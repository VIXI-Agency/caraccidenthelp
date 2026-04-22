<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Admin;

use VIXI\CahSplit\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const MENU_SLUG     = 'cah-split';
    public const TESTS_SLUG    = 'cah-split-tests';
    public const LEADS_SLUG    = 'cah-split-leads';
    public const SETTINGS_SLUG = 'cah-split-settings';

    public const CAPABILITY = 'manage_options';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function boot(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('admin_post_cah_split_save_settings', [$this, 'handleSaveSettings']);
    }

    public function registerMenu(): void
    {
        \add_menu_page(
            \__('Split Tester', 'cah-split'),
            \__('Split Tester', 'cah-split'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard'],
            'dashicons-chart-bar',
            58
        );

        \add_submenu_page(
            self::MENU_SLUG,
            \__('Dashboard', 'cah-split'),
            \__('Dashboard', 'cah-split'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard']
        );

        \add_submenu_page(
            self::MENU_SLUG,
            \__('Tests', 'cah-split'),
            \__('Tests', 'cah-split'),
            self::CAPABILITY,
            self::TESTS_SLUG,
            [$this, 'renderTests']
        );

        \add_submenu_page(
            self::MENU_SLUG,
            \__('Leads', 'cah-split'),
            \__('Leads', 'cah-split'),
            self::CAPABILITY,
            self::LEADS_SLUG,
            [$this, 'renderLeads']
        );

        \add_submenu_page(
            self::MENU_SLUG,
            \__('Settings', 'cah-split'),
            \__('Settings', 'cah-split'),
            self::CAPABILITY,
            self::SETTINGS_SLUG,
            [$this, 'renderSettings']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (!\str_contains($hookSuffix, 'cah-split')) {
            return;
        }

        \wp_enqueue_style(
            'cah-split-admin',
            CAH_SPLIT_PLUGIN_URL . 'admin/css/admin.css',
            [],
            CAH_SPLIT_VERSION
        );

        \wp_enqueue_script(
            'cah-split-admin',
            CAH_SPLIT_PLUGIN_URL . 'admin/js/admin.js',
            [],
            CAH_SPLIT_VERSION,
            true
        );
    }

    public function renderDashboard(): void
    {
        $this->renderView('dashboard');
    }

    public function renderTests(): void
    {
        $this->renderView('tests-list');
    }

    public function renderLeads(): void
    {
        $this->renderView('leads-list');
    }

    public function renderSettings(): void
    {
        $this->renderView('settings', ['settings' => $this->settings]);
    }

    public function handleSaveSettings(): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('You do not have permission to perform this action.', 'cah-split'));
        }

        \check_admin_referer('cah_split_save_settings', 'cah_split_nonce');

        $webhook    = \esc_url_raw((string) ($_POST['make_webhook_url'] ?? ''));
        $ttlDays    = \max(1, (int) ($_POST['cookie_ttl_days'] ?? Settings::DEFAULT_COOKIE_TTL_DAYS));
        $dropTables = !empty($_POST['drop_tables_on_uninstall']);

        $this->settings->update([
            'make_webhook_url'         => $webhook,
            'cookie_ttl_days'          => $ttlDays,
            'drop_tables_on_uninstall' => $dropTables,
        ]);

        \wp_safe_redirect(\add_query_arg(
            ['page' => self::SETTINGS_SLUG, 'updated' => '1'],
            \admin_url('admin.php')
        ));
        exit;
    }

    private function renderView(string $view, array $vars = []): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('You do not have permission to view this page.', 'cah-split'));
        }
        $path = CAH_SPLIT_PLUGIN_DIR . 'admin/views/' . $view . '.php';
        if (!\file_exists($path)) {
            return;
        }
        \extract($vars, EXTR_SKIP);
        require $path;
    }
}
