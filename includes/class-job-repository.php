<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Job repository.
 *
 * Single responsibility:
 * - read jobs;
 * - create jobs;
 * - update jobs;
 * - validate status transitions;
 * - save AI analysis results;
 * - provide normalized job data to the rest of the plugin.
 *
 * Database column names used here are canonical:
 *
 * source_id
 * external_id
 * title
 * description
 * url
 * budget_amount
 * budget_currency
 * lang
 * country
 * category
 * published_at
 * found_at
 * status
 * assignee
 * hash
 * raw_data
 * created_at
 * updated_at
 */
class AIJP_Job_Repository
{
    /**
     * Maximum number of jobs returned by one request.
     */
    private const MAX_LIMIT = 500;

    /**
     * Get one job by ID.
     *
     * This is the canonical single-job read method.
     *
     * @param int $id Job ID.
     * @return object|null
     */
    public static function get(int $id)
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $jobs_table    = AIJP_Database::table('jobs');
        $sources_table = AIJP_Database::table('sources');

        $sql = "
            SELECT
                j.*,

                j.url AS original_url,
                j.budget_amount AS budget_min,
                j.budget_amount AS budget_max,
                j.budget_currency AS currency,
                j.lang AS language,
                j.assignee AS assigned_to,
                j.found_at AS imported_at,

                s.name AS source_name,
                s.source_type AS source_type,
                s.enabled AS source_enabled

            FROM {$jobs_table} AS j

            LEFT JOIN {$sources_table} AS s
                ON s.id = j.source_id

            WHERE j.id = %d

            LIMIT 1
        ";

        return $wpdb->get_row(
            $wpdb->prepare(
                $sql,
                $id
            )
        );
    }

    /**
     * Backwards-compatible alias.
     *
     * Some older processors call find() instead of get().
     *
     * @param int $id Job ID.
     * @return object|null
     */
    public static function find(int $id)
    {
        return self::get($id);
    }

    /**
     * Get jobs with optional filters.
     *
     * Supported filters:
     * - status
     * - source_id
     * - category
     * - lang
     * - country
     * - assignee
     * - limit
     * - offset
     *
     * @param array<string,mixed> $filters Filters.
     * @return array<int,object>
     */
    public static function get_all(array $filters = []): array
    {
        global $wpdb;

        $jobs_table    = AIJP_Database::table('jobs');
        $sources_table = AIJP_Database::table('sources');

        $where  = [];
        $values = [];

        /*
         * Status.
         */
        if (!empty($filters['status'])) {
            $status = sanitize_key(
                (string) $filters['status']
            );

            if (AIJP_Job_Status::is_valid($status)) {
                $where[]  = 'j.status = %s';
                $values[] = $status;
            }
        }

        /*
         * Source.
         */
        if (!empty($filters['source_id'])) {
            $source_id = absint(
                $filters['source_id']
            );

            if ($source_id > 0) {
                $where[]  = 'j.source_id = %d';
                $values[] = $source_id;
            }
        }

        /*
         * Category.
         */
        if (!empty($filters['category'])) {
            $category = sanitize_text_field(
                (string) $filters['category']
            );

            if ($category !== '') {
                $where[]  = 'j.category = %s';
                $values[] = $category;
            }
        }

        /*
         * Language.
         */
        if (!empty($filters['lang'])) {
            $lang = sanitize_text_field(
                (string) $filters['lang']
            );

            if ($lang !== '') {
                $where[]  = 'j.lang = %s';
                $values[] = $lang;
            }
        }

        /*
         * Country.
         */
        if (!empty($filters['country'])) {
            $country = sanitize_text_field(
                (string) $filters['country']
            );

            if ($country !== '') {
                $where[]  = 'j.country = %s';
                $values[] = $country;
            }
        }

        /*
         * Assignee.
         */
        if (!empty($filters['assignee'])) {
            $assignee = sanitize_text_field(
                (string) $filters['assignee']
            );

            if ($assignee !== '') {
                $where[]  = 'j.assignee = %s';
                $values[] = $assignee;
            }
        }

        /*
         * Limit.
         */
        $limit = isset($filters['limit'])
            ? absint($filters['limit'])
            : 100;

        if ($limit <= 0) {
            $limit = 100;
        }

        $limit = min(
            $limit,
            self::MAX_LIMIT
        );

        /*
         * Offset.
         */
        $offset = isset($filters['offset'])
            ? absint($filters['offset'])
            : 0;

        $sql = "
            SELECT
                j.*,

                j.url AS original_url,
                j.budget_amount AS budget_min,
                j.budget_amount AS budget_max,
                j.budget_currency AS currency,
                j.lang AS language,
                j.assignee AS assigned_to,
                j.found_at AS imported_at,

                s.name AS source_name,
                s.source_type AS source_type,
                s.enabled AS source_enabled

            FROM {$jobs_table} AS j

            LEFT JOIN {$sources_table} AS s
                ON s.id = j.source_id
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(
                ' AND ',
                $where
            );
        }

        $sql .= "
            ORDER BY
                j.found_at DESC,
                j.id DESC
            LIMIT %d
            OFFSET %d
        ";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare(
                $sql,
                $values
            )
        );
    }

    /**
     * Get jobs by status.
     *
     * This is intentionally a first-class repository method so
     * background processors do not need direct SQL access.
     *
     * @param string $status Job status.
     * @param int    $limit  Maximum number of jobs.
     * @return array<int,object>
     */
    public static function get_by_status(
        string $status,
        int $limit = 50
    ): array {
        $status = sanitize_key($status);

        if (!AIJP_Job_Status::is_valid($status)) {
            return [];
        }

        return self::get_all(
            [
                'status' => $status,
                'limit'  => $limit,
            ]
        );
    }

    /**
     * Get jobs waiting for AI processing.
     *
     * The canonical pending state is FOUND.
     *
     * @param int $limit Maximum number of jobs.
     * @return array<int,object>
     */
    public static function get_pending_ai_jobs(
        int $limit = 50
    ): array {
        return self::get_by_status(
            AIJP_Job_Status::FOUND,
            $limit
        );
    }

    /**
     * Find a job by source and external ID.
     *
     * @param int    $source_id   Source ID.
     * @param string $external_id External ID.
     * @return object|null
     */
    public static function find_by_external_id(
        int $source_id,
        string $external_id
    ) {
        global $wpdb;

        if (
            $source_id <= 0 ||
            $external_id === ''
        ) {
            return null;
        }

        $table = AIJP_Database::table('jobs');

        return $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE source_id = %d
                  AND external_id = %s
                LIMIT 1
                ",
                $source_id,
                $external_id
            )
        );
    }

    /**
     * Backwards-compatible alias used by RSS importer.
     *
     * @param int    $source_id   Source ID.
     * @param string $external_id External ID.
     * @return object|null
     */
    public static function find_by_source_and_external_id(
        int $source_id,
        string $external_id
    ) {
        return self::find_by_external_id(
            $source_id,
            $external_id
        );
    }

    /**
     * Find a job by hash.
     *
     * @param string $hash Job hash.
     * @return object|null
     */
    public static function find_by_hash(string $hash)
    {
        global $wpdb;

        $hash = sanitize_text_field($hash);

        if ($hash === '') {
            return null;
        }

        $table = AIJP_Database::table('jobs');

        return $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE hash = %s
                LIMIT 1
                ",
                $hash
            )
        );
    }

    /**
     * Insert a job.
     *
     * @param array<string,mixed> $data Job data.
     * @return int|false Inserted ID or false.
     */
    public static function insert(array $data)
    {
        global $wpdb;

        $table = AIJP_Database::table('jobs');

        $prepared = self::prepare_data(
            $data
        );

        if ($prepared === false) {
            return false;
        }

        $formats = self::get_formats(
            $prepared
        );

        $result = $wpdb->insert(
            $table,
            $prepared,
            $formats
        );

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Insert unless the job already exists.
     *
     * Deduplication happens by:
     * 1. source_id + external_id;
     * 2. deterministic hash.
     *
     * @param array<string,mixed> $data Job data.
     * @return int|false Existing/new ID or false.
     */
    public static function insert_or_ignore(
        array $data
    ) {
        if (
            empty($data['source_id']) ||
            empty($data['external_id'])
        ) {
            return false;
        }

        $source_id = absint(
            $data['source_id']
        );

        $external_id = sanitize_text_field(
            (string) $data['external_id']
        );

        if (
            $source_id <= 0 ||
            $external_id === ''
        ) {
            return false;
        }

        /*
         * Primary deduplication.
         */
        $existing = self::find_by_external_id(
            $source_id,
            $external_id
        );

        if ($existing) {
            return (int) $existing->id;
        }

        /*
         * Generate canonical hash before insertion.
         */
        if (empty($data['hash'])) {
            $data['hash'] = self::build_hash(
                $source_id,
                $external_id
            );
        }

        /*
         * Secondary deduplication.
         */
        $existing_by_hash = self::find_by_hash(
            (string) $data['hash']
        );

        if ($existing_by_hash) {
            return (int) $existing_by_hash->id;
        }

        return self::insert($data);
    }

    /**
     * Update a job.
     *
     * @param int                  $id   Job ID.
     * @param array<string,mixed>  $data Data to update.
     * @return bool
     */
    public static function update(
        int $id,
        array $data
    ): bool {
        global $wpdb;

        if (
            $id <= 0 ||
            empty($data)
        ) {
            return false;
        }

        $table = AIJP_Database::table('jobs');

        /*
         * Only canonical database columns are allowed.
         *
         * Compatibility aliases such as:
         * original_url
         * language
         * budget_min
         * budget_max
         * currency
         * imported_at
         * assigned_to
         *
         * must never reach the database UPDATE.
         */
        $allowed = [
            'source_id',
            'external_id',
            'title',
            'description',
            'url',
            'budget_amount',
            'budget_currency',
            'lang',
            'country',
            'category',
            'published_at',
            'found_at',
            'status',
            'assignee',
            'hash',
            'raw_data',
        ];

        $clean = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = $data[$field];
            }
        }

        if (empty($clean)) {
            return false;
        }

        $clean = self::prepare_update_data(
            $clean
        );

        if ($clean === false) {
            return false;
        }

        /*
         * Always maintain updated_at.
         *
         * It is added separately because it is not exposed as a
         * public mutable field.
         */
        $clean['updated_at'] = current_time(
            'mysql',
            true
        );

        $formats = self::get_formats(
            $clean
        );

        $result = $wpdb->update(
            $table,
            $clean,
            [
                'id' => $id,
            ],
            $formats,
            [
                '%d',
            ]
        );

        return $result !== false;
    }

    /**
     * Change job status with transition validation.
     *
     * @param int    $id     Job ID.
     * @param string $status New status.
     * @return bool
     */
    public static function update_status(
        int $id,
        string $status
    ): bool {
        $job = self::get($id);

        if (!$job) {
            return false;
        }

        $status = sanitize_key($status);

        if (!AIJP_Job_Status::is_valid($status)) {
            return false;
        }

        $current_status = (string) $job->status;

        /*
         * Re-applying the same status is harmless.
         */
        if ($current_status === $status) {
            return true;
        }

        if (
            !AIJP_Job_Status::can_transition(
                $current_status,
                $status
            )
        ) {
            return false;
        }

        return self::update(
            $id,
            [
                'status' => $status,
            ]
        );
    }

    /**
     * Save AI analysis for a job.
     *
     * The analyses table is a one-to-one relation with jobs.
     * Therefore INSERT is followed by UPDATE when an analysis
     * for the same job already exists.
     *
     * @param int                  $job_id  Job ID.
     * @param array<string,mixed>  $analysis AI analysis data.
     * @param array<string,mixed>  $metadata Provider and prompt metadata.
     * @return int|false Analysis ID or false.
     */
    public static function save_ai_analysis(
        int $job_id,
        array $analysis,
        array $metadata = []
    ) {
        global $wpdb;

        if ($job_id <= 0) {
            return false;
        }

        $jobs_table     = AIJP_Database::table('jobs');
        $analyses_table = AIJP_Database::table('analyses');

        /*
         * Make sure the job actually exists.
         */
        $job_exists = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT id
                FROM {$jobs_table}
                WHERE id = %d
                LIMIT 1
                ",
                $job_id
            )
        );

        if (!$job_exists) {
            return false;
        }

        /*
         * The processor keeps provider metadata separate from the business
         * analysis. Normalize the two inputs into the repository contract.
         * Usage itself is persisted once by AIJP_AI_Client, where the paid
         * provider response is first received.
         */
        foreach (
            [
                'provider',
                'model',
                'prompt_meta',
                'raw_response',
            ] as $metadata_key
        ) {
            if (array_key_exists($metadata_key, $metadata)) {
                $analysis[$metadata_key] =
                    $metadata[$metadata_key];
            }
        }

        /*
         * Canonical analysis fields.
         */
        $summary = isset($analysis['summary'])
            ? sanitize_textarea_field(
                (string) $analysis['summary']
            )
            : '';

        $category = isset($analysis['category'])
            ? sanitize_key(
                (string) $analysis['category']
            )
            : '';

        $complexity = isset($analysis['complexity'])
            ? absint($analysis['complexity'])
            : null;

        if ($complexity !== null) {
            $complexity = max(
                1,
                min(5, $complexity)
            );
        }

        /*
         * ai_feasible is semantically an enum:
         * yes / partial / no
         *
         * Do not convert it to boolean.
         */
        $ai_feasible = isset($analysis['ai_feasible'])
            ? sanitize_key(
                (string) $analysis['ai_feasible']
            )
            : 'no';

        if (
            !in_array(
                $ai_feasible,
                [
                    'yes',
                    'partial',
                    'no',
                ],
                true
            )
        ) {
            $ai_feasible = 'no';
        }

        $ai_feasible_reason =
            isset($analysis['ai_feasible_reason'])
                ? sanitize_textarea_field(
                    (string) $analysis['ai_feasible_reason']
                )
                : '';

        $estimated_hours =
            isset($analysis['estimated_hours']) &&
            is_numeric($analysis['estimated_hours'])
                ? round(
                    max(
                        0,
                        (float) $analysis['estimated_hours']
                    ),
                    2
                )
                : null;

        $recommendation =
            isset($analysis['recommendation'])
                ? sanitize_key(
                    (string) $analysis['recommendation']
                )
                : '';

        if (
            !in_array(
                $recommendation,
                [
                    'take',
                    'skip',
                ],
                true
            )
        ) {
            $recommendation = 'skip';
        }

        $confidence =
            isset($analysis['confidence']) &&
            is_numeric($analysis['confidence'])
                ? (float) $analysis['confidence']
                : null;

        if ($confidence !== null) {
            $confidence = max(
                0,
                min(1, $confidence)
            );

            $confidence = round(
                $confidence,
                4
            );
        }

        /*
         * Arrays are stored as JSON.
         */
        $plan = self::normalize_string_array(
            $analysis['plan'] ?? []
        );

        $red_flags = self::normalize_string_array(
            $analysis['red_flags'] ?? []
        );

        $model = isset($analysis['model'])
            ? sanitize_text_field(
                (string) $analysis['model']
            )
            : '';

        $provider = isset($analysis['provider'])
            ? sanitize_key(
                (string) $analysis['provider']
            )
            : '';

        /*
         * Prompt metadata.
         */
        $prompt_meta =
            isset($analysis['prompt_meta']) &&
            is_array($analysis['prompt_meta'])
                ? $analysis['prompt_meta']
                : [];

        $prompt_version =
            isset($prompt_meta['version'])
                ? sanitize_text_field(
                    (string) $prompt_meta['version']
                )
                : '';

        /*
         * Raw response is useful for debugging but can be large.
         * Keep it as text and never expose it automatically to
         * frontend users.
         */
        $raw_response =
            isset($analysis['raw_response'])
                ? (string) $analysis['raw_response']
                : '';

        /*
         * Some callers pass provider/model outside the nested
         * analysis array. Support both contracts.
         */
        if (
            $provider === '' &&
            isset($analysis['ai_provider'])
        ) {
            $provider = sanitize_key(
                (string) $analysis['ai_provider']
            );
        }

        if (
            $model === '' &&
            isset($analysis['ai_model'])
        ) {
            $model = sanitize_text_field(
                (string) $analysis['ai_model']
            );
        }

        /*
         * Build the database payload.
         *
         * Do not write unknown fields. This is important because
         * the AI response may contain provider-specific metadata.
         */
        $data = [
            'job_id' => $job_id,

            'summary' =>
                $summary,

            'category' =>
                $category,

            'complexity' =>
                $complexity,

            'ai_feasible' =>
                $ai_feasible,

            'ai_feasible_reason' =>
                $ai_feasible_reason,

            'estimated_hours' =>
                $estimated_hours,

            'recommendation' =>
                $recommendation,

            'confidence' =>
                $confidence,

            'plan' =>
                wp_json_encode(
                    $plan,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'red_flags' =>
                wp_json_encode(
                    $red_flags,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'model' =>
                $model,

            'prompt_version' =>
                $prompt_version,

            'raw_response' =>
                $raw_response,

            'updated_at' =>
                current_time(
                    'mysql',
                    true
                ),
        ];

        /*
         * Provider is optional because older database versions
         * may not have the column yet.
         */
        if ($provider !== '') {
            $data['provider'] = $provider;
        }

        /*
         * Check whether analysis already exists.
         */
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT id
                FROM {$analyses_table}
                WHERE job_id = %d
                LIMIT 1
                ",
                $job_id
            )
        );

        /*
         * Existing analysis -> UPDATE.
         */
        if ($existing_id) {
            /*
             * updated_at is already present in $data.
             */
            $formats = self::get_analysis_formats(
                $data
            );

            /*
             * job_id is not changed.
             */
            unset(
                $data['job_id']
            );

            $result = $wpdb->update(
                $analyses_table,
                $data,
                [
                    'id' => (int) $existing_id,
                ],
                $formats,
                [
                    '%d',
                ]
            );

            if ($result === false) {
                return false;
            }

            return (int) $existing_id;
        }

        /*
         * New analysis -> INSERT.
         */
        $data['created_at'] = current_time(
            'mysql',
            true
        );

        $formats = self::get_analysis_formats(
            $data
        );

        $result = $wpdb->insert(
            $analyses_table,
            $data,
            $formats
        );

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Return job statuses.
     *
     * @return array<string,string>
     */
    public static function get_statuses(): array
    {
        $statuses = [];

        foreach (
            AIJP_Job_Status::all()
            as $status
        ) {
            $statuses[$status] =
                AIJP_Job_Status::label(
                    $status
                );
        }

        return $statuses;
    }

    /**
     * Count jobs.
     *
     * @param array<string,mixed> $filters Filters.
     * @return int
     */
    public static function count(
        array $filters = []
    ): int {
        global $wpdb;

        $table = AIJP_Database::table(
            'jobs'
        );

        $where  = [];
        $values = [];

        if (!empty($filters['status'])) {
            $status = sanitize_key(
                (string) $filters['status']
            );

            if (
                AIJP_Job_Status::is_valid(
                    $status
                )
            ) {
                $where[]  = 'status = %s';
                $values[] = $status;
            }
        }

        if (!empty($filters['source_id'])) {
            $source_id = absint(
                $filters['source_id']
            );

            if ($source_id > 0) {
                $where[]  = 'source_id = %d';
                $values[] = $source_id;
            }
        }

        $sql = "
            SELECT COUNT(*)
            FROM {$table}
        ";

        if (!empty($where)) {
            $sql .=
                ' WHERE ' .
                implode(
                    ' AND ',
                    $where
                );
        }

        if (!empty($values)) {
            $sql = $wpdb->prepare(
                $sql,
                $values
            );
        }

        return (int) $wpdb->get_var(
            $sql
        );
    }

    /**
     * Build deterministic SHA-1 hash.
     *
     * @param int    $source_id   Source ID.
     * @param string $external_id External ID.
     * @return string
     */
    public static function build_hash(
        int $source_id,
        string $external_id
    ): string {
        return sha1(
            $source_id .
            '|' .
            $external_id
        );
    }

    /**
     * Prepare INSERT data.
     *
     * @param array<string,mixed> $data Raw data.
     * @return array<string,mixed>|false
     */
    private static function prepare_data(
        array $data
    ) {
        if (empty($data['source_id'])) {
            return false;
        }

        if (empty($data['external_id'])) {
            return false;
        }

        if (empty($data['title'])) {
            return false;
        }

        if (empty($data['url'])) {
            return false;
        }

        $source_id = absint(
            $data['source_id']
        );

        $external_id = sanitize_text_field(
            (string) $data['external_id']
        );

        $title = sanitize_text_field(
            (string) $data['title']
        );

        $description = isset(
            $data['description']
        )
            ? (string) $data['description']
            : '';

        $url = esc_url_raw(
            (string) $data['url']
        );

        if (
            $source_id <= 0 ||
            $external_id === '' ||
            $title === '' ||
            $url === ''
        ) {
            return false;
        }

        /*
         * Status.
         */
        $status = isset(
            $data['status']
        )
            ? sanitize_key(
                (string) $data['status']
            )
            : AIJP_Job_Status::FOUND;

        if (
            !AIJP_Job_Status::is_valid(
                $status
            )
        ) {
            $status =
                AIJP_Job_Status::FOUND;
        }

        /*
         * Hash.
         */
        $hash = isset(
            $data['hash']
        )
            ? sanitize_text_field(
                (string) $data['hash']
            )
            : '';

        if ($hash === '') {
            $hash = self::build_hash(
                $source_id,
                $external_id
            );
        }

        /*
         * Found timestamp.
         */
        $found_at = isset(
            $data['found_at']
        )
            ? self::normalize_datetime(
                $data['found_at']
            )
            : current_time(
                'mysql',
                true
            );

        if ($found_at === null) {
            $found_at =
                current_time(
                    'mysql',
                    true
                );
        }

        $now = current_time(
            'mysql',
            true
        );

        return [
            'source_id' =>
                $source_id,

            'external_id' =>
                $external_id,

            'title' =>
                $title,

            'description' =>
                $description,

            'url' =>
                $url,

            'budget_amount' =>
                self::normalize_decimal(
                    $data['budget_amount'] ?? null
                ),

            'budget_currency' =>
                self::normalize_currency(
                    $data['budget_currency'] ?? null
                ),

            'lang' =>
                self::normalize_short_text(
                    $data['lang'] ?? null,
                    10
                ),

            'country' =>
                self::normalize_short_text(
                    $data['country'] ?? null,
                    10
                ),

            'category' =>
                self::normalize_short_text(
                    $data['category'] ?? null,
                    50
                ),

            'published_at' =>
                self::normalize_datetime(
                    $data['published_at'] ?? null
                ),

            'found_at' =>
                $found_at,

            'status' =>
                $status,

            'assignee' =>
                self::normalize_short_text(
                    $data['assignee'] ?? null,
                    30
                ),

            'hash' =>
                $hash,

            'raw_data' =>
                self::normalize_raw_data(
                    $data['raw_data'] ?? null
                ),

            'created_at' =>
                $now,

            'updated_at' =>
                $now,
        ];
    }

    /**
     * Prepare UPDATE data.
     *
     * @param array<string,mixed> $data Data.
     * @return array<string,mixed>|false
     */
    private static function prepare_update_data(
        array $data
    ) {
        $prepared = [];

        foreach ($data as $field => $value) {
            switch ($field) {
                case 'source_id':
                    $prepared[$field] =
                        absint($value);
                    break;

                case 'external_id':
                    $prepared[$field] =
                        sanitize_text_field(
                            (string) $value
                        );
                    break;

                case 'title':
                    $prepared[$field] =
                        sanitize_text_field(
                            (string) $value
                        );
                    break;

                case 'description':
                    $prepared[$field] =
                        (string) $value;
                    break;

                case 'url':
                    $prepared[$field] =
                        esc_url_raw(
                            (string) $value
                        );

                    if (
                        $prepared[$field] === ''
                    ) {
                        return false;
                    }

                    break;

                case 'budget_amount':
                    $prepared[$field] =
                        self::normalize_decimal(
                            $value
                        );
                    break;

                case 'budget_currency':
                    $prepared[$field] =
                        self::normalize_currency(
                            $value
                        );
                    break;

                case 'lang':
                    $prepared[$field] =
                        self::normalize_short_text(
                            $value,
                            10
                        );
                    break;

                case 'country':
                    $prepared[$field] =
                        self::normalize_short_text(
                            $value,
                            10
                        );
                    break;

                case 'category':
                    $prepared[$field] =
                        self::normalize_short_text(
                            $value,
                            50
                        );
                    break;

                case 'published_at':
                case 'found_at':
                    $prepared[$field] =
                        self::normalize_datetime(
                            $value
                        );
                    break;

                case 'status':
                    $status = sanitize_key(
                        (string) $value
                    );

                    if (
                        !AIJP_Job_Status::is_valid(
                            $status
                        )
                    ) {
                        return false;
                    }

                    $prepared[$field] =
                        $status;
                    break;

                case 'assignee':
                    $prepared[$field] =
                        self::normalize_short_text(
                            $value,
                            30
                        );
                    break;

                case 'hash':
                    $prepared[$field] =
                        sanitize_text_field(
                            (string) $value
                        );
                    break;

                case 'raw_data':
                    $prepared[$field] =
                        self::normalize_raw_data(
                            $value
                        );
                    break;
            }
        }

        return $prepared;
    }

    /**
     * Get wpdb formats for job data.
     *
     * @param array<string,mixed> $data Data.
     * @return array<int,string>
     */
    private static function get_formats(
        array $data
    ): array {
        $formats = [];

        foreach ($data as $field => $value) {
            if ($value === null) {
                $formats[] = '%s';
                continue;
            }

            switch ($field) {
                case 'source_id':
                    $formats[] = '%d';
                    break;

                case 'budget_amount':
                    $formats[] = '%f';
                    break;

                default:
                    $formats[] = '%s';
                    break;
            }
        }

        return $formats;
    }

    /**
     * Get wpdb formats for analysis data.
     *
     * @param array<string,mixed> $data Analysis data.
     * @return array<int,string>
     */
    private static function get_analysis_formats(
        array $data
    ): array {
        $formats = [];

        foreach ($data as $field => $value) {
            if ($value === null) {
                $formats[] = '%s';
                continue;
            }

            switch ($field) {
                case 'job_id':
                    $formats[] = '%d';
                    break;

                case 'complexity':
                    $formats[] = '%d';
                    break;

                case 'estimated_hours':
                case 'confidence':
                    $formats[] = '%f';
                    break;

                default:
                    $formats[] = '%s';
                    break;
            }
        }

        return $formats;
    }

    /**
     * Normalize decimal value.
     *
     * @param mixed $value Value.
     * @return float|null
     */
    private static function normalize_decimal(
        $value
    ) {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round(
            (float) $value,
            2
        );
    }

    /**
     * Normalize currency.
     *
     * @param mixed $value Currency.
     * @return string|null
     */
    private static function normalize_currency(
        $value
    ) {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        $value = strtoupper(
            sanitize_text_field(
                (string) $value
            )
        );

        return substr(
            $value,
            0,
            3
        );
    }

    /**
     * Normalize short text.
     *
     * @param mixed $value  Value.
     * @param int   $length Maximum length.
     * @return string|null
     */
    private static function normalize_short_text(
        $value,
        int $length
    ) {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        $value = sanitize_text_field(
            (string) $value
        );

        if ($value === '') {
            return null;
        }

        return substr(
            $value,
            0,
            $length
        );
    }

    /**
     * Normalize raw job data.
     *
     * raw_data is stored as JSON when an array/object is supplied.
     *
     * @param mixed $value Raw data.
     * @return string|null
     */
    private static function normalize_raw_data(
        $value
    ) {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        if (
            is_array($value) ||
            is_object($value)
        ) {
            $json = wp_json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            return $json !== false
                ? $json
                : null;
        }

        return (string) $value;
    }

    /**
     * Normalize an array of strings.
     *
     * @param mixed $value Value.
     * @return array<int,string>
     */
    private static function normalize_string_array(
        $value
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (
                is_scalar($item)
            ) {
                $item = sanitize_textarea_field(
                    (string) $item
                );

                if ($item !== '') {
                    $result[] = $item;
                }
            }
        }

        return array_values(
            $result
        );
    }

    /**
     * Normalize datetime.
     *
     * @param mixed $value Datetime.
     * @return string|null
     */
    private static function normalize_datetime(
        $value
    ) {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
        } else {
            $timestamp = strtotime(
                (string) $value
            );
        }

        if ($timestamp === false) {
            return null;
        }

        return gmdate(
            'Y-m-d H:i:s',
            $timestamp
        );
    }
}
