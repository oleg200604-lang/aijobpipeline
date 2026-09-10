<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIJP_Log_Repository
{
    public static function get_all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " .
            AIJP_Database::table('logs') .
            " ORDER BY id DESC"
        );
    }
}