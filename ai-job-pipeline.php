<?php
/**
 * Plugin Name: AI Job Pipeline
 * Plugin URI:  https://example.com/
 * Description: Import, filter, analyze and evaluate freelance jobs with AI.
 * Version:     0.3.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      AI Job Pipeline
 * Text Domain: ai-job-pipeline
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Plugin constants
|--------------------------------------------------------------------------
*/

define(
    'AIJP_VERSION',
    '0.3.1'
);

define(
    'AIJP_PLUGIN_FILE',
    __FILE__
);

define(
    'AIJP_PLUGIN_PATH',
    plugin_dir_path(__FILE__)
);

define(
    'AIJP_PLUGIN_URL',
    plugin_dir_url(__FILE__)
);

define(
    'AIJP_PLUGIN_BASENAME',
    plugin_basename(__FILE__)
);

/*
|--------------------------------------------------------------------------
| Core
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-database.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-logger.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-log-repository.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-settings.php';

/*
|--------------------------------------------------------------------------
| Roles and capabilities
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-roles.php';

/*
|--------------------------------------------------------------------------
| Sources
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-source-repository.php';

/*
|--------------------------------------------------------------------------
| Jobs
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-job-status.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-job-repository.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-rss-importer.php';

require_once AIJP_PLUGIN_PATH . 'includes/class-job-importer.php';

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-api.php';

/*
|--------------------------------------------------------------------------
| Cron
|--------------------------------------------------------------------------
*/

require_once AIJP_PLUGIN_PATH . 'includes/class-cron.php';

/*
|--------------------------------------------------------------------------
| AI infrastructure
|--------------------------------------------------------------------------
*/

/*
 * Low-level AI provider client.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-client.php';

/*
 * Prompt generation.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-prompts.php';

/*
 * AI response validation.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-validator.php';

/*
 * AI service layer.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai.php';

/*
|--------------------------------------------------------------------------
| AI Agent
|--------------------------------------------------------------------------
*/

/*
 * Builds normalized context for agent operations.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-agent-context.php';

/*
 * Central AI workflow orchestration.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-agent.php';

/*
 * Persists AI results and updates job state.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-job-processor.php';

/*
 * Batch runner used by WP-Cron and the manual admin action.
 */
require_once AIJP_PLUGIN_PATH . 'includes/class-ai-job-pipeline-runner.php';

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

if (is_admin()) {
    require_once AIJP_PLUGIN_PATH . 'admin/class-admin.php';
}

/*
|--------------------------------------------------------------------------
| Load translations
|--------------------------------------------------------------------------
*/

/**
 * Load plugin translations.
 *
 * @return void
 */
function aijp_load_textdomain(): void
{
    load_plugin_textdomain(
        'ai-job-pipeline',
        false,
        dirname(
            AIJP_PLUGIN_BASENAME
        ) . '/languages'
    );
}

/*
|--------------------------------------------------------------------------
| Database upgrade
|--------------------------------------------------------------------------
*/

/**
 * Run database migrations when required.
 *
 * @return void
 */
function aijp_maybe_upgrade_database(): void
{
    AIJP_Database::maybe_upgrade();
}

/*
|--------------------------------------------------------------------------
| Initialize cron
|--------------------------------------------------------------------------
*/

/**
 * Register plugin cron hooks.
 *
 * @return void
 */
function aijp_init_cron(): void
{
    $cron = new AIJP_Cron();

    $cron->register();

    /*
     * Self-heal missing events after an update or an interrupted activation.
     */
    $cron->schedule();
}

/**
 * Run one AI processing batch.
 *
 * This callback is shared by WP-Cron and the manual AI Queue form.
 *
 * @param int $batch_size Maximum number of jobs to process.
 *
 * @return array<string,mixed>
 */
function aijp_run_ai_pipeline(int $batch_size = 5): array
{
    $runner = new AIJP_AI_Job_Pipeline_Runner();

    return $runner->run($batch_size);
}

/**
 * Recreate scheduled events after the configured interval changes.
 *
 * @param mixed $old_value Previous settings value.
 * @param mixed $new_value New settings value.
 *
 * @return void
 */
function aijp_reschedule_cron_after_settings_update(
    mixed $old_value,
    mixed $new_value
): void {
    $old_interval = is_array($old_value)
        ? absint($old_value['cron_interval'] ?? 5)
        : 5;

    $new_interval = is_array($new_value)
        ? absint($new_value['cron_interval'] ?? 5)
        : 5;

    if ($old_interval === $new_interval) {
        return;
    }

    $cron = new AIJP_Cron();
    $cron->register();
    $cron->unschedule();
    $cron->schedule();
}

/*
|--------------------------------------------------------------------------
| Initialize settings
|--------------------------------------------------------------------------
*/

/**
 * Register plugin settings with WordPress.
 *
 * @return void
 */
function aijp_init_settings(): void
{
    if (!is_admin()) {
        return;
    }

    $settings = new AIJP_Settings();

    $settings->register();
}

/*
|--------------------------------------------------------------------------
| Initialize admin
|--------------------------------------------------------------------------
*/

/**
 * Initialize WordPress admin integration.
 *
 * @return void
 */
function aijp_init_admin(): void
{
    if (!is_admin()) {
        return;
    }

    $admin = new AIJP_Admin();

    add_action(
        'admin_menu',
        [
            $admin,
            'register_menu',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Plugin initialization
|--------------------------------------------------------------------------
*/

/**
 * Initialize plugin components.
 *
 * @return void
 */
function aijp_init(): void
{
    /*
     * Load translations first.
     */
    aijp_load_textdomain();

    /*
     * Upgrade database schema if required.
     *
     * This must happen before the AI pipeline starts because
     * AI analyses and usage records depend on the latest schema.
     */
    aijp_maybe_upgrade_database();

    /*
     * Synchronize plugin roles and capabilities.
     *
     * AIJP_Roles::install() is designed to be idempotent.
     */
    AIJP_Roles::install();

    /*
     * Register cron hooks.
     *
     * This only registers WordPress actions and does not necessarily
     * execute an import immediately.
     */
    aijp_init_cron();

    /*
     * Register plugin settings.
     *
     * Settings must be registered on admin_init.
     */
    if (is_admin()) {
        add_action(
            'admin_init',
            'aijp_init_settings'
        );
    }

    /*
     * Initialize admin integration.
     */
    if (is_admin()) {
        aijp_init_admin();
    }
}

add_action(
    'plugins_loaded',
    'aijp_init',
    20
);

add_action(
    AIJP_Cron::AI_PIPELINE_HOOK,
    'aijp_run_ai_pipeline',
    10,
    1
);

add_action(
    'update_option_' . AIJP_Settings::OPTION_NAME,
    'aijp_reschedule_cron_after_settings_update',
    10,
    2
);

/*
|--------------------------------------------------------------------------
| Plugin activation
|--------------------------------------------------------------------------
*/

/**
 * Plugin activation handler.
 *
 * @return void
 */
function aijp_activate(): void
{
    /*
     * Create or upgrade all database tables.
     */
    AIJP_Database::install();

    /*
     * Persist defaults on a clean installation. Existing settings are kept.
     */
    add_option(
        AIJP_Settings::OPTION_NAME,
        AIJP_Settings::all(),
        '',
        false
    );

    /*
     * Create and synchronize roles/capabilities.
     */
    AIJP_Roles::install();

    /*
     * Register and schedule cron events.
     */
    $cron = new AIJP_Cron();

    $cron->register();

    $cron->schedule();

    /*
     * Flush rewrite rules after plugin activation.
     */
    flush_rewrite_rules();
}

register_activation_hook(
    AIJP_PLUGIN_FILE,
    'aijp_activate'
);

/*
|--------------------------------------------------------------------------
| Plugin deactivation
|--------------------------------------------------------------------------
*/

/**
 * Plugin deactivation handler.
 *
 * Database tables, settings and roles are intentionally preserved.
 *
 * @return void
 */
function aijp_deactivate(): void
{
    /*
     * Remove all scheduled plugin cron events.
     */
    $cron = new AIJP_Cron();

    $cron->unschedule();

    flush_rewrite_rules();
}

register_deactivation_hook(
    AIJP_PLUGIN_FILE,
    'aijp_deactivate'
);
