<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database management.
 *
 * This class is the single source of truth for plugin database schema.
 *
 * Canonical source columns:
 *
 * - source_type
 * - enabled
 * - country
 * - language
 *
 * Canonical job columns:
 *
 * - url
 * - budget_amount
 * - budget_currency
 * - lang
 * - found_at
 * - hash
 * - assignee
 *
 * Older installations may still contain legacy aliases such as:
 *
 * - type
 * - is_active
 * - original_url
 * - language
 * - budget_min
 * - budget_max
 * - currency
 * - imported_at
 *
 * Migrations copy legacy data into the canonical columns without
 * destructively dropping the old columns.
 */
class AIJP_Database
{
    /**
     * Current database schema version.
     *
     * Bumping this version forces maybe_upgrade() to run migrations.
     */
    public const DB_VERSION = '0.5.0';

    /**
     * Option storing installed database version.
     */
    private const VERSION_OPTION =
        'aijp_db_version';

    /**
     * Get plugin table name.
     *
     * @param string $name Table suffix.
     *
     * @return string
     */
    public static function table(
        string $name
    ): string {
        global $wpdb;

        return $wpdb->prefix .
            'aijp_' .
            $name;
    }

    /**
     * Install or upgrade database.
     *
     * @return void
     */
    public static function install(): void
    {
        self::create_tables();

        update_option(
            self::VERSION_OPTION,
            self::DB_VERSION,
            false
        );
    }

    /**
     * Upgrade database when schema version changes.
     *
     * @return void
     */
    public static function maybe_upgrade(): void
    {
        $installed_version =
            (string) get_option(
                self::VERSION_OPTION,
                ''
            );

        if (
            $installed_version ===
            self::DB_VERSION
        ) {
            return;
        }

        self::install();
    }

    /**
     * Create or upgrade all plugin tables.
     *
     * @return void
     */
    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH .
            'wp-admin/includes/upgrade.php';

        $charset_collate =
            $wpdb->get_charset_collate();

        $sources_table =
            self::table(
                'sources'
            );

        $jobs_table =
            self::table(
                'jobs'
            );

        $analyses_table =
            self::table(
                'analyses'
            );

        $logs_table =
            self::table(
                'logs'
            );

        $usage_table =
            self::table(
                'ai_usage'
            );

        $proposals_table =
            self::table(
                'proposals'
            );

        /*
         * Sources.
         *
         * These names match AIJP_Source_Repository.
         */
        $sql_sources = "
            CREATE TABLE {$sources_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                name varchar(191) NOT NULL,

                url text NOT NULL,

                country varchar(100) NULL,

                language varchar(50) NULL,

                source_type varchar(50) NOT NULL DEFAULT 'RSS',

                enabled tinyint(1) NOT NULL DEFAULT 1,

                config longtext NULL,

                last_import_at datetime NULL,

                last_error longtext NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                KEY source_type (source_type),

                KEY enabled (enabled)
            ) {$charset_collate};
        ";

        /*
         * Jobs.
         *
         * These are the canonical database field names used by:
         *
         * - AIJP_Job_Repository
         * - AIJP_RSS_Importer
         * - AI pipeline
         * - admin views
         */
        $sql_jobs = "
            CREATE TABLE {$jobs_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                source_id bigint(20) unsigned NULL,

                external_id varchar(191) NULL,

                title text NOT NULL,

                description longtext NULL,

                url text NULL,

                budget_amount decimal(14,2) NULL,

                budget_currency varchar(20) NULL,

                lang varchar(50) NULL,

                country varchar(100) NULL,

                category varchar(100) NULL,

                published_at datetime NULL,

                found_at datetime NULL,

                status varchar(50) NOT NULL DEFAULT 'found',

                assignee varchar(191) NULL,

                hash varchar(40) NULL,

                raw_data longtext NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                KEY source_id (source_id),

                KEY external_id (external_id),

                KEY status (status),

                KEY hash (hash),

                KEY published_at (published_at),

                KEY found_at (found_at),

                KEY status_created (status, created_at),

                KEY source_status (source_id, status)
            ) {$charset_collate};
        ";

        /*
         * AI analyses.
         *
         * ai_feasible MUST remain a string because the AI contract is:
         *
         * yes | partial | no
         *
         * A tinyint cannot represent "partial".
         */
        $sql_analyses = "
            CREATE TABLE {$analyses_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                job_id bigint(20) unsigned NOT NULL,

                summary longtext NULL,

                category varchar(100) NULL,

                complexity tinyint(3) unsigned NULL,

                ai_feasible varchar(20) NULL,

                ai_feasible_reason longtext NULL,

                estimated_hours decimal(10,2) NULL,

                recommendation varchar(50) NULL,

                confidence decimal(5,4) NULL,

                plan longtext NULL,

                red_flags longtext NULL,

                provider varchar(100) NULL,

                model varchar(191) NULL,

                prompt_version varchar(100) NULL,

                raw_response longtext NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                UNIQUE KEY job_id (job_id),

                KEY recommendation (recommendation),

                KEY ai_feasible (ai_feasible),

                KEY updated_at (updated_at)
            ) {$charset_collate};
        ";

        /*
         * Logs.
         */
        $sql_logs = "
            CREATE TABLE {$logs_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                level varchar(20) NOT NULL DEFAULT 'info',

                message longtext NOT NULL,

                context longtext NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                KEY level (level),

                KEY created_at (created_at)
            ) {$charset_collate};
        ";

        /*
         * AI usage.
         */
        $sql_usage = "
            CREATE TABLE {$usage_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                job_id bigint(20) unsigned NULL,

                operation varchar(100) NOT NULL DEFAULT 'job_analysis',

                provider varchar(100) NULL,

                model varchar(191) NULL,

                request_id varchar(191) NULL,

                prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,

                completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,

                total_tokens bigint(20) unsigned NOT NULL DEFAULT 0,

                estimated_cost decimal(14,8) NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                KEY job_id (job_id),

                KEY operation (operation),

                KEY model (model),

                KEY created_at (created_at)
            ) {$charset_collate};
        ";

        /*
         * Proposals (відгуки, Етап 5 ТЗ).
         *
         * A job may have several proposal drafts over time (e.g. a
         * regenerated draft after the first one was skipped), so this
         * is a one-to-many relationship to jobs, unlike analyses which
         * are one-to-one.
         */
        $sql_proposals = \"
            CREATE TABLE {$proposals_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

                job_id bigint(20) unsigned NOT NULL,

                lang varchar(20) NULL,

                text longtext NULL,

                status varchar(20) NOT NULL DEFAULT 'draft',

                source varchar(20) NOT NULL DEFAULT 'ai',

                provider varchar(100) NULL,

                model varchar(191) NULL,

                prompt_version varchar(100) NULL,

                sent_at datetime NULL,

                client_reply longtext NULL,

                client_replied_at datetime NULL,

                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY  (id),

                KEY job_id (job_id),

                KEY status (status)
            ) {$charset_collate};
        \";

        dbDelta(
            $sql_sources
        );

        dbDelta(
            $sql_jobs
        );

        dbDelta(
            $sql_analyses
        );

        dbDelta(
            $sql_logs
        );

        dbDelta(
            $sql_usage
        );

        dbDelta(
            $sql_proposals
        );

        /*
         * Explicit migrations are required because dbDelta adds
         * columns but does not reliably rename old schema fields.
         */
        self::migrate_sources_table(
            $sources_table
        );

        self::migrate_jobs_table(
            $jobs_table
        );

        self::migrate_analyses_table(
            $analyses_table
        );

        self::migrate_usage_table(
            $usage_table
        );
    }

    /**
     * Migrate sources table to canonical column names.
     *
     * @param string $table Table name.
     *
     * @return void
     */
    private static function migrate_sources_table(
        string $table
    ): void {
        global $wpdb;

        $columns =
            self::get_columns(
                $table
            );

        $required = [
            'country' =>
                'varchar(100) NULL',

            'language' =>
                'varchar(50) NULL',

            'source_type' =>
                "varchar(50) NOT NULL DEFAULT 'RSS'",

            'enabled' =>
                'tinyint(1) NOT NULL DEFAULT 1',

            'config' =>
                'longtext NULL',

            'last_import_at' =>
                'datetime NULL',

            'last_error' =>
                'longtext NULL',

            'created_at' =>
                'datetime NULL',

            'updated_at' =>
                'datetime NULL',
        ];

        foreach (
            $required
            as $column => $definition
        ) {
            if (isset($columns[$column])) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$table}
                ADD COLUMN {$column} {$definition}"
            );
        }

        /*
         * Refresh column list after additions.
         */
        $columns =
            self::get_columns(
                $table
            );

        /*
         * Legacy:
         *
         * type -> source_type
         */
        if (
            isset($columns['type']) &&
            isset($columns['source_type'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET source_type =
                    CASE
                        WHEN type IS NULL OR TRIM(type) = ''
                            THEN 'RSS'
                        ELSE UPPER(TRIM(type))
                    END
                WHERE source_type IS NULL
                   OR TRIM(source_type) = ''
                   OR source_type = 'RSS'"
            );
        }

        /*
         * Legacy:
         *
         * is_active -> enabled
         */
        if (
            isset($columns['is_active']) &&
            isset($columns['enabled'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET enabled =
                    CASE
                        WHEN is_active = 1 THEN 1
                        ELSE 0
                    END"
            );
        }

        /*
         * Normalize source type.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET source_type = 'RSS'
            WHERE source_type IS NULL
               OR TRIM(source_type) = ''"
        );

        /*
         * Backfill timestamps for legacy rows.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET created_at = UTC_TIMESTAMP()
            WHERE created_at IS NULL"
        );

        $wpdb->query(
            "UPDATE {$table}
            SET updated_at = created_at
            WHERE updated_at IS NULL"
        );

        self::ensure_index(
            $table,
            'source_type',
            'source_type'
        );

        self::ensure_index(
            $table,
            'enabled',
            'enabled'
        );
    }

    /**
     * Migrate jobs table to canonical repository column names.
     *
     * Legacy columns are intentionally not deleted.
     *
     * @param string $table Table name.
     *
     * @return void
     */
    private static function migrate_jobs_table(
        string $table
    ): void {
        global $wpdb;

        $columns =
            self::get_columns(
                $table
            );

        $required = [
            'url' =>
                'text NULL',

            'budget_amount' =>
                'decimal(14,2) NULL',

            'budget_currency' =>
                'varchar(20) NULL',

            'lang' =>
                'varchar(50) NULL',

            'country' =>
                'varchar(100) NULL',

            'category' =>
                'varchar(100) NULL',

            'published_at' =>
                'datetime NULL',

            'found_at' =>
                'datetime NULL',

            'status' =>
                "varchar(50) NOT NULL DEFAULT 'found'",

            'assignee' =>
                'varchar(191) NULL',

            'hash' =>
                'varchar(40) NULL',

            'raw_data' =>
                'longtext NULL',

            'created_at' =>
                'datetime NULL',

            'updated_at' =>
                'datetime NULL',
        ];

        foreach (
            $required
            as $column => $definition
        ) {
            if (isset($columns[$column])) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$table}
                ADD COLUMN {$column} {$definition}"
            );
        }

        $columns =
            self::get_columns(
                $table
            );

        /*
         * Legacy:
         *
         * original_url -> url
         */
        if (
            isset($columns['original_url']) &&
            isset($columns['url'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET url = original_url
                WHERE
                    (url IS NULL OR TRIM(url) = '')
                    AND original_url IS NOT NULL
                    AND TRIM(original_url) <> ''"
            );
        }

        /*
         * Legacy:
         *
         * language -> lang
         */
        if (
            isset($columns['language']) &&
            isset($columns['lang'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET lang = language
                WHERE
                    (lang IS NULL OR TRIM(lang) = '')
                    AND language IS NOT NULL
                    AND TRIM(language) <> ''"
            );
        }

        /*
         * Legacy:
         *
         * currency -> budget_currency
         */
        if (
            isset($columns['currency']) &&
            isset($columns['budget_currency'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET budget_currency = currency
                WHERE
                    (
                        budget_currency IS NULL
                        OR TRIM(budget_currency) = ''
                    )
                    AND currency IS NOT NULL
                    AND TRIM(currency) <> ''"
            );
        }

        /*
         * The current repository supports a single budget_amount.
         *
         * Prefer budget_min. If it is absent, use budget_max.
         */
        if (
            isset($columns['budget_min']) &&
            isset($columns['budget_amount'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET budget_amount = budget_min
                WHERE budget_amount IS NULL
                  AND budget_min IS NOT NULL"
            );
        }

        if (
            isset($columns['budget_max']) &&
            isset($columns['budget_amount'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET budget_amount = budget_max
                WHERE budget_amount IS NULL
                  AND budget_max IS NOT NULL"
            );
        }

        /*
         * Legacy:
         *
         * imported_at -> found_at
         */
        if (
            isset($columns['imported_at']) &&
            isset($columns['found_at'])
        ) {
            $wpdb->query(
                "UPDATE {$table}
                SET found_at = imported_at
                WHERE found_at IS NULL
                  AND imported_at IS NOT NULL"
            );
        }

        /*
         * Ensure FOUND date always exists for imported jobs.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET found_at = UTC_TIMESTAMP()
            WHERE found_at IS NULL"
        );

        /*
         * Generate deterministic hashes for legacy records.
         *
         * The PHP repository uses:
         *
         * sha1(source_id . '|' . external_id)
         *
         * MySQL SHA1() produces the same lowercase hexadecimal form.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET hash = SHA1(
                CONCAT(
                    COALESCE(source_id, 0),
                    '|',
                    COALESCE(external_id, '')
                )
            )
            WHERE
                (hash IS NULL OR TRIM(hash) = '')
                AND external_id IS NOT NULL
                AND TRIM(external_id) <> ''"
        );

        /*
         * Backfill timestamps.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET created_at =
                COALESCE(
                    found_at,
                    published_at,
                    UTC_TIMESTAMP()
                )
            WHERE created_at IS NULL"
        );

        $wpdb->query(
            "UPDATE {$table}
            SET updated_at = created_at
            WHERE updated_at IS NULL"
        );

        self::ensure_index(
            $table,
            'source_id',
            'source_id'
        );

        self::ensure_index(
            $table,
            'external_id',
            'external_id'
        );

        self::ensure_index(
            $table,
            'status',
            'status'
        );

        self::ensure_index(
            $table,
            'hash',
            'hash'
        );

        self::ensure_index(
            $table,
            'published_at',
            'published_at'
        );

        self::ensure_index(
            $table,
            'found_at',
            'found_at'
        );
    }

    /**
     * Upgrade analyses table.
     *
     * @param string $table Table name.
     *
     * @return void
     */
    private static function migrate_analyses_table(
        string $table
    ): void {
        global $wpdb;

        $columns =
            self::get_columns(
                $table
            );

        $required = [
            'summary' =>
                'longtext NULL',

            'category' =>
                'varchar(100) NULL',

            'complexity' =>
                'tinyint(3) unsigned NULL',

            'ai_feasible' =>
                'varchar(20) NULL',

            'ai_feasible_reason' =>
                'longtext NULL',

            'estimated_hours' =>
                'decimal(10,2) NULL',

            'recommendation' =>
                'varchar(50) NULL',

            'confidence' =>
                'decimal(5,4) NULL',

            'plan' =>
                'longtext NULL',

            'red_flags' =>
                'longtext NULL',

            'provider' =>
                'varchar(100) NULL',

            'model' =>
                'varchar(191) NULL',

            'prompt_version' =>
                'varchar(100) NULL',

            'raw_response' =>
                'longtext NULL',

            'created_at' =>
                'datetime NULL',

            'updated_at' =>
                'datetime NULL',
        ];

        foreach (
            $required
            as $column => $definition
        ) {
            if (isset($columns[$column])) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$table}
                ADD COLUMN {$column} {$definition}"
            );
        }

        $columns =
            self::get_columns(
                $table
            );

        /*
         * Convert legacy boolean ai_feasible into the canonical enum:
         *
         * 1 -> yes
         * 0 -> no
         *
         * "partial" is preserved when the previous column already
         * contained strings.
         */
        if (isset($columns['ai_feasible'])) {
            $column_type =
                strtolower(
                    (string) (
                        $columns['ai_feasible']->Type
                        ?? ''
                    )
                );

            if (
                !str_contains(
                    $column_type,
                    'varchar'
                )
            ) {
                $wpdb->query(
                    "ALTER TABLE {$table}
                    MODIFY ai_feasible varchar(20) NULL"
                );

                $wpdb->query(
                    "UPDATE {$table}
                    SET ai_feasible =
                        CASE
                            WHEN ai_feasible = '1'
                                THEN 'yes'
                            WHEN ai_feasible = '0'
                                THEN 'no'
                            WHEN LOWER(ai_feasible) = 'partial'
                                THEN 'partial'
                            WHEN LOWER(ai_feasible) = 'yes'
                                THEN 'yes'
                            ELSE 'no'
                        END"
                );
            } else {
                $wpdb->query(
                    "UPDATE {$table}
                    SET ai_feasible =
                        CASE
                            WHEN LOWER(TRIM(ai_feasible))
                                IN ('1', 'true', 'yes', 'y', 'on')
                                THEN 'yes'

                            WHEN LOWER(TRIM(ai_feasible)) = 'partial'
                                THEN 'partial'

                            ELSE 'no'
                        END
                    WHERE ai_feasible IS NOT NULL
                      AND TRIM(ai_feasible) <> ''"
                );
            }
        }

        /*
         * Normalize complexity as integer 1-5.
         */
        if (isset($columns['complexity'])) {
            $wpdb->query(
                "UPDATE {$table}
                SET complexity =
                    CASE
                        WHEN CAST(complexity AS UNSIGNED) < 1
                            THEN 1
                        WHEN CAST(complexity AS UNSIGNED) > 5
                            THEN 5
                        ELSE CAST(complexity AS UNSIGNED)
                    END
                WHERE complexity IS NOT NULL
                  AND complexity <> ''"
            );

            $wpdb->query(
                "ALTER TABLE {$table}
                MODIFY complexity tinyint(3) unsigned NULL"
            );
        }

        /*
         * Backfill timestamps.
         */
        $wpdb->query(
            "UPDATE {$table}
            SET created_at = UTC_TIMESTAMP()
            WHERE created_at IS NULL"
        );

        $wpdb->query(
            "UPDATE {$table}
            SET updated_at = created_at
            WHERE updated_at IS NULL"
        );

        /*
         * Ensure a single current analysis exists per job.
         */
        if (
            !self::has_index(
                $table,
                'job_id',
                true
            )
        ) {
            $wpdb->query(
                "DELETE older
                FROM {$table} AS older
                INNER JOIN {$table} AS newer
                    ON older.job_id = newer.job_id
                    AND older.id < newer.id"
            );

            $wpdb->query(
                "ALTER TABLE {$table}
                ADD UNIQUE KEY job_id (job_id)"
            );
        }

        self::ensure_index(
            $table,
            'recommendation',
            'recommendation'
        );

        self::ensure_index(
            $table,
            'ai_feasible',
            'ai_feasible'
        );

        self::ensure_index(
            $table,
            'updated_at',
            'updated_at'
        );
    }

    /**
     * Upgrade AI usage table.
     *
     * @param string $table Table name.
     *
     * @return void
     */
    private static function migrate_usage_table(
        string $table
    ): void {
        global $wpdb;

        $columns =
            self::get_columns(
                $table
            );

        $required = [
            'job_id' =>
                'bigint(20) unsigned NULL',

            'operation' =>
                "varchar(100) NOT NULL DEFAULT 'job_analysis'",

            'provider' =>
                'varchar(100) NULL',

            'model' =>
                'varchar(191) NULL',

            'request_id' =>
                'varchar(191) NULL',

            'prompt_tokens' =>
                'bigint(20) unsigned NOT NULL DEFAULT 0',

            'completion_tokens' =>
                'bigint(20) unsigned NOT NULL DEFAULT 0',

            'total_tokens' =>
                'bigint(20) unsigned NOT NULL DEFAULT 0',

            'estimated_cost' =>
                'decimal(14,8) NULL',

            'created_at' =>
                'datetime NULL',
        ];

        foreach (
            $required
            as $column => $definition
        ) {
            if (isset($columns[$column])) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$table}
                ADD COLUMN {$column} {$definition}"
            );
        }

        $wpdb->query(
            "UPDATE {$table}
            SET operation = 'job_analysis'
            WHERE operation IS NULL
               OR TRIM(operation) = ''"
        );

        $wpdb->query(
            "UPDATE {$table}
            SET created_at = UTC_TIMESTAMP()
            WHERE created_at IS NULL"
        );

        self::ensure_index(
            $table,
            'job_id',
            'job_id'
        );

        self::ensure_index(
            $table,
            'operation',
            'operation'
        );

        self::ensure_index(
            $table,
            'model',
            'model'
        );

        self::ensure_index(
            $table,
            'created_at',
            'created_at'
        );
    }

    /**
     * Get columns for a table.
     *
     * @param string $table Table name.
     *
     * @return array<string,object>
     */
    private static function get_columns(
        string $table
    ): array {
        global $wpdb;

        $results =
            $wpdb->get_results(
                "SHOW COLUMNS FROM {$table}"
            );

        if (!is_array($results)) {
            return [];
        }

        $columns = [];

        foreach ($results as $column) {
            if (empty($column->Field)) {
                continue;
            }

            $columns[
                (string) $column->Field
            ] = $column;
        }

        return $columns;
    }

    /**
     * Ensure a non-unique index exists.
     *
     * @param string $table Table name.
     * @param string $name Index name.
     * @param string $column Column name.
     *
     * @return void
     */
    private static function ensure_index(
        string $table,
        string $name,
        string $column
    ): void {
        global $wpdb;

        if (
            self::has_index(
                $table,
                $name
            )
        ) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table}
            ADD KEY {$name} ({$column})"
        );
    }

    /**
     * Check whether an index exists.
     *
     * @param string $table Table name.
     * @param string $name Index name.
     * @param bool   $unique Require unique index.
     *
     * @return bool
     */
    private static function has_index(
        string $table,
        string $name,
        bool $unique = false
    ): bool {
        global $wpdb;

        $indexes =
            $wpdb->get_results(
                "SHOW INDEX FROM {$table}"
            );

        if (!is_array($indexes)) {
            return false;
        }

        foreach ($indexes as $index) {
            if (
                !isset($index->Key_name) ||
                (string) $index->Key_name !==
                $name
            ) {
                continue;
            }

            if (
                $unique &&
                isset($index->Non_unique) &&
                (int) $index->Non_unique !== 0
            ) {
                continue;
            }

            return true;
        }

        return false;
    }
}