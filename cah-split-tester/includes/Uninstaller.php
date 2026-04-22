<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class Uninstaller
{
    public static function run(): void
    {
        $stored = \get_option(Settings::OPTION_KEY, []);
        $dropTables = \is_array($stored) && !empty($stored['drop_tables_on_uninstall']);

        if ($dropTables) {
            self::dropTables();
        }

        \delete_option(Settings::OPTION_KEY);
        \delete_option('cah_split_db_version');
    }

    private static function dropTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            "{$prefix}cah_leads",
            "{$prefix}cah_pageviews",
            "{$prefix}cah_variants",
            "{$prefix}cah_tests",
        ];
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}
