<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin activation routines.
 */
class AIJP_Activator
{
    /**
     * Activate plugin.
     *
     * @return void
     */
    public static function activate(): void
    {
        require_once AIJP_PLUGIN_PATH . 'includes/class-database.php';
        require_once AIJP_PLUGIN_PATH . 'includes/class-logger.php';
        require_once AIJP_PLUGIN_PATH . 'includes/class-settings.php';
        require_once AIJP_PLUGIN_PATH . 'includes/class-cron.php';
        require_once AIJP_PLUGIN_PATH . 'includes/class-roles.php';

        /*
         * Database must exist before the rest of the plugin starts using it.
         */
        AIJP_Database::install();

        /*
         * Roles/capabilities are persistent WordPress data.
         * We create/update them on activation.
         *
         * IMPORTANT:
         * They are NOT removed during deactivation.
         */
        AIJP_Roles::install();

        /*
         * Schedule cron.
         */
        $cron = new AIJP_Cron();

        $cron->register();
        $cron->schedule();
    }
}
