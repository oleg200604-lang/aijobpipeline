<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main AI service.
 *
 * Coordinates:
 *
 * - AI prompt generation;
 * - AI provider requests;
 * - extraction of JSON from the AI response;
 * - validation and normalization of AI analysis;
 * - preparation of the final result for the AI Agent.
 *
 * This class does not:
 *
 * - load jobs from the database;
 * - update job statuses;
 * - save analysis results;
 * - manage cron processing.
 */
class AIJP_AI
{
    /**
     * Current context version.
     */
    private const CONTEXT_VERSION = '1.1';

    /**
     * Supported AI operation.
     */
    private const OPERATION_JOB_ANALYSIS = 'job_analysis';

    /**
     * AI client.
     *
     * @var AIJP_AI_Client
     */
    private AIJP_AI_Client $client;

    /**
     * AI validator.
     *
     * @var AIJP_AI_Validator
     */
    private AIJP_AI_Validator $validator;

    /**
     * Constructor.
     *
     * @param AIJP_AI_Client|null    $client    AI client.
     * @param AIJP_AI_Validator|null $validator AI validator.
     */
    public function __construct(
        ?AIJP_AI_Client $client = null,
        ?AIJP_AI_Validator $validator = null
    ) {
        $this->client =
            $client ?? new AIJP_AI_Client();

        $this->validator =
            $validator ?? new AIJP_AI_Validator();
    }

    /**
     * Analyze an AI Agent context.
     *
     * Expected context:
     *
     * [
     *     'job' => [...],
     *     'metadata' => [...],
     *     'agent' => [...],
     * ]
     *
     * @param array<string,mixed> $context AI Agent context.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function analyze_context(
        array $context
    ): array|WP_Error {
        $validation =
            $this->validate_context(
                $context
            );

        if (is_wp_error($validation)) {
            return $validation;
        }

        /*
         * Prompt generation is isolated in AIJP_AI_Prompts.
         */
        $prompt =
            AIJP_AI_Prompts::job_analysis_context(
                $context
            );

        if (
            !is_string($prompt) ||
            trim($prompt) === ''
        ) {
            return new WP_Error(
                'aijp_ai_empty_prompt',
                __(
                    'AI analysis prompt is empty.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Provider communication is isolated in AIJP_AI_Client.
         *
         * Keep the operation explicit so the client/prompt layer can
         * distinguish this request from future AI operations.
         */
        $response =
            $this->client->request(
                $prompt,
                [
                    'operation' =>
                        self::OPERATION_JOB_ANALYSIS,

                    'job_id' =>
                        absint(
                            $context['job_id']
                            ?? $context['job']['id']
                            ?? 0
                        ),
                ]
            );

        if (is_wp_error($response)) {
            return $response;
        }

        if (!is_array($response)) {
            return new WP_Error(
                'aijp_ai_invalid_client_response',
                __(
                    'AI client returned an invalid response.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * The client contract provides normalized textual content.
         */
        $content =
            $this->extract_response_content(
                $response
            );

        if ($content === '') {
            return new WP_Error(
                'aijp_ai_empty_response',
                __(
                    'AI returned an empty analysis response.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Convert provider text into a PHP array.
         */
        $analysis =
            $this->extract_analysis(
                $content
            );

        if (is_wp_error($analysis)) {
            return $analysis;
        }

        if ($analysis === []) {
            return new WP_Error(
                'aijp_ai_empty_analysis',
                __(
                    'AI returned an empty analysis object.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * The Validator is the authoritative place for the business
         * contract:
         *
         * - category;
         * - complexity;
         * - feasibility;
         * - estimated hours;
         * - plan;
         * - red flags;
         * - recommendation;
         * - confidence.
         */
        $normalized_analysis =
            $this->validator->normalize_analysis(
                $analysis
            );

        if (is_wp_error($normalized_analysis)) {
            return $normalized_analysis;
        }

        if (
            !is_array($normalized_analysis) ||
            $normalized_analysis === []
        ) {
            return new WP_Error(
                'aijp_ai_invalid_normalized_analysis',
                __(
                    'AI validator returned an invalid normalized analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Normalize provider metadata instead of passing arbitrary
         * provider response fields further into the pipeline.
         */
        $provider =
            $this->string_value(
                $response,
                'provider'
            );

        $model =
            $this->string_value(
                $response,
                'model'
            );

        /*
         * Preserve complete accounting information.
         *
         * In particular:
         *
         * - input_tokens;
         * - cached_input_tokens;
         * - output_tokens;
         * - total_tokens;
         * - cost_usd.
         */
        $usage =
            $this->usage_value(
                $response
            );

        /*
         * Prompt metadata is generated from the canonical prompt layer.
         */
        $prompt_meta =
            $this->get_prompt_meta();

        /*
         * Context metadata contains only safe, non-job-sensitive
         * orchestration information.
         */
        $context_meta =
            $this->context_meta(
                $context
            );

        /*
         * raw_response must remain available for audit/debugging.
         *
         * The Client is responsible for deciding what constitutes
         * the canonical raw provider response.
         */
        $raw_response =
            $this->string_value(
                $response,
                'raw_response'
            );

        return [
            'analysis' =>
                $normalized_analysis,

            'provider' =>
                $provider,

            'model' =>
                $model,

            'usage' =>
                $usage,

            'prompt_meta' =>
                $prompt_meta,

            'context_meta' =>
                $context_meta,

            'raw_response' =>
                $raw_response,
        ];
    }

    /**
     * Analyze a single job.
     *
     * Compatibility method for callers that still pass raw job data.
     *
     * @param array<string,mixed> $job Job data.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function analyze_job(
        array $job
    ): array|WP_Error {
        if ($job === []) {
            return new WP_Error(
                'aijp_ai_empty_job',
                __(
                    'Cannot analyze an empty job.',
                    'ai-job-pipeline'
                )
            );
        }

        return $this->analyze_context(
            [
                'job' =>
                    $job,

                'metadata' => [
                    'context_version' =>
                        self::CONTEXT_VERSION,
                ],

                'agent' => [
                    'operation' =>
                        self::OPERATION_JOB_ANALYSIS,
                ],
            ]
        );
    }

    /**
     * Validate the minimum required AI context.
     *
     * @param array<string,mixed> $context AI context.
     *
     * @return true|WP_Error
     */
    private function validate_context(
        array $context
    ): true|WP_Error {
        if (
            !isset($context['job']) ||
            !is_array($context['job'])
        ) {
            return new WP_Error(
                'aijp_ai_invalid_context',
                __(
                    'AI context must contain a job array.',
                    'ai-job-pipeline'
                )
            );
        }

        $job =
            $context['job'];

        $title =
            $this->string_value(
                $job,
                'title'
            );

        $description =
            $this->string_value(
                $job,
                'description'
            );

        /*
         * A job without meaningful textual content cannot be analyzed
         * reliably.
         */
        if (
            $title === '' &&
            $description === ''
        ) {
            return new WP_Error(
                'aijp_ai_empty_job_context',
                __(
                    'Job context does not contain a title or description.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Metadata, when present, must be an array.
         */
        if (
            isset($context['metadata']) &&
            !is_array($context['metadata'])
        ) {
            return new WP_Error(
                'aijp_ai_invalid_context_metadata',
                __(
                    'AI context metadata must be an array.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Agent descriptor, when present, must be an array.
         */
        if (
            isset($context['agent']) &&
            !is_array($context['agent'])
        ) {
            return new WP_Error(
                'aijp_ai_invalid_agent_descriptor',
                __(
                    'AI context agent descriptor must be an array.',
                    'ai-job-pipeline'
                )
            );
        }

        $metadata =
            isset($context['metadata']) &&
            is_array($context['metadata'])
                ? $context['metadata']
                : [];

        $agent =
            isset($context['agent']) &&
            is_array($context['agent'])
                ? $context['agent']
                : [];

        /*
         * If an operation is explicitly supplied, validate it.
         */
        $operation =
            $this->string_value(
                $agent,
                'operation'
            );

        if (
            $operation !== '' &&
            $operation !== self::OPERATION_JOB_ANALYSIS
        ) {
            return new WP_Error(
                'aijp_ai_unsupported_operation',
                sprintf(
                    __(
                        'Unsupported AI operation "%s".',
                        'ai-job-pipeline'
                    ),
                    $operation
                )
            );
        }

        /*
         * We allow older context versions for compatibility.
         *
         * A future/newer version is rejected instead of silently
         * interpreting an unknown structure.
         */
        $context_version =
            $this->string_value(
                $metadata,
                'context_version'
            );

        if (
            $context_version !== '' &&
            version_compare(
                $context_version,
                self::CONTEXT_VERSION,
                '>'
            )
        ) {
            return new WP_Error(
                'aijp_ai_context_version_unsupported',
                sprintf(
                    __(
                        'AI context version "%s" is newer than supported version "%s".',
                        'ai-job-pipeline'
                    ),
                    $context_version,
                    self::CONTEXT_VERSION
                )
            );
        }

        return true;
    }

    /**
     * Extract AI response content from the client contract.
     *
     * Expected:
     *
     * [
     *     'content' => string,
     *     'provider' => string,
     *     'model' => string,
     *     'usage' => array,
     *     'raw_response' => string,
     * ]
     *
     * @param array<string,mixed> $response Client response.
     *
     * @return string
     */
    private function extract_response_content(
        array $response
    ): string {
        return $this->string_value(
            $response,
            'content'
        );
    }

    /**
     * Extract JSON analysis from AI response.
     *
     * Supports:
     *
     * - pure JSON;
     * - JSON wrapped in Markdown code fences;
     * - explanatory text around one JSON object.
     *
     * @param string $content AI response content.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function extract_analysis(
        string $content
    ): array|WP_Error {
        $content =
            trim($content);

        if ($content === '') {
            return new WP_Error(
                'aijp_ai_empty_analysis_content',
                __(
                    'AI analysis content is empty.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Remove Markdown fences when present.
         */
        $content =
            $this->remove_code_fences(
                $content
            );

        /*
         * First attempt: the complete response is JSON.
         */
        $decoded =
            json_decode(
                $content,
                true
            );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            return $decoded;
        }

        /*
         * Second attempt: the response contains a single JSON object
         * surrounded by explanatory text.
         */
        $json =
            $this->extract_json_object(
                $content
            );

        if ($json === '') {
            return new WP_Error(
                'aijp_ai_json_not_found',
                __(
                    'No valid JSON object was found in the AI response.',
                    'ai-job-pipeline'
                )
            );
        }

        $decoded =
            json_decode(
                $json,
                true
            );

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($decoded)
        ) {
            return new WP_Error(
                'aijp_ai_invalid_analysis_json',
                sprintf(
                    __(
                        'AI returned invalid analysis JSON: %s',
                        'ai-job-pipeline'
                    ),
                    json_last_error_msg()
                )
            );
        }

        return $decoded;
    }

    /**
     * Remove Markdown code fences.
     *
     * @param string $content Content.
     *
     * @return string
     */
    private function remove_code_fences(
        string $content
    ): string {
        $content =
            trim($content);

        if (
            substr(
                $content,
                0,
                3
            ) !== '```'
        ) {
            return $content;
        }

        $content =
            preg_replace(
                '/^```(?:json|JSON)?[ \t]*\r?\n?/',
                '',
                $content,
                1
            );

        $content =
            preg_replace(
                '/\r?\n?```[ \t]*$/',
                '',
                (string) $content,
                1
            );

        return trim(
            (string) $content
        );
    }

    /**
     * Extract the first complete JSON object.
     *
     * Correctly handles:
     *
     * - nested objects;
     * - quoted strings;
     * - escaped quotes;
     * - braces inside strings.
     *
     * @param string $content Content.
     *
     * @return string
     */
    private function extract_json_object(
        string $content
    ): string {
        $length =
            strlen($content);

        $start =
            strpos(
                $content,
                '{'
            );

        if ($start === false) {
            return '';
        }

        $depth = 0;
        $in_string = false;
        $escaped = false;

        for (
            $index = $start;
            $index < $length;
            $index++
        ) {
            $character =
                $content[$index];

            if ($in_string) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($character === '"') {
                    $in_string = false;
                }

                continue;
            }

            if ($character === '"') {
                $in_string = true;

                continue;
            }

            if ($character === '{') {
                $depth++;

                continue;
            }

            if ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr(
                        $content,
                        $start,
                        $index - $start + 1
                    );
                }
            }
        }

        return '';
    }

    /**
     * Extract and normalize usage metadata.
     *
     * Preserves compatibility field names together with the
     * accounting fields required by the usage layer.
     *
     * @param array<string,mixed> $response Client response.
     *
     * @return array<string,int|float>
     */
    private function usage_value(
        array $response
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

        $cached_input_tokens =
            absint(
                $usage['cached_input_tokens']
                ?? 0
            );

        /*
         * Cached input cannot exceed total input tokens.
         */
        if (
            $cached_input_tokens >
            $input_tokens
        ) {
            $cached_input_tokens =
                $input_tokens;
        }

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

        /*
         * Guard against inconsistent provider accounting.
         */
        if (
            $total_tokens <
            ($input_tokens + $output_tokens)
        ) {
            $total_tokens =
                $input_tokens +
                $output_tokens;
        }

        $cost_usd =
            isset($usage['cost_usd']) &&
            is_numeric($usage['cost_usd'])
                ? max(
                    0.0,
                    (float) $usage['cost_usd']
                )
                : 0.0;

        return [
            'prompt_tokens' =>
                $input_tokens,

            'input_tokens' =>
                $input_tokens,

            'cached_input_tokens' =>
                $cached_input_tokens,

            'completion_tokens' =>
                $output_tokens,

            'output_tokens' =>
                $output_tokens,

            'total_tokens' =>
                $total_tokens,

            'cost_usd' =>
                round(
                    $cost_usd,
                    8
                ),
        ];
    }

    /**
     * Get prompt metadata.
     *
     * @return array<string,mixed>
     */
    private function get_prompt_meta(): array
    {
        /*
         * analysis_meta() is part of the prompt layer's public
         * compatibility contract.
         */
        if (
            !method_exists(
                'AIJP_AI_Prompts',
                'analysis_meta'
            )
        ) {
            return [
                'prompt_version' =>
                    method_exists(
                        'AIJP_AI_Prompts',
                        'get_version'
                    )
                        ? (string) AIJP_AI_Prompts::get_version()
                        : '',
                'prompt_type' =>
                    self::OPERATION_JOB_ANALYSIS,
            ];
        }

        $meta =
            AIJP_AI_Prompts::analysis_meta();

        if (!is_array($meta)) {
            $meta = [];
        }

        $prompt_version =
            $this->string_value(
                $meta,
                'prompt_version'
            );

        if (
            $prompt_version === '' &&
            method_exists(
                'AIJP_AI_Prompts',
                'get_version'
            )
        ) {
            $prompt_version =
                (string) AIJP_AI_Prompts::get_version();
        }

        $prompt_type =
            $this->string_value(
                $meta,
                'prompt_type'
            );

        if ($prompt_type === '') {
            $prompt_type =
                $this->string_value(
                    $meta,
                    'type'
                );
        }

        if ($prompt_type === '') {
            $prompt_type =
                self::OPERATION_JOB_ANALYSIS;
        }

        $meta['prompt_version'] =
            $prompt_version;

        $meta['prompt_type'] =
            $prompt_type;

        return $meta;
    }

    /**
     * Extract safe context metadata.
     *
     * @param array<string,mixed> $context AI context.
     *
     * @return array<string,mixed>
     */
    private function context_meta(
        array $context
    ): array {
        $metadata =
            isset($context['metadata']) &&
            is_array($context['metadata'])
                ? $context['metadata']
                : [];

        $agent =
            isset($context['agent']) &&
            is_array($context['agent'])
                ? $context['agent']
                : [];

        $version =
            $this->string_value(
                $metadata,
                'context_version'
            );

        if ($version === '') {
            $version =
                self::CONTEXT_VERSION;
        }

        $operation =
            $this->string_value(
                $agent,
                'operation'
            );

        if ($operation === '') {
            $operation =
                self::OPERATION_JOB_ANALYSIS;
        }

        return [
            'context_version' =>
                $version,

            'operation' =>
                $operation,
        ];
    }

    /**
     * Safely get a scalar string value from an array.
     *
     * @param array<string,mixed> $data Data.
     * @param string              $key  Key.
     *
     * @return string
     */
    private function string_value(
        array $data,
        string $key
    ): string {
        if (
            !array_key_exists(
                $key,
                $data
            )
        ) {
            return '';
        }

        $value =
            $data[$key];

        if (
            $value === null ||
            is_array($value) ||
            is_object($value)
        ) {
            return '';
        }

        return trim(
            (string) $value
        );
    }
}
