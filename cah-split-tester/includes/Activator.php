<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class Activator
{
    public const DB_VERSION_OPTION = 'cah_split_db_version';
    public const FLUSH_FLAG_OPTION = 'cah_split_needs_rewrite_flush';

    public static function activate(): void
    {
        self::createTables();
        self::seedDefaultSettings();
        \update_option(self::DB_VERSION_OPTION, CAH_SPLIT_VERSION);
        \update_option(self::FLUSH_FLAG_OPTION, '1', false);
    }

    public static function migrateIfNeeded(): void
    {
        $stored = (string) \get_option(self::DB_VERSION_OPTION, '0.0.0');
        if (\version_compare($stored, CAH_SPLIT_VERSION, '<')) {
            self::activate();
        }
    }

    private static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $prefix         = $wpdb->prefix;

        $tests = "CREATE TABLE {$prefix}cah_tests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(100) NOT NULL DEFAULT '',
            trigger_path VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_trigger_status (trigger_path, status),
            KEY idx_slug (slug)
        ) {$charsetCollate};";

        $variants = "CREATE TABLE {$prefix}cah_variants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(100) NOT NULL DEFAULT '',
            url VARCHAR(2048) NOT NULL,
            html_file VARCHAR(255) DEFAULT NULL,
            pretty_path VARCHAR(190) DEFAULT NULL,
            weight TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_test (test_id),
            KEY idx_test_slug (test_id, slug),
            KEY idx_pretty_path (pretty_path)
        ) {$charsetCollate};";

        $pageviews = "CREATE TABLE {$prefix}cah_pageviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            visitor_id CHAR(36) NOT NULL,
            utm_source VARCHAR(191) DEFAULT NULL,
            utm_medium VARCHAR(191) DEFAULT NULL,
            utm_campaign VARCHAR(191) DEFAULT NULL,
            utm_term VARCHAR(191) DEFAULT NULL,
            utm_content VARCHAR(191) DEFAULT NULL,
            clickid VARCHAR(191) DEFAULT NULL,
            referrer VARCHAR(2048) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_variant_date (variant_id, created_at),
            KEY idx_test_date (test_id, created_at),
            KEY idx_visitor (visitor_id)
        ) {$charsetCollate};";

        $leads = "CREATE TABLE {$prefix}cah_leads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id BIGINT UNSIGNED DEFAULT NULL,
            variant_id BIGINT UNSIGNED DEFAULT NULL,
            visitor_id CHAR(36) DEFAULT NULL,
            service_type VARCHAR(64) DEFAULT NULL,
            attorney VARCHAR(64) DEFAULT NULL,
            fault VARCHAR(16) DEFAULT NULL,
            injury VARCHAR(16) DEFAULT NULL,
            timeframe VARCHAR(64) DEFAULT NULL,
            state VARCHAR(64) DEFAULT NULL,
            zipcode VARCHAR(16) DEFAULT NULL,
            insured VARCHAR(16) DEFAULT NULL,
            first_name VARCHAR(128) DEFAULT NULL,
            last_name VARCHAR(128) DEFAULT NULL,
            email VARCHAR(191) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            describe_accident TEXT,
            twilio_lookup_status VARCHAR(32) DEFAULT NULL,
            trestle_first_name VARCHAR(128) DEFAULT NULL,
            trestle_last_name VARCHAR(128) DEFAULT NULL,
            trestle_email VARCHAR(191) DEFAULT NULL,
            lead_stage VARCHAR(20) NOT NULL DEFAULT 'unknown',
            utm_source VARCHAR(191) DEFAULT NULL,
            utm_medium VARCHAR(191) DEFAULT NULL,
            utm_campaign VARCHAR(191) DEFAULT NULL,
            utm_term VARCHAR(191) DEFAULT NULL,
            utm_content VARCHAR(191) DEFAULT NULL,
            utm_adname VARCHAR(191) DEFAULT NULL,
            utm_adid VARCHAR(191) DEFAULT NULL,
            utm_adsetid VARCHAR(191) DEFAULT NULL,
            utm_adsetname VARCHAR(191) DEFAULT NULL,
            utm_campaignid VARCHAR(191) DEFAULT NULL,
            utm_placement VARCHAR(191) DEFAULT NULL,
            utm_sitesourcename VARCHAR(191) DEFAULT NULL,
            utm_creative VARCHAR(191) DEFAULT NULL,
            utm_state VARCHAR(64) DEFAULT NULL,
            clickid VARCHAR(191) DEFAULT NULL,
            trustedform_cert_url VARCHAR(500) DEFAULT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            make_forwarded_at DATETIME DEFAULT NULL,
            make_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            make_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            make_response LONGTEXT,
            raw_payload LONGTEXT,
            source VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_variant_date (variant_id, created_at),
            KEY idx_test_date (test_id, created_at),
            KEY idx_stage (lead_stage),
            KEY idx_utm_source (utm_source),
            KEY idx_email (email),
            KEY idx_phone (phone),
            KEY idx_make_status (make_status),
            KEY idx_source (source)
        ) {$charsetCollate};";

        $log = "CREATE TABLE {$prefix}cah_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            source VARCHAR(64) NOT NULL,
            message VARCHAR(500) NOT NULL,
            context LONGTEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_level_date (level, created_at),
            KEY idx_source_date (source, created_at),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        \dbDelta($tests);
        \dbDelta($variants);
        \dbDelta($pageviews);
        \dbDelta($leads);
        \dbDelta($log);
    }

    private static function seedDefaultSettings(): void
    {
        $settings = new Settings();
        $existing = \get_option(Settings::OPTION_KEY, []);
        if (!\is_array($existing)) {
            $existing = [];
        }
        $merged = \array_merge($settings->defaults(), $existing);
        if (empty($merged['ip_hash_salt'])) {
            $merged['ip_hash_salt'] = \wp_generate_password(64, false);
        }
        \update_option(Settings::OPTION_KEY, $merged);
    }
}
