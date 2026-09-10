<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Source repository.
 *
 * Single responsibility:
 * - read sources;
 * - create sources;
 * - update sources;
 * - delete sources;
 * - provide enabled sources for importers.
 *
 * Canonical database fields:
 *
 * id
 * name
 * url
 * country
 * language
 * source_type
 * enabled
 * config
 * last_import_at
 * last_error
 * created_at
 * updated_at
 */
class AIJP_Source_Repository
{
    /**
     * Maximum number of sources returned by one request.
     */
    private const MAX_LIMIT = 500;

    /**
     * Supported source types.
     *
     * Keep this list small and explicit. New source adapters can be
     * added later without allowing arbitrary values into the database.
     *
     * @return array<int,string>
     */
    public static function get_types(): array
    {
        return [
            'RSS',
            'API',
        ];
    }

    /**
     * Get all sources.
     *
     * @param array<string,mixed> $filters Optional filters.
     *
     * Supported:
     * - enabled
     * - source_type
     * - limit
     * - offset
     *
     * @return array<int,object>
     */
    public static function get_all(
        array $filters = []
    ): array {
        global $wpdb;

        $table = AIJP_Database::table(
            'sources'
        );

        $where  = [];
        $values = [];

        /*
         * Enabled filter.
         */
        if (
            array_key_exists(
                'enabled',
                $filters
            )
        ) {
            $enabled = !empty(
                $filters['enabled']
            )
                ? 1
                : 0;

            $where[]  = 'enabled = %d';
            $values[] = $enabled;
        }

        /*
         * Source type filter.
         */
        if (
            !empty($filters['source_type'])
        ) {
            $source_type =
                self::normalize_source_type(
                    $filters['source_type']
                );

            $where[] =
                'UPPER(TRIM(source_type)) = %s';

            $values[] = $source_type;
        }

        /*
         * Limit.
         */
        $limit = isset(
            $filters['limit']
        )
            ? absint(
                $filters['limit']
            )
            : self::MAX_LIMIT;

        if ($limit <= 0) {
            $limit = self::MAX_LIMIT;
        }

        $limit = min(
            $limit,
            self::MAX_LIMIT
        );

        /*
         * Offset.
         */
        $offset = isset(
            $filters['offset']
        )
            ? absint(
                $filters['offset']
            )
            : 0;

        $sql = "
            SELECT *
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

        $sql .= "
            ORDER BY id DESC
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
     * Get one source by ID.
     *
     * @param int $id Source ID.
     *
     * @return object|null
     */
    public static function get(int $id)
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        return $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE id = %d
                LIMIT 1
                ",
                $id
            )
        );
    }

    /**
     * Backwards-compatible find() alias.
     *
     * @param int $id Source ID.
     *
     * @return object|null
     */
    public static function find(int $id)
    {
        return self::get($id);
    }

    /**
     * Get enabled sources of a specific type.
     *
     * Comparison is case-insensitive.
     *
     * @param string $source_type Source type.
     *
     * @return array<int,object>
     */
    public static function get_enabled_by_type(
        string $source_type
    ): array {
        global $wpdb;

        $source_type =
            self::normalize_source_type(
                $source_type
            );

        if ($source_type === '') {
            return [];
        }

        $table = AIJP_Database::table(
            'sources'
        );

        return $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE enabled = 1
                  AND UPPER(TRIM(source_type)) = %s
                ORDER BY id ASC
                ",
                $source_type
            )
        );
    }

    /**
     * Get all enabled sources.
     *
     * @return array<int,object>
     */
    public static function get_enabled(): array
    {
        global $wpdb;

        $table = AIJP_Database::table(
            'sources'
        );

        return $wpdb->get_results(
            "
            SELECT *
            FROM {$table}
            WHERE enabled = 1
            ORDER BY id ASC
            "
        );
    }

    /**
     * Insert a source.
     *
     * @param array<string,mixed> $data Source data.
     *
     * @return int|false Inserted source ID or false.
     */
    public static function insert(
        array $data
    ) {
        global $wpdb;

        $table = AIJP_Database::table(
            'sources'
        );

        $prepared =
            self::prepare_data(
                $data
            );

        if ($prepared === false) {
            return false;
        }

        /*
         * Do not silently create duplicate source records.
         *
         * URL is the natural identifier for an RSS/API endpoint.
         */
        $existing =
            self::find_by_url(
                $prepared['url'],
                $prepared['source_type']
            );

        if ($existing) {
            return (int) $existing->id;
        }

        $now = current_time(
            'mysql',
            true
        );

        $prepared['created_at'] = $now;
        $prepared['updated_at'] = $now;

        $formats =
            self::get_formats(
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
     * Update a source.
     *
     * This is a full canonical update. Missing optional values are
     * normalized to empty/null values rather than leaving unknown
     * database fields untouched.
     *
     * @param int                  $id   Source ID.
     * @param array<string,mixed>  $data Source data.
     *
     * @return bool
     */
    public static function update(
        int $id,
        array $data
    ): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $existing = self::get(
            $id
        );

        if (!$existing) {
            return false;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        /*
         * Merge with existing values so a caller can safely perform
         * a partial update.
         */
        $merged = [
            'name' =>
                $data['name']
                ?? ($existing->name ?? ''),

            'url' =>
                $data['url']
                ?? ($existing->url ?? ''),

            'country' =>
                $data['country']
                ?? ($existing->country ?? ''),

            'language' =>
                $data['language']
                ?? ($existing->language ?? ''),

            'source_type' =>
                $data['source_type']
                ?? ($existing->source_type ?? 'RSS'),

            'enabled' =>
                array_key_exists(
                    'enabled',
                    $data
                )
                    ? $data['enabled']
                    : ($existing->enabled ?? 0),

            'config' =>
                array_key_exists(
                    'config',
                    $data
                )
                    ? $data['config']
                    : ($existing->config ?? null),
        ];

        $prepared =
            self::prepare_data(
                $merged
            );

        if ($prepared === false) {
            return false;
        }

        /*
         * Preserve configuration as JSON/text.
         */
        if (
            array_key_exists(
                'config',
                $merged
            )
        ) {
            $prepared['config'] =
                self::normalize_config(
                    $merged['config']
                );
        }

        /*
         * Prevent the same URL from being assigned to another
         * source of the same type.
         */
        $duplicate =
            self::find_by_url(
                $prepared['url'],
                $prepared['source_type'],
                $id
            );

        if ($duplicate) {
            return false;
        }

        $prepared['updated_at'] =
            current_time(
                'mysql',
                true
            );

        /*
         * Keep operational fields untouched:
         *
         * last_import_at
         * last_error
         *
         * They are maintained by the importer layer.
         */

        $formats =
            self::get_formats(
                $prepared
            );

        $result = $wpdb->update(
            $table,
            $prepared,
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
     * Update only source operational state.
     *
     * This avoids making importers write directly to the database.
     *
     * @param int         $id           Source ID.
     * @param string|null $last_import_at Last import timestamp.
     * @param string|null $last_error    Last error message.
     *
     * @return bool
     */
    public static function update_import_state(
        int $id,
        ?string $last_import_at = null,
        ?string $last_error = null
    ): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        $data = [
            'updated_at' =>
                current_time(
                    'mysql',
                    true
                ),
        ];

        $formats = [
            '%s',
        ];

        if ($last_import_at !== null) {
            $normalized =
                self::normalize_datetime(
                    $last_import_at
                );

            if ($normalized !== null) {
                $data['last_import_at'] =
                    $normalized;

                $formats[] = '%s';
            }
        }

        if ($last_error !== null) {
            $data['last_error'] =
                sanitize_textarea_field(
                    $last_error
                );

            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $data,
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
     * Clear the last source error.
     *
     * @param int $id Source ID.
     *
     * @return bool
     */
    public static function clear_error(
        int $id
    ): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        $result = $wpdb->update(
            $table,
            [
                'last_error' =>
                    null,

                'updated_at' =>
                    current_time(
                        'mysql',
                        true
                    ),
            ],
            [
                'id' => $id,
            ],
            [
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $result !== false;
    }

    /**
     * Delete a source.
     *
     * Jobs are intentionally not deleted here.
     *
     * Foreign-key/cascade behavior belongs to the database schema.
     *
     * @param int $id Source ID.
     *
     * @return bool
     */
    public static function delete(
        int $id
    ): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        $deleted = $wpdb->delete(
            $table,
            [
                'id' => $id,
            ],
            [
                '%d',
            ]
        );

        return $deleted !== false;
    }

    /**
     * Enable or disable a source.
     *
     * @param int  $id      Source ID.
     * @param bool $enabled Enabled state.
     *
     * @return bool
     */
    public static function set_enabled(
        int $id,
        bool $enabled
    ): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        $updated = $wpdb->update(
            $table,
            [
                'enabled' =>
                    $enabled ? 1 : 0,

                'updated_at' =>
                    current_time(
                        'mysql',
                        true
                    ),
            ],
            [
                'id' => $id,
            ],
            [
                '%d',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Find source by URL and type.
     *
     * @param string   $url         Source URL.
     * @param string   $source_type Source type.
     * @param int|null $exclude_id  ID to exclude.
     *
     * @return object|null
     */
    public static function find_by_url(
        string $url,
        string $source_type = 'RSS',
        ?int $exclude_id = null
    ) {
        global $wpdb;

        $url = esc_url_raw(
            trim($url)
        );

        $source_type =
            self::normalize_source_type(
                $source_type
            );

        if (
            $url === '' ||
            $source_type === ''
        ) {
            return null;
        }

        $table = AIJP_Database::table(
            'sources'
        );

        if (
            $exclude_id !== null &&
            $exclude_id > 0
        ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "
                    SELECT *
                    FROM {$table}
                    WHERE url = %s
                      AND UPPER(TRIM(source_type)) = %s
                      AND id != %d
                    LIMIT 1
                    ",
                    $url,
                    $source_type,
                    $exclude_id
                )
            );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE url = %s
                  AND UPPER(TRIM(source_type)) = %s
                LIMIT 1
                ",
                $url,
                $source_type
            )
        );
    }

    /**
     * Count sources.
     *
     * @param array<string,mixed> $filters Filters.
     *
     * @return int
     */
    public static function count(
        array $filters = []
    ): int {
        global $wpdb;

        $table = AIJP_Database::table(
            'sources'
        );

        $where  = [];
        $values = [];

        if (
            array_key_exists(
                'enabled',
                $filters
            )
        ) {
            $where[] =
                'enabled = %d';

            $values[] =
                !empty(
                    $filters['enabled']
                )
                    ? 1
                    : 0;
        }

        if (
            !empty($filters['source_type'])
        ) {
            $source_type =
                self::normalize_source_type(
                    $filters['source_type']
                );

            $where[] =
                'UPPER(TRIM(source_type)) = %s';

            $values[] =
                $source_type;
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
     * Prepare source data for INSERT/UPDATE.
     *
     * @param array<string,mixed> $data Raw data.
     *
     * @return array<string,mixed>|false
     */
    private static function prepare_data(
        array $data
    ) {
        $name = sanitize_text_field(
            (string) (
                $data['name'] ?? ''
            )
        );

        $url = esc_url_raw(
            trim(
                (string) (
                    $data['url'] ?? ''
                )
            )
        );

        $source_type =
            self::normalize_source_type(
                $data['source_type']
                ?? 'RSS'
            );

        if ($name === '') {
            return false;
        }

        if ($url === '') {
            return false;
        }

        if ($source_type === '') {
            return false;
        }

        /*
         * Only known source adapters are accepted.
         */
        if (
            !in_array(
                $source_type,
                self::get_types(),
                true
            )
        ) {
            return false;
        }

        return [
            'name' =>
                substr(
                    $name,
                    0,
                    191
                ),

            'url' =>
                $url,

            'country' =>
                self::normalize_short_text(
                    $data['country'] ?? null,
                    10
                ),

            'language' =>
                self::normalize_short_text(
                    $data['language'] ?? null,
                    10
                ),

            'source_type' =>
                $source_type,

            'enabled' =>
                !empty(
                    $data['enabled']
                )
                    ? 1
                    : 0,

            'config' =>
                self::normalize_config(
                    $data['config'] ?? null
                ),
        ];
    }

    /**
     * Get wpdb formats for source data.
     *
     * @param array<string,mixed> $data Data.
     *
     * @return array<int,string>
     */
    private static function get_formats(
        array $data
    ): array {
        $formats = [];

        foreach (
            $data
            as $field => $value
        ) {
            switch ($field) {
                case 'enabled':
                    $formats[] = '%d';
                    break;

                default:
                    $formats[] = '%s';
                    break;
            }
        }

        return $formats;
    }

    /**
     * Normalize source type.
     *
     * @param mixed $source_type Source type.
     *
     * @return string
     */
    private static function normalize_source_type(
        mixed $source_type
    ): string {
        if (
            is_array($source_type) ||
            is_object($source_type)
        ) {
            return '';
        }

        $source_type = strtoupper(
            trim(
                sanitize_text_field(
                    (string) $source_type
                )
            )
        );

        if ($source_type === '') {
            return 'RSS';
        }

        return $source_type;
    }

    /**
     * Normalize short text.
     *
     * @param mixed $value  Value.
     * @param int   $length Maximum length.
     *
     * @return string|null
     */
    private static function normalize_short_text(
        mixed $value,
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
     * Normalize config.
     *
     * Arrays/objects are converted to JSON.
     *
     * @param mixed $config Configuration.
     *
     * @return string|null
     */
    private static function normalize_config(
        mixed $config
    ) {
        if (
            $config === null ||
            $config === ''
        ) {
            return null;
        }

        if (
            is_array($config) ||
            is_object($config)
        ) {
            $json = wp_json_encode(
                $config,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            return $json !== false
                ? $json
                : null;
        }

        return (string) $config;
    }

    /**
     * Normalize datetime.
     *
     * @param mixed $value Datetime.
     *
     * @return string|null
     */
    private static function normalize_datetime(
        mixed $value
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