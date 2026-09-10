<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Agent.
 *
 * Coordinates the AI analysis workflow for a prepared job context.
 *
 * Flow:
 *
 * AIJP_AI_Agent_Context
 *          ↓
 * AIJP_AI_Agent
 *          ↓
 * AIJP_AI::analyze_context()
 *          ↓
 * AIJP_AI_Client
 *          ↓
 * AIJP_AI_Validator
 *          ↓
 * Stable Agent result
 *
 * This class does not:
 *
 * - load jobs directly from the database;
 * - update job statuses;
 * - save analysis results;
 * - manage cron jobs.
 */
class AIJP_AI_Agent
{
    /**
     * Current context version understood by this Agent.
     *
     * @var string
     */
    private const CONTEXT_VERSION = '1.1';

    /**
     * Supported Agent operations.
     *
     * @var array<int,string>
     */
    private const SUPPORTED_OPERATIONS = [
        'job_analysis',
    ];

    /**
     * AI service.
     *
     * @var AIJP_AI
     */
    private AIJP_AI $ai;

    /**
     * Constructor.
     *
     * @param AIJP_AI|null $ai AI service.
     */
    public function __construct(
        ?AIJP_AI $ai = null
    ) {
        $this->ai =
            $ai
            ?? new AIJP_AI();
    }

    /**
     * Analyze a prepared AI Agent context.
     *
     * @param array<string,mixed> $context Prepared AI context.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function analyze(
        array $context
    ): array|WP_Error {
        $validation = $this->validate_context(
            $context
        );

        if (is_wp_error($validation)) {
            return $validation;
        }

        /*
         * Ensure the Agent operation exists.
         *
         * The context builder normally provides this descriptor,
         * but keeping a deterministic default here makes the Agent
         * safe for older callers.
         */
        if (
            !isset($context['agent']) ||
            !is_array($context['agent'])
        ) {
            $context['agent'] = [
                'operation' => 'job_analysis',
            ];
        }

        $result = $this->ai->analyze_context(
            $context
        );

        if (is_wp_error($result)) {
            return $result;
        }

        if (!is_array($result)) {
            return new WP_Error(
                'aijp_ai_agent_invalid_result',
                __(
                    'AI service returned an invalid result.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * The AI service must return a normalized analysis object.
         */
        if (
            !isset($result['analysis']) ||
            !is_array($result['analysis'])
        ) {
            return new WP_Error(
                'aijp_ai_agent_missing_analysis',
                __(
                    'AI service did not return a valid analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        if ($result['analysis'] === []) {
            return new WP_Error(
                'aijp_ai_agent_empty_analysis',
                __(
                    'AI service returned an empty analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Preserve all metadata required by the processor and repository.
         *
         * The Agent deliberately does not persist anything. It only
         * normalizes the result into a stable internal contract.
         */
        return [
            'analysis' =>
                $result['analysis'],

            'provider' =>
                $this->string_value(
                    $result,
                    'provider'
                ),

            'model' =>
                $this->string_value(
                    $result,
                    'model'
                ),

            'usage' =>
                $this->normalize_usage(
                    $result['usage'] ?? []
                ),

            'prompt_meta' =>
                $this->normalize_prompt_meta(
                    $result['prompt_meta'] ?? []
                ),

            'context_meta' =>
                $this->normalize_context_meta(
                    $result['context_meta'] ?? [],
                    $context
                ),

            'raw_response' =>
                $this->string_value(
                    $result,
                    'raw_response'
                ),
        ];
    }

    /**
     * Analyze a job.
     *
     * Compatibility method for callers that provide raw job data
     * instead of a prepared Agent context.
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
                'aijp_ai_agent_empty_job',
                __(
                    'Cannot analyze an empty job.',
                    'ai-job-pipeline'
                )
            );
        }

        return $this->analyze(
            [
                'job' => $job,

                'metadata' => [
                    'context_version' =>
                        self::CONTEXT_VERSION,
                ],

                'agent' => [
                    'operation' =>
                        'job_analysis',
                ],
            ]
        );
    }

    /**
     * Validate Agent context before passing it to AIJP_AI.
     *
     * @param array<string,mixed> $context Context.
     *
     * @return true|WP_Error
     */
    private function validate_context(
        array $context
    ): true|WP_Error {
        /*
         * Job is mandatory.
         */
        if (
            !isset($context['job']) ||
            !is_array($context['job']) ||
            $context['job'] === []
        ) {
            return new WP_Error(
                'aijp_ai_agent_invalid_context',
                __(
                    'AI Agent context must contain a non-empty job array.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Metadata is optional for backwards compatibility.
         */
        if (
            isset($context['metadata']) &&
            !is_array($context['metadata'])
        ) {
            return new WP_Error(
                'aijp_ai_agent_invalid_metadata',
                __(
                    'AI Agent context metadata must be an array.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Agent descriptor is optional for backwards compatibility.
         *
         * If supplied, however, it must contain a supported operation.
         */
        if (isset($context['agent'])) {
            if (!is_array($context['agent'])) {
                return new WP_Error(
                    'aijp_ai_agent_invalid_descriptor',
                    __(
                        'AI Agent descriptor must be an array.',
                        'ai-job-pipeline'
                    )
                );
            }

            $operation = isset($context['agent']['operation'])
                ? trim(
                    (string) $context['agent']['operation']
                )
                : '';

            if ($operation === '') {
                return new WP_Error(
                    'aijp_ai_agent_operation_missing',
                    __(
                        'AI Agent operation is missing.',
                        'ai-job-pipeline'
                    )
                );
            }

            if (
                !in_array(
                    $operation,
                    self::SUPPORTED_OPERATIONS,
                    true
                )
            ) {
                return new WP_Error(
                    'aijp_ai_agent_operation_unsupported',
                    sprintf(
                        __(
                            'AI Agent operation "%s" is not supported.',
                            'ai-job-pipeline'
                        ),
                        $operation
                    ),
                    [
                        'operation' => $operation,
                        'supported_operations' =>
                            self::SUPPORTED_OPERATIONS,
                    ]
                );
            }
        }

        /*
         * Reject contexts newer than this Agent understands.
         *
         * Older versions remain accepted because the context builder
         * is responsible for keeping backwards compatibility.
         */
        if (
            isset($context['metadata']['context_version'])
        ) {
            $version = trim(
                (string) $context['metadata']['context_version']
            );

            if (
                $version !== '' &&
                version_compare(
                    $version,
                    self::CONTEXT_VERSION,
                    '>'
                )
            ) {
                return new WP_Error(
                    'aijp_ai_agent_context_version_unsupported',
                    sprintf(
                        __(
                            'AI Agent context version "%s" is newer than supported version "%s".',
                            'ai-job-pipeline'
                        ),
                        $version,
                        self::CONTEXT_VERSION
                    ),
                    [
                        'context_version' =>
                            $version,

                        'supported_version' =>
                            self::CONTEXT_VERSION,
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Normalize token and cost usage.
     *
     * The AI Client is the source of truth for calculated cost.
     * The Agent must preserve that information rather than recalculate it.
     *
     * @param mixed $usage Usage data.
     *
     * @return array<string,int|float>
     */
    private function normalize_usage(
        mixed $usage
    ): array {
        if (!is_array($usage)) {
            $usage = [];
        }

        $input_tokens = absint(
            $usage['input_tokens']
            ?? $usage['prompt_tokens']
            ?? 0
        );

        $output_tokens = absint(
            $usage['output_tokens']
            ?? $usage['completion_tokens']
            ?? 0
        );

        $cached_input_tokens = $this->get_cached_input_tokens(
            $usage
        );

        /*
         * Cached input tokens cannot exceed total input tokens.
         */
        $cached_input_tokens = min(
            $cached_input_tokens,
            $input_tokens
        );

        $total_tokens = absint(
            $usage['total_tokens']
            ?? (
                $input_tokens +
                $output_tokens
            )
        );

        /*
         * A malformed provider response must not produce a total lower
         * than the sum of its input/output token counts.
         */
        $minimum_total = $input_tokens + $output_tokens;

        if ($total_tokens < $minimum_total) {
            $total_tokens = $minimum_total;
        }

        $cost_usd = 0.0;

        if (
            isset($usage['cost_usd']) &&
            is_numeric($usage['cost_usd'])
        ) {
            $cost_usd = max(
                0.0,
                (float) $usage['cost_usd']
            );
        }

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
     * Extract cached input tokens from all supported usage formats.
     *
     * @param array<string,mixed> $usage Usage data.
     *
     * @return int
     */
    private function get_cached_input_tokens(
        array $usage
    ): int {
        /*
         * Responses API format.
         */
        if (
            isset($usage['input_tokens_details']) &&
            is_array($usage['input_tokens_details'])
        ) {
            return absint(
                $usage['input_tokens_details']['cached_tokens']
                ?? 0
            );
        }

        /*
         * Chat Completions-compatible format.
         */
        if (
            isset($usage['prompt_tokens_details']) &&
            is_array($usage['prompt_tokens_details'])
        ) {
            return absint(
                $usage['prompt_tokens_details']['cached_tokens']
                ?? 0
            );
        }

        /*
         * Already normalized internal format.
         */
        return absint(
            $usage['cached_input_tokens']
            ?? 0
        );
    }

    /**
     * Normalize prompt metadata.
     *
     * @param mixed $prompt_meta Prompt metadata.
     *
     * @return array<string,mixed>
     */
    private function normalize_prompt_meta(
        mixed $prompt_meta
    ): array {
        if (!is_array($prompt_meta)) {
            $prompt_meta = [];
        }

        $version = isset($prompt_meta['version'])
            ? trim(
                (string) $prompt_meta['version']
            )
            : '';

        $type = isset($prompt_meta['type'])
            ? trim(
                (string) $prompt_meta['type']
            )
            : '';

        if ($version === '') {
            $version = AIJP_AI_Prompts::get_version();
        }

        if ($type === '') {
            $type = 'job_analysis';
        }

        return [
            'version' =>
                $version,

            'type' =>
                $type,
        ];
    }

    /**
     * Normalize context metadata.
     *
     * @param mixed                $context_meta Context metadata.
     * @param array<string,mixed>  $context      Original context.
     *
     * @return array<string,mixed>
     */
    private function normalize_context_meta(
        mixed $context_meta,
        array $context
    ): array {
        if (!is_array($context_meta)) {
            $context_meta = [];
        }

        $context_version = isset(
            $context_meta['context_version']
        )
            ? trim(
                (string) $context_meta['context_version']
            )
            : '';

        /*
         * Prefer the actual context metadata when the AI service did not
         * return its own context metadata.
         */
        if ($context_version === '') {
            $context_version = isset(
                $context['metadata']['context_version']
            )
                ? trim(
                    (string) $context['metadata']['context_version']
                )
                : '';
        }

        if ($context_version === '') {
            $context_version = self::CONTEXT_VERSION;
        }

        $context_meta['context_version'] =
            $context_version;

        return $context_meta;
    }

    /**
     * Safely get a string value.
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

        $value = $data[$key];

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
