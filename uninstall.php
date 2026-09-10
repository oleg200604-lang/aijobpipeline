<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('aijp_run_automatic_import');
wp_clear_scheduled_hook('aijp_run_ai_pipeline');

delete_option('aijp_ai_pipeline_lock');
