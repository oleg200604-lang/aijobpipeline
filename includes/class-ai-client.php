<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI API client.
 *
 * Responsible only for:
 *
 * - provider configuration;
 * - budget preflight checks;
 * - HTTP communication with OpenAI;
 * - retrying transient failures;
 * - normalizing provider response;
 * - calculating and recording AI usage.
 *
 * This class does not:
 *
 * - build business prompts;
 * - validate AI analysis schema;
 * - change job statuses;
 * - save job analyses;
 * - process cron batches.
 */
class AIJP_AI_Client
{
    /**
     * OpenAI Responses API endpoint.
     */
    private const API_URL =
        'https://api.openai.com/v1/responses';

    /**
     * Provider name used in stored usage records.
     */
    private const PROVIDER =
        'openai';

    /**
     * Default request timeout.
     */
    private const DEFAULT_TIMEOUT =
        90;

    /**
     * Maximum number of attempts.
     *
     * Total attempts = 1 initial request + retries.
     */
    private const MAX_ATTEMPTS =
        3;

    /**
     * Initial retry delay in seconds.
     */
    private const INITIAL_RETRY_DELAY =
        1;

    /**
     * Maximum raw response size retained in memory.
     *
     * This is a safety guard against accidentally storing an
     * unexpectedly huge provider response.
     */
    private const MAX_RAW_RESPONSE_LENGTH =
        2_000_000;

    /**
     * Pricing per 1M tokens.
     *
     * cached_input is the price for cached input tokens.
     *
     * @var array<string,array<string,float>>
     */
    private const MODEL_PRICING = [
        'gpt-5' => [
            'input' =>
                1.25,

            'output' =>
                10.00,

            'cached_input' =>
                0.125,
        ],

        'gpt-5-mini' => [
            'input' =>
                0.25,

            'output' =>
                2.00,

            'cached_input' =>
                0.025,
        ],

        'gpt-5-nano' => [
            'input' =>
                0.05,

            'output' =>
                0.40,

            'cached_input' =>
                0.005,
        ],

        'gpt-5-chat-latest' => [
            'input' =>
                1.25,

            'output' =>
                10.00,

            'cached_input' =>
                0.125,
        ],

        'gpt-5.6-luna' => [
            'input' =>
                0.20,

            'output' =>
                1.20,

            'cached_input' =>
                0.02,
        ],

        'gpt-5.6-terra' => [
            'input' =>
                2.00,

            'output' =>
                12.00,

            'cached_input' =>
                0.20,
        ],

        'gpt-5.6-sol' => [
            'input' =>
                4.00,

            'output' =>
                20.00,

            'cached_input' =>
                0.40,
        ],

        'gpt-6-astra' => [
            'input' =>
                10.00,

            'output' =>
                50.00,

            'cached_input' =>
                1.00,
        ],
    ];

    /**
     * Request an AI completion.
     *
     * The method returns a stable internal response contract:
     *
     * [
     *     'content'      => string,
     *     'provider'     => string,
     *     'model'        => string,
     *     'usage'        => array,
     *     'raw_response' => string,
     * ]
     *
     * @param string               $prompt Prompt.
     * @param array<string,mixed>  $options Request options.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function request(
        string $prompt,
        array $options = []
    ): array|WP_Error {
        $prompt =
            trim($prompt);

        if ($prompt === '') {
            return new WP_Error(
                'aijp_ai_empty_prompt',
                __(
                    'AI prompt cannot be empty.',
                    'ai-job-pipeline'
                )
            );
        }

        $configuration =
            $this->get_configuration(
                $options
            );

        if (
            is_wp_error(
                $configuration
            )
        ) {
            return $configuration;
        }

        $model =
            $configuration['model'];

        /*
         * Important: model pricing is validated here as well as during
         * configuration creation. This prevents an unknown model from
         * silently generating untracked cost.
         */
        $pricing_validation =
            $this->validate_model_pricing(
                $model
            );

        if (
            is_wp_error(
                $pricing_validation
            )
        ) {
            return $pricing_validation;
        }

        $budget =
            $this->check_budget();

        if (
            is_wp_error(
                $budget
            )
        ) {
            return $budget;
        }

        $body =
            $this->build_request_body(
                $prompt,
                $configuration
            );

        $response =
            $this->perform_request(
                $body,
                $configuration
            );

        if (
            is_wp_error(
                $response
            )
        ) {
            return $response;
        }

        $normalized =
            $this->normalize_response(
                $response,
                $model
            );

        if (
            is_wp_error(
                $normalized
            )
        ) {
            return $normalized;
        }

        /*
         * Usage is recorded only after a successful provider response.
         *
         * A database failure must not turn a successful paid API request
         * into a fake provider failure.
         */
        $usage_result =
            $this->record_usage(
                $normalized['usage'],
                $model,
                [
                    'job_id' =>
                        absint($options['job_id'] ?? 0),

                    'operation' =>
                        sanitize_key(
                            (string) (
                                $options['operation']
                                ?? 'job_analysis'
                            )
                        ),

                    'request_id' =>
                        $normalized['request_id']
                        ?? '',
                ]
            );

        if (
            is_wp_error(
                $usage_result
            )
        ) {
            do_action(
                'aijp_ai_usage_record_failed',
                $usage_result,
                $normalized['usage'],
                $model
            );
        }

        return $normalized;
    }

    /**
     * Compatibility alias used by AI services.
     *
     * @param string              $prompt  Prompt.
     * @param array<string,mixed> $options Options.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function analyze(
        string $prompt,
        array $options = []
    ): array|WP_Error {
        return $this->request(
            $prompt,
            $options
        );
    }

    /**
     * Get provider configuration.
     *
     * @param array<string,mixed> $options Runtime options.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function get_configuration(
        array $options
    ): array|WP_Error {
        $provider =
            AIJP_Settings::get(
                'ai_provider',
                'openai'
            );

        $provider =
            sanitize_key(
                (string) $provider
            );

        if ($provider !== self::PROVIDER) {
            return new WP_Error(
                'aijp_ai_unsupported_provider',
                sprintf(
                    __(
                        'Unsupported AI provider: %s.',
                        'ai-job-pipeline'
                    ),
                    $provider
                )
            );
        }

        $api_key =
            AIJP_Settings::get(
                'ai_api_key',
                ''
            );

        $api_key =
            trim(
                (string) $api_key
            );

        if ($api_key === '') {
            return new WP_Error(
                'aijp_ai_missing_api_key',
                __(
                    'OpenAI API key is not configured.',
                    'ai-job-pipeline'
                )
            );
        }

        $model =
            $options['model']
            ?? AIJP_Settings::get(
                'ai_model',
                'gpt-5.6-luna'
            );

        $model =
            trim(
                (string) $model
            );

        if ($model === '') {
            return new WP_Error(
                'aijp_ai_missing_model',
                __(
                    'AI model is not configured.',
                    'ai-job-pipeline'
                )
            );
        }

        $pricing_validation =
            $this->validate_model_pricing(
                $model
            );

        if (
            is_wp_error(
                $pricing_validation
            )
        ) {
            return $pricing_validation;
        }

        $timeout =
            isset($options['timeout'])
                ? absint(
                    $options['timeout']
                )
                : absint(
                    AIJP_Settings::get(
                        'ai_timeout',
                        self::DEFAULT_TIMEOUT
                    )
                );

        if ($timeout < 10) {
            $timeout = 10;
        }

        if ($timeout > 300) {
            $timeout = 300;
        }

        $max_output_tokens =
            isset(
                $options['max_output_tokens']
            )
                ? absint(
                    $options['max_output_tokens']
                )
                : 2000;

        if ($max_output_tokens < 100) {
            $max_output_tokens = 100;
        }

        if ($max_output_tokens > 16000) {
            $max_output_tokens = 16000;
        }

        $temperature = isset($options['temperature'])
            ? (float) $options['temperature']
            : (float) AIJP_Settings::get(
                'ai_temperature',
                0.2
            );

        $temperature = max(
            0.0,
            min(2.0, $temperature)
        );

        return [
            'provider' =>
                self::PROVIDER,

            'api_key' =>
                $api_key,

            'model' =>
                $model,

            'timeout' =>
                $timeout,

            'max_output_tokens' =>
                $max_output_tokens,

            'temperature' =>
                $temperature,
        ];
    }

    /**
     * Validate that pricing for the model is known.
     *
     * Unknown models are rejected because otherwise AI spending
     * cannot be calculated reliably.
     *
     * @param string $model Model ID.
     *
     * @return true|WP_Error
     */
    private function validate_model_pricing(
        string $model
    ): true|WP_Error {
        if (
            !isset(
                self::MODEL_PRICING[$model]
            )
        ) {
            return new WP_Error(
                'aijp_ai_unknown_model_pricing',
                sprintf(
                    __(
                        'No pricing configuration exists for AI model "%s".',
                        'ai-job-pipeline'
                    ),
                    $model
                ),
                [
                    'model' =>
                        $model,

                    'supported_models' =>
                        array_keys(
                            self::MODEL_PRICING
                        ),
                ]
            );
        }

        $pricing =
            self::MODEL_PRICING[$model];

        foreach (
            [
                'input',
                'output',
                'cached_input',
            ] as $price_key
        ) {
            if (
                !isset(
                    $pricing[$price_key]
                ) ||
                !is_numeric(
                    $pricing[$price_key]
                ) ||
                (float) $pricing[$price_key] < 0
            ) {
                return new WP_Error(
                    'aijp_ai_invalid_model_pricing',
                    sprintf(
                        __(
                            'Pricing configuration for model "%1$s" is invalid: %2$s.',
                            'ai-job-pipeline'
                        ),
                        $model,
                        $price_key
                    ),
                    [
                        'model' =>
                            $model,

                        'price_key' =>
                            $price_key,
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Build Responses API request body.
     *
     * @param string              $prompt        Prompt.
     * @param array<string,mixed> $configuration Configuration.
     *
     * @return array<string,mixed>
     */
    private function build_request_body(
        string $prompt,
        array $configuration
    ): array {
        $schema =
            AIJP_AI_Prompts::analysis_schema();

        return [
            'model' =>
                $configuration['model'],

            'input' =>
                $prompt,

            'max_output_tokens' =>
                $configuration['max_output_tokens'],

            'temperature' =>
                $configuration['temperature'],

            /*
             * Job descriptions can contain client information. Avoid
             * retaining these one-shot responses for later retrieval.
             */
            'store' => false,

            'text' => [
                'format' => [
                    'type' =>
                        'json_schema',

                    'name' =>
                        $schema['name'],

                    'strict' =>
                        true,

                    'schema' =>
                        $schema['schema'],
                ],
            ],
        ];
    }

    /**
     * Perform HTTP request with retry handling.
     *
     * @param array<string,mixed> $body          Request body.
     * @param array<string,mixed> $configuration Provider configuration.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function perform_request(
        array $body,
        array $configuration
    ): array|WP_Error {
        if (
            !function_exists(
                'wp_remote_post'
            )
        ) {
            return new WP_Error(
                'aijp_ai_http_unavailable',
                __(
                    'WordPress HTTP API is unavailable.',
                    'ai-job-pipeline'
                )
            );
        }

        $encoded_body =
            wp_json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if (
            $encoded_body === false
        ) {
            return new WP_Error(
                'aijp_ai_request_encoding_failed',
                __(
                    'Failed to encode AI request.',
                    'ai-job-pipeline'
                )
            );
        }

        $last_error = null;

        for (
            $attempt = 1;
            $attempt <= self::MAX_ATTEMPTS;
            $attempt++
        ) {
            $response =
                wp_remote_post(
                    self::API_URL,
                    [
                        'timeout' =>
                            $configuration['timeout'],

                        'redirection' =>
                            2,

                        'blocking' =>
                            true,

                        'headers' => [
                            'Authorization' =>
                                'Bearer ' .
                                $configuration['api_key'],

                            'Content-Type' =>
                                'application/json',

                            'Accept' =>
                                'application/json',
                        ],

                        'body' =>
                            $encoded_body,
                    ]
                );

            if (
                is_wp_error(
                    $response
                )
            ) {
                $last_error =
                    $response;

                if (
                    $attempt <
                    self::MAX_ATTEMPTS
                ) {
                    $this->sleep_before_retry(
                        $attempt
                    );

                    continue;
                }

                return new WP_Error(
                    'aijp_ai_http_error',
                    sprintf(
                        __(
                            'OpenAI HTTP request failed: %s',
                            'ai-job-pipeline'
                        ),
                        $response->get_error_message()
                    ),
                    [
                        'attempts' =>
                            $attempt,
                    ]
                );
            }

            $status_code =
                wp_remote_retrieve_response_code(
                    $response
                );

            $raw_body =
                wp_remote_retrieve_body(
                    $response
                );

            if (
                $status_code >= 200 &&
                $status_code < 300
            ) {
                return [
                    'status_code' =>
                        $status_code,

                    'body' =>
                        $raw_body,

                    'headers' =>
                        wp_remote_retrieve_headers(
                            $response
                        ),
                ];
            }

            $error =
                $this->parse_provider_error(
                    $raw_body
                );

            /*
             * Retry only transient failures.
             */
            if (
                $this->is_retryable_status(
                    $status_code
                ) &&
                $attempt <
                self::MAX_ATTEMPTS
            ) {
                $last_error =
                    new WP_Error(
                        'aijp_ai_provider_retryable_error',
                        $error['message'],
                        [
                            'status_code' =>
                                $status_code,

                            'provider_code' =>
                                $error['code'],
                        ]
                    );

                $this->sleep_before_retry(
                    $attempt
                );

                continue;
            }

            return new WP_Error(
                'aijp_ai_provider_error',
                $error['message'],
                [
                    'status_code' =>
                        $status_code,

                    'provider_code' =>
                        $error['code'],

                    'attempts' =>
                        $attempt,
                ]
            );
        }

        if (
            is_wp_error(
                $last_error
            )
        ) {
            return $last_error;
        }

        return new WP_Error(
            'aijp_ai_request_failed',
            __(
                'OpenAI request failed after all attempts.',
                'ai-job-pipeline'
            )
        );
    }

    /**
     * Normalize OpenAI Responses API response.
     *
     * @param array<string,mixed> $response Provider HTTP response.
     * @param string               $model    Requested model.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_response(
        array $response,
        string $model
    ): array|WP_Error {
        $raw_body =
            isset($response['body'])
                ? (string) $response['body']
                : '';

        if ($raw_body === '') {
            return new WP_Error(
                'aijp_ai_empty_provider_response',
                __(
                    'OpenAI returned an empty response body.',
                    'ai-job-pipeline'
                )
            );
        }

        if (
            strlen($raw_body) >
            self::MAX_RAW_RESPONSE_LENGTH
        ) {
            return new WP_Error(
                'aijp_ai_response_too_large',
                __(
                    'OpenAI response is too large to process safely.',
                    'ai-job-pipeline'
                )
            );
        }

        $decoded =
            json_decode(
                $raw_body,
                true
            );

        if (
            json_last_error() !==
            JSON_ERROR_NONE ||
            !is_array($decoded)
        ) {
            return new WP_Error(
                'aijp_ai_invalid_provider_json',
                sprintf(
                    __(
                        'OpenAI returned invalid JSON: %s',
                        'ai-job-pipeline'
                    ),
                    json_last_error_msg()
                )
            );
        }

        $content =
            $this->extract_output_text(
                $decoded
            );

        if ($content === '') {
            /*
             * Responses API may return a refusal instead of normal text.
             */
            $refusal =
                $this->extract_refusal(
                    $decoded
                );

            if ($refusal !== '') {
                return new WP_Error(
                    'aijp_ai_refusal',
                    $refusal
                );
            }

            return new WP_Error(
                'aijp_ai_empty_model_output',
                __(
                    'OpenAI response did not contain output text.',
                    'ai-job-pipeline'
                )
            );
        }

        $usage =
            $this->normalize_usage(
                $decoded,
                $model
            );

        return [
            'content' =>
                $content,

            'provider' =>
                self::PROVIDER,

            'model' =>
                $model,

            'request_id' =>
                isset($decoded['id'])
                    ? sanitize_text_field(
                        (string) $decoded['id']
                    )
                    : '',

            'usage' =>
                $usage,

            'raw_response' =>
                $raw_body,
        ];
    }

    /**
     * Extract output text from a Responses API response.
     *
     * @param array<string,mixed> $response Response.
     *
     * @return string
     */
    private function extract_output_text(
        array $response
    ): string {
        if (
            isset(
                $response['output_text']
            ) &&
            is_string(
                $response['output_text']
            )
        ) {
            return trim(
                $response['output_text']
            );
        }

        $output =
            isset($response['output']) &&
            is_array($response['output'])
                ? $response['output']
                : [];

        foreach ($output as $item) {
            if (
                !is_array($item)
            ) {
                continue;
            }

            $content =
                isset($item['content']) &&
                is_array($item['content'])
                    ? $item['content']
                    : [];

            foreach ($content as $content_item) {
                if (
                    !is_array(
                        $content_item
                    )
                ) {
                    continue;
                }

                if (
                    isset(
                        $content_item['text']
                    ) &&
                    is_string(
                        $content_item['text']
                    )
                ) {
                    $text =
                        trim(
                            $content_item['text']
                        );

                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Extract provider refusal text if present.
     *
     * @param array<string,mixed> $response Response.
     *
     * @return string
     */
    private function extract_refusal(
        array $response
    ): string {
        $output =
            isset($response['output']) &&
            is_array($response['output'])
                ? $response['output']
                : [];

        foreach ($output as $item) {
            if (
                !is_array($item)
            ) {
                continue;
            }

            $content =
                isset($item['content']) &&
                is_array($item['content'])
                    ? $item['content']
                    : [];

            foreach ($content as $content_item) {
                if (
                    !is_array(
                        $content_item
                    )
                ) {
                    continue;
                }

                if (
                    isset(
                        $content_item['refusal']
                    ) &&
                    is_string(
                        $content_item['refusal']
                    )
                ) {
                    return trim(
                        $content_item['refusal']
                    );
                }
            }
        }

        return '';
    }

    /**
     * Normalize usage information.
     *
     * @param array<string,mixed> $response Response.
     * @param string               $model    Model ID.
     *
     * @return array<string,mixed>
     */
    private function normalize_usage(
        array $response,
        string $model
    ): array {
        $usage =
            isset($response['usage']) &&
            is_array($response['usage'])
                ? $response['usage']
                : [];

        $input_tokens =
            absint(
                $usage['input_tokens']
                ?? $usage['prompt_tokens']
                ?? 0
            );

        $output_tokens =
            absint(
                $usage['output_tokens']
                ?? $usage['completion_tokens']
                ?? 0
            );

        $total_tokens =
            absint(
                $usage['total_tokens']
                ?? (
                    $input_tokens +
                    $output_tokens
                )
            );

        $cached_input_tokens =
            $this->extract_cached_input_tokens(
                $usage
            );

        /*
         * Keep compatibility aliases because older code used
         * prompt_tokens/completion_tokens.
         */
        $prompt_tokens =
            $input_tokens;

        $completion_tokens =
            $output_tokens;

        $cost =
            $this->calculate_cost(
                $model,
                $input_tokens,
                $output_tokens,
                $cached_input_tokens
            );

        return [
            'prompt_tokens' =>
                $prompt_tokens,

            'input_tokens' =>
                $input_tokens,

            'cached_input_tokens' =>
                $cached_input_tokens,

            'completion_tokens' =>
                $completion_tokens,

            'output_tokens' =>
                $output_tokens,

            'total_tokens' =>
                $total_tokens,

            'cost_usd' =>
                $cost,
        ];
    }

    /**
     * Extract cached input token count from provider usage.
     *
     * @param array<string,mixed> $usage Usage.
     *
     * @return int
     */
    private function extract_cached_input_tokens(
        array $usage
    ): int {
        if (
            isset(
                $usage['input_tokens_details']
            ) &&
            is_array(
                $usage['input_tokens_details']
            )
        ) {
            return absint(
                $usage[
                    'input_tokens_details'
                ]['cached_tokens']
                ?? 0
            );
        }

        if (
            isset(
                $usage['prompt_tokens_details']
            ) &&
            is_array(
                $usage['prompt_tokens_details']
            )
        ) {
            return absint(
                $usage[
                    'prompt_tokens_details'
                ]['cached_tokens']
                ?? 0
            );
        }

        return absint(
            $usage['cached_input_tokens']
            ?? 0
        );
    }

    /**
     * Calculate request cost.
     *
     * Cached input tokens are charged at the cached-input rate.
     * Non-cached input tokens use the regular input rate.
     *
     * @param string $model               Model ID.
     * @param int    $input_tokens        Total input tokens.
     * @param int    $output_tokens       Output tokens.
     * @param int    $cached_input_tokens Cached input tokens.
     *
     * @return float
     */
    private function calculate_cost(
        string $model,
        int $input_tokens,
        int $output_tokens,
        int $cached_input_tokens
    ): float {
        if (
            !isset(
                self::MODEL_PRICING[$model]
            )
        ) {
            return 0.0;
        }

        $pricing =
            self::MODEL_PRICING[$model];

        $cached_input_tokens =
            min(
                $cached_input_tokens,
                $input_tokens
            );

        $regular_input_tokens =
            max(
                0,
                $input_tokens -
                $cached_input_tokens
            );

        $input_cost =
            (
                $regular_input_tokens *
                $pricing['input']
            ) / 1_000_000;

        $cached_cost =
            (
                $cached_input_tokens *
                $pricing['cached_input']
            ) / 1_000_000;

        $output_cost =
            (
                $output_tokens *
                $pricing['output']
            ) / 1_000_000;

        return round(
            $input_cost +
            $cached_cost +
            $output_cost,
            8
        );
    }

    /**
     * Check daily/monthly AI spending limits.
     *
     * @return true|WP_Error
     */
    private function check_budget(): true|WP_Error
    {
        $enabled =
            AIJP_Settings::get(
                'ai_budget_enabled',
                1
            );

        if (
            !filter_var(
                $enabled,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            )
        ) {
            return true;
        }

        global $wpdb;

        $table =
            AIJP_Database::table(
                'ai_usage'
            );

        $daily_limit =
            $this->positive_float(
                AIJP_Settings::get(
                    'ai_daily_budget_usd',
                    20
                )
            );

        $monthly_limit =
            $this->positive_float(
                AIJP_Settings::get(
                    'ai_monthly_budget_usd',
                    20
                )
            );

        $daily_spend =
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(estimated_cost), 0)
                    FROM {$table}
                    WHERE created_at >= %s",
                    wp_date(
                        'Y-m-d 00:00:00'
                    )
                )
            );

        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'aijp_ai_budget_query_failed',
                __('Unable to verify the daily AI budget.', 'ai-job-pipeline'),
                [
                    'database_error' =>
                        (string) $wpdb->last_error,
                ]
            );
        }

        $month_start =
            wp_date(
                'Y-m-01 00:00:00'
            );

        $monthly_spend =
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(estimated_cost), 0)
                    FROM {$table}
                    WHERE created_at >= %s",
                    $month_start
                )
            );

        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'aijp_ai_budget_query_failed',
                __('Unable to verify the monthly AI budget.', 'ai-job-pipeline'),
                [
                    'database_error' =>
                        (string) $wpdb->last_error,
                ]
            );
        }

        $daily_spend =
            (float) $daily_spend;

        $monthly_spend =
            (float) $monthly_spend;

        if (
            $daily_limit > 0 &&
            $daily_spend >= $daily_limit
        ) {
            return new WP_Error(
                'aijp_ai_daily_budget_exceeded',
                sprintf(
                    __(
                        'Daily AI budget exceeded: %.4f / %.4f USD.',
                        'ai-job-pipeline'
                    ),
                    $daily_spend,
                    $daily_limit
                ),
                [
                    'period' =>
                        'daily',

                    'spent' =>
                        $daily_spend,

                    'limit' =>
                        $daily_limit,
                ]
            );
        }

        if (
            $monthly_limit > 0 &&
            $monthly_spend >= $monthly_limit
        ) {
            return new WP_Error(
                'aijp_ai_monthly_budget_exceeded',
                sprintf(
                    __(
                        'Monthly AI budget exceeded: %.4f / %.4f USD.',
                        'ai-job-pipeline'
                    ),
                    $monthly_spend,
                    $monthly_limit
                ),
                [
                    'period' =>
                        'monthly',

                    'spent' =>
                        $monthly_spend,

                    'limit' =>
                        $monthly_limit,
                ]
            );
        }

        return true;
    }

    /**
     * Record provider usage in the AI usage table.
     *
     * @param array<string,mixed> $usage Usage.
     * @param string               $model Model ID.
     * @param array<string,mixed> $context Request context.
     *
     * @return true|WP_Error
     */
    private function record_usage(
        array $usage,
        string $model,
        array $context = []
    ): true|WP_Error {
        global $wpdb;

        $table =
            AIJP_Database::table(
                'ai_usage'
            );

        $provider =
            self::PROVIDER;

        $prompt_tokens =
            absint(
                $usage['prompt_tokens']
                ?? 0
            );

        $completion_tokens =
            absint(
                $usage['completion_tokens']
                ?? 0
            );

        $total_tokens =
            absint(
                $usage['total_tokens']
                ?? (
                    $prompt_tokens +
                    $completion_tokens
                )
            );

        $cost_usd =
            round(
                (float) (
                    $usage['cost_usd']
                    ?? 0
                ),
                8
            );

        $job_id = absint(
            $context['job_id']
            ?? 0
        );

        $operation = sanitize_key(
            (string) (
                $context['operation']
                ?? 'job_analysis'
            )
        );

        if ($operation === '') {
            $operation = 'job_analysis';
        }

        $request_id = sanitize_text_field(
            (string) (
                $context['request_id']
                ?? ''
            )
        );

        $inserted =
            $wpdb->insert(
                $table,
                [
                    'job_id' =>
                        $job_id > 0
                            ? $job_id
                            : null,

                    'operation' =>
                        $operation,

                    'provider' =>
                        $provider,

                    'model' =>
                        $model,

                    'request_id' =>
                        $request_id,

                    'prompt_tokens' =>
                        $prompt_tokens,

                    'completion_tokens' =>
                        $completion_tokens,

                    'total_tokens' =>
                        $total_tokens,

                    'estimated_cost' =>
                        $cost_usd,

                    'created_at' =>
                        current_time(
                            'mysql'
                        ),
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%d',
                    '%f',
                    '%s',
                ]
            );

        if ($inserted === false) {
            return new WP_Error(
                'aijp_ai_usage_insert_failed',
                sprintf(
                    __(
                        'Failed to record AI usage: %s',
                        'ai-job-pipeline'
                    ),
                    (string) $wpdb->last_error
                ),
                [
                    'model' =>
                        $model,

                    'cost_usd' =>
                        $cost_usd,
                ]
            );
        }

        return true;
    }

    /**
     * Parse an OpenAI provider error.
     *
     * @param string $body Raw response body.
     *
     * @return array{message:string,code:string}
     */
    private function parse_provider_error(
        string $body
    ): array {
        $decoded =
            json_decode(
                $body,
                true
            );

        if (
            is_array($decoded)
        ) {
            $error =
                isset($decoded['error']) &&
                is_array($decoded['error'])
                    ? $decoded['error']
                    : [];

            $message =
                isset($error['message']) &&
                is_scalar($error['message'])
                    ? trim(
                        (string) $error['message']
                    )
                    : '';

            $code =
                isset($error['code']) &&
                is_scalar($error['code'])
                    ? trim(
                        (string) $error['code']
                    )
                    : '';

            if ($message !== '') {
                return [
                    'message' =>
                        $message,

                    'code' =>
                        $code,
                ];
            }
        }

        $body =
            trim($body);

        if ($body === '') {
            $body =
                __(
                    'OpenAI returned an empty error response.',
                    'ai-job-pipeline'
                );
        }

        return [
            'message' =>
                $body,

            'code' =>
                '',
        ];
    }

    /**
     * Determine whether an HTTP status is transient.
     *
     * @param int $status_code HTTP status.
     *
     * @return bool
     */
    private function is_retryable_status(
        int $status_code
    ): bool {
        return in_array(
            $status_code,
            [
                408,
                409,
                429,
                500,
                502,
                503,
                504,
            ],
            true
        );
    }

    /**
     * Sleep before a retry.
     *
     * @param int $attempt Completed attempt number.
     *
     * @return void
     */
    private function sleep_before_retry(
        int $attempt
    ): void {
        $delay =
            self::INITIAL_RETRY_DELAY *
            (2 ** max(
                0,
                $attempt - 1
            ));

        /*
         * Keep retry delays bounded.
         */
        $delay =
            min(
                $delay,
                8
            );

        if (
            $delay > 0
        ) {
            sleep(
                $delay
            );
        }
    }

    /**
     * Normalize positive configuration value.
     *
     * @param mixed $value Value.
     *
     * @return float
     */
    private function positive_float(
        mixed $value
    ): float {
        if (
            !is_numeric($value)
        ) {
            return 0.0;
        }

        return max(
            0.0,
            (float) $value
        );
    }
}
