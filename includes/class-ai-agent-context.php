<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a normalized context for AI agent operations.
 *
 * The agent must not work directly with raw database objects.
 * This class creates a predictable context structure that can
 * later be used by multiple AI operations:
 *
 * - job analysis;
 * - planning;
 * - verification;
 * - proposal generation;
 * - retries;
 * - future multi-step agent workflows.
 *
 * The context uses canonical repository/database field names.
 */
class AIJP_AI_Agent_Context
{
    /**
     * Current context contract version.
     */
    private const CONTEXT_VERSION = '1.1';

    /**
     * Build complete agent context for a job.
     *
     * @param int $job_id Job ID.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function build(
        int $job_id
    ): array|WP_Error {
        if ($job_id <= 0) {
            return new WP_Error(
                'aijp_invalid_job_id',
                __(
                    'Invalid job ID.',
                    'ai-job-pipeline'
                )
            );
        }

        $job = AIJP_Job_Repository::get(
            $job_id
        );

        if (!$job) {
            return new WP_Error(
                'aijp_job_not_found',
                __(
                    'Job not found.',
                    'ai-job-pipeline'
                )
            );
        }

        $job_data = $this->normalize_job(
            $job
        );

        if ($job_data['id'] <= 0) {
            return new WP_Error(
                'aijp_invalid_job_data',
                __(
                    'Job data is invalid.',
                    'ai-job-pipeline'
                )
            );
        }

        $source = $this->get_source(
            $job_data['source_id']
        );

        $previous_analysis =
            $this->get_previous_analysis(
                $job_id
            );

        return [
            'job_id' => $job_data['id'],

            'job' => $job_data,

            'source' => $source,

            'previous_analysis' =>
                $previous_analysis,

            'metadata' => [
                'context_version' =>
                    self::CONTEXT_VERSION,

                'generated_at' =>
                    current_time(
                        'mysql',
                        true
                    ),

                'wordpress_site' =>
                    home_url(),

                'locale' =>
                    get_locale(),
            ],
        ];
    }

    /**
     * Normalize a job object into agent-safe canonical data.
     *
     * Canonical job fields:
     *
     * - id
     * - source_id
     * - external_id
     * - title
     * - description
     * - url
     * - budget_amount
     * - budget_currency
     * - lang
     * - country
     * - category
     * - published_at
     * - found_at
     * - status
     * - assignee
     * - hash
     * - raw_data
     * - created_at
     * - updated_at
     *
     * A small set of compatibility aliases is also exposed because
     * older prompt code may still expect original_url/language/etc.
     *
     * @param object|array<string,mixed> $job Job.
     *
     * @return array<string,mixed>
     */
    private function normalize_job(
        object|array $job
    ): array {
        if (is_object($job)) {
            $job = get_object_vars(
                $job
            );
        }

        $id = absint(
            $job['id'] ?? 0
        );

        $source_id = absint(
            $job['source_id'] ?? 0
        );

        $external_id = $this->string(
            $job['external_id'] ?? ''
        );

        $title = $this->string(
            $job['title'] ?? ''
        );

        $description = $this->string(
            $job['description'] ?? ''
        );

        /*
         * Canonical URL.
         *
         * The corrected Job Repository exposes url as the real
         * database field and original_url as a compatibility alias.
         */
        $url = $this->string(
            $job['url']
                ?? $job['original_url']
                ?? ''
        );

        $budget_amount =
            $this->nullable_float(
                $job['budget_amount']
                    ?? null
            );

        $budget_currency =
            $this->string(
                $job['budget_currency']
                    ?? $job['currency']
                    ?? ''
            );

        $lang = $this->string(
            $job['lang']
                ?? $job['language']
                ?? ''
        );

        $country = $this->string(
            $job['country'] ?? ''
        );

        $category = $this->string(
            $job['category'] ?? ''
        );

        $published_at =
            $this->string(
                $job['published_at'] ?? ''
            );

        $found_at =
            $this->string(
                $job['found_at']
                    ?? $job['imported_at']
                    ?? ''
            );

        $status = $this->string(
            $job['status'] ?? ''
        );

        $assignee = $this->string(
            $job['assignee']
                ?? $job['assigned_to']
                ?? ''
        );

        $hash = $this->string(
            $job['hash'] ?? ''
        );

        $created_at =
            $this->string(
                $job['created_at'] ?? ''
            );

        $updated_at =
            $this->string(
                $job['updated_at'] ?? ''
            );

        $raw_data =
            $this->decode_json(
                $job['raw_data'] ?? null
            );

        /*
         * Canonical budget representation.
         *
         * The new database has one budget_amount value.
         * We expose it as both min/max for compatibility with
         * older prompt code, but the canonical value remains amount.
         */
        $budget = [
            'amount' =>
                $budget_amount,

            'currency' =>
                $budget_currency,

            /*
             * Compatibility values.
             */
            'min' =>
                $budget_amount,

            'max' =>
                $budget_amount,
        ];

        return [
            'id' => $id,

            'source_id' =>
                $source_id,

            'external_id' =>
                $external_id,

            'title' =>
                $title,

            'description' =>
                $description,

            /*
             * Canonical field.
             */
            'url' =>
                $url,

            /*
             * Compatibility alias for existing prompts.
             */
            'original_url' =>
                $url,

            'country' =>
                $country,

            /*
             * Canonical language field.
             */
            'lang' =>
                $lang,

            /*
             * Compatibility alias.
             */
            'language' =>
                $lang,

            'category' =>
                $category,

            /*
             * Canonical budget fields.
             */
            'budget_amount' =>
                $budget_amount,

            'budget_currency' =>
                $budget_currency,

            /*
             * Structured budget used by AI prompts.
             */
            'budget' =>
                $budget,

            /*
             * Compatibility aliases.
             */
            'budget_min' =>
                $budget_amount,

            'budget_max' =>
                $budget_amount,

            'currency' =>
                $budget_currency,

            'status' =>
                $status,

            'assignee' =>
                $assignee,

            /*
             * Compatibility alias.
             */
            'assigned_to' =>
                $assignee,

            'hash' =>
                $hash,

            'published_at' =>
                $published_at,

            /*
             * Canonical import timestamp.
             */
            'found_at' =>
                $found_at,

            /*
             * Compatibility alias.
             */
            'imported_at' =>
                $found_at,

            'created_at' =>
                $created_at,

            'updated_at' =>
                $updated_at,

            /*
             * Raw source data remains separate from normalized
             * fields and is available for source-specific metadata.
             */
            'raw_data' =>
                $raw_data,
        ];
    }

    /**
     * Get normalized source data.
     *
     * The Source Repository is the single access point for source data.
     * This avoids duplicating source SQL and keeps this class independent
     * from the physical database schema.
     *
     * @param int $source_id Source ID.
     *
     * @return array<string,mixed>|null
     */
    private function get_source(
        int $source_id
    ): ?array {
        if ($source_id <= 0) {
            return null;
        }

        $repository =
            new AIJP_Source_Repository();

        $source =
            $repository->get(
                $source_id
            );

        if (!is_object($source) && !is_array($source)) {
            return null;
        }

        if (is_object($source)) {
            $source =
                get_object_vars(
                    $source
                );
        }

        $id = absint(
            $source['id'] ?? 0
        );

        if ($id <= 0) {
            return null;
        }

        $source_type =
            $this->string(
                $source['source_type']
                    ?? $source['type']
                    ?? ''
            );

        $enabled =
            $this->boolean(
                $source['enabled']
                    ?? $source['is_active']
                    ?? false
            );

        $config =
            $this->decode_json(
                $source['config'] ?? null
            );

        $language =
            $this->string(
                $source['language'] ?? ''
            );

        $country =
            $this->string(
                $source['country'] ?? ''
            );

        return [
            'id' =>
                $id,

            'name' =>
                $this->string(
                    $source['name'] ?? ''
                ),

            /*
             * Canonical source type.
             */
            'source_type' =>
                $source_type,

            /*
             * Compatibility alias.
             */
            'type' =>
                $source_type,

            'url' =>
                $this->string(
                    $source['url'] ?? ''
                ),

            'country' =>
                $country,

            'language' =>
                $language,

            /*
             * Compatibility with job terminology.
             */
            'lang' =>
                $language,

            /*
             * Canonical enabled flag.
             */
            'enabled' =>
                $enabled,

            /*
             * Compatibility alias.
             */
            'is_active' =>
                $enabled,

            'last_import_at' =>
                $this->string(
                    $source['last_import_at'] ?? ''
                ),

            'last_error' =>
                $this->string(
                    $source['last_error'] ?? ''
                ),

            'config' =>
                $config,

            'created_at' =>
                $this->string(
                    $source['created_at'] ?? ''
                ),

            'updated_at' =>
                $this->string(
                    $source['updated_at'] ?? ''
                ),
        ];
    }

    /**
     * Get the latest stored AI analysis for the job.
     *
     * The analyses table currently stores one current analysis per job.
     *
     * IMPORTANT:
     * ai_feasible is a semantic value:
     *
     * - yes
     * - partial
     * - no
     *
     * It must not be converted to boolean because "partial" would
     * otherwise be indistinguishable from "yes".
     *
     * @param int $job_id Job ID.
     *
     * @return array<string,mixed>|null
     */
    private function get_previous_analysis(
        int $job_id
    ): ?array {
        if ($job_id <= 0) {
            return null;
        }

        global $wpdb;

        $table =
            AIJP_Database::table(
                'analyses'
            );

        $analysis =
            $wpdb->get_row(
                $wpdb->prepare(
                    "
                    SELECT *
                    FROM {$table}
                    WHERE job_id = %d
                    ORDER BY id DESC
                    LIMIT 1
                    ",
                    $job_id
                ),
                ARRAY_A
            );

        if (!is_array($analysis)) {
            return null;
        }

        $ai_feasible =
            $this->normalize_feasibility(
                $analysis['ai_feasible']
                    ?? null
            );

        return [
            'id' =>
                absint(
                    $analysis['id'] ?? 0
                ),

            'job_id' =>
                absint(
                    $analysis['job_id'] ?? 0
                ),

            'summary' =>
                $this->string(
                    $analysis['summary'] ?? ''
                ),

            'category' =>
                $this->string(
                    $analysis['category'] ?? ''
                ),

            'complexity' =>
                $this->nullable_int(
                    $analysis['complexity'] ?? null
                ),

            /*
             * Canonical semantic value.
             */
            'ai_feasible' =>
                $ai_feasible,

            'ai_feasible_reason' =>
                $this->string(
                    $analysis['ai_feasible_reason']
                        ?? ''
                ),

            'estimated_hours' =>
                $this->nullable_float(
                    $analysis['estimated_hours']
                        ?? null
                ),

            'recommendation' =>
                $this->string(
                    $analysis['recommendation']
                        ?? ''
                ),

            'confidence' =>
                $this->nullable_float(
                    $analysis['confidence']
                        ?? null
                ),

            'plan' =>
                $this->decode_json(
                    $analysis['plan'] ?? null
                ),

            'red_flags' =>
                $this->decode_json(
                    $analysis['red_flags'] ?? null
                ),

            'provider' =>
                $this->string(
                    $analysis['provider'] ?? ''
                ),

            'model' =>
                $this->string(
                    $analysis['model'] ?? ''
                ),

            'prompt_version' =>
                $this->string(
                    $analysis['prompt_version']
                        ?? ''
                ),

            'raw_response' =>
                $this->string(
                    $analysis['raw_response']
                        ?? ''
                ),

            'created_at' =>
                $this->string(
                    $analysis['created_at'] ?? ''
                ),

            'updated_at' =>
                $this->string(
                    $analysis['updated_at'] ?? ''
                ),
        ];
    }

    /**
     * Normalize AI feasibility value.
     *
     * Supports the new semantic contract and the old boolean DB values.
     *
     * @param mixed $value Raw value.
     *
     * @return string|null
     */
    private function normalize_feasibility(
        mixed $value
    ): ?string {
        if (
            is_string($value)
        ) {
            $value =
                strtolower(
                    trim($value)
                );

            if (
                in_array(
                    $value,
                    [
                        'yes',
                        'partial',
                        'no',
                    ],
                    true
                )
            ) {
                return $value;
            }

            /*
             * Legacy textual boolean values.
             */
            if (
                in_array(
                    $value,
                    [
                        'true',
                        '1',
                        'y',
                        'on',
                    ],
                    true
                )
            ) {
                return 'yes';
            }

            if (
                in_array(
                    $value,
                    [
                        'false',
                        '0',
                        'n',
                        'off',
                    ],
                    true
                )
            ) {
                return 'no';
            }

            return null;
        }

        if (is_bool($value)) {
            return $value
                ? 'yes'
                : 'no';
        }

        if (
            is_numeric($value)
        ) {
            return ((int) $value === 1)
                ? 'yes'
                : 'no';
        }

        return null;
    }

    /**
     * Decode JSON safely.
     *
     * @param mixed $value Value.
     *
     * @return array
     */
    private function decode_json(
        mixed $value
    ): array {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (
            !is_string($value) ||
            trim($value) === ''
        ) {
            return [];
        }

        $decoded =
            json_decode(
                $value,
                true
            );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    /**
     * Normalize string.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    private function string(
        mixed $value
    ): string {
        if (
            is_array($value) ||
            is_object($value) ||
            $value === null
        ) {
            return '';
        }

        return trim(
            (string) $value
        );
    }

    /**
     * Normalize nullable float.
     *
     * @param mixed $value Value.
     *
     * @return float|null
     */
    private function nullable_float(
        mixed $value
    ): ?float {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Normalize nullable integer.
     *
     * @param mixed $value Value.
     *
     * @return int|null
     */
    private function nullable_int(
        mixed $value
    ): ?int {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Normalize boolean.
     *
     * @param mixed $value Value.
     *
     * @return bool
     */
    private function boolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(
            strtolower(
                trim($value)
            ),
            [
                '1',
                'true',
                'yes',
                'y',
                'on',
            ],
            true
        );
    }
}