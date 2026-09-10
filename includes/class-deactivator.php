<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIJP_Deactivator
{
    public static function deactivate(): void
    {
        require_once AIJP_PLUGIN_PATH . 'includes/class-cron.php';

        $cron = new AIJP_Cron();
        $cron->unschedule();
    }
}