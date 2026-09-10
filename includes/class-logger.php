<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIJP_Logger
{
    public static function log(
        string $level,
        string $message
    ): bool {
        global $wpdb;

        return (bool) $wpdb->insert(
            AIJP_Database::table('logs'),
            [
                'level'      => strtoupper($level),
                'message'    => $message,
                'created_at' => current_time('mysql')
            ],
            [
                '%s',
                '%s',
                '%s'
            ]
        );
    }

    public static function info(string $message): bool
    {
        return self::log('INFO', $message);
    }

    public static function warning(string $message): bool
    {
        return self::log('WARNING', $message);
    }

    public static function error(string $message): bool
    {
        return self::log('ERROR', $message);
    }
}