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

        // Force-remove our own plugin directory if WP's filesystem abstraction left it behind.
        // WP normally handles this, but on hosts where the filesystem layer runs as a different
        // uid (Hostinger/LiteSpeed in particular) stale files can linger and prevent a clean
        // reinstall. Safe here because we only target our OWN plugin slug.
        self::forceRemovePluginDir();

        \delete_option(Settings::OPTION_KEY);
        \delete_option('cah_split_db_version');
        \delete_option('cah_split_needs_rewrite_flush');
    }

    /**
     * Recursively delete a directory, chmod'ing files/dirs if needed.
     * Returns true if the path no longer exists when the method returns.
     */
    public static function rrmdir(string $path): bool
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return true;
        }

        if (\is_link($path) || \is_file($path)) {
            @\chmod($path, 0644);
            return @\unlink($path) || !\file_exists($path);
        }

        if (!\is_dir($path)) {
            return false;
        }

        $entries = @\scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::rrmdir($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @\chmod($path, 0755);
        return @\rmdir($path) || !\is_dir($path);
    }

    private static function forceRemovePluginDir(): void
    {
        $pluginDir = \dirname(__DIR__); // cah-split-tester/
        $realPath  = \realpath($pluginDir);
        if ($realPath === false) {
            return;
        }

        // Safety: only proceed if the directory basename matches our plugin slug.
        if (\basename($realPath) !== 'cah-split-tester') {
            return;
        }

        // Further safety: must live inside wp-content/plugins to avoid accidents.
        if (\strpos($realPath, 'wp-content' . DIRECTORY_SEPARATOR . 'plugins') === false) {
            return;
        }

        self::rrmdir($realPath);
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
