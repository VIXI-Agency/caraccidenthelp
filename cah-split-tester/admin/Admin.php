<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Admin;

use VIXI\CahSplit\LeadReprocessor;
use VIXI\CahSplit\MakeForwarder;
use VIXI\CahSplit\Repositories\LeadsRepository;
use VIXI\CahSplit\Repositories\LogsRepository;
use VIXI\CahSplit\Repositories\PageviewsRepository;
use VIXI\CahSplit\Repositories\StatsRepository;
use VIXI\CahSplit\Repositories\TestsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;
use VIXI\CahSplit\Settings;
use VIXI\CahSplit\Stats\Significance;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const MENU_SLUG     = 'cah-split';
    public const TESTS_SLUG    = 'cah-split-tests';
    public const LEADS_SLUG    = 'cah-split-leads';
    public const LOGS_SLUG     = 'cah-split-logs';
    public const SETTINGS_SLUG = 'cah-split-settings';

    public const CAPABILITY = 'manage_options';

    public const LOGS_AUTO_REFRESH_SECONDS = 10;

    public function __construct(
        private readonly Settings $settings,
        private readonly TestsRepository $tests,
        private readonly VariantsRepository $variants,
        private readonly LeadsRepository $leads,
        private readonly PageviewsRepository $pageviews,
        private readonly StatsRepository $stats,
        private readonly Significance $significance,
        private readonly MakeForwarder $forwarder,
        private readonly LeadReprocessor $reprocessor,
        private readonly ?LogsRepository $logsRepo = null,
    ) {
    }

    public function boot(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('admin_post_cah_split_save_settings', [$this, 'handleSaveSettings']);
        \add_action('admin_post_cah_split_save_test', [$this, 'handleSaveTest']);
        \add_action('admin_post_cah_split_delete_test', [$this, 'handleDeleteTest']);
        \add_action('admin_post_cah_split_clone_test', [$this, 'handleCloneTest']);
        \add_action('admin_post_cah_split_toggle_status', [$this, 'handleToggleStatus']);
        \add_action('admin_post_cah_split_export_leads', [$this, 'handleLeadsExport']);
        \add_action('admin_post_cah_split_prune_pageviews', [$this, 'handlePrunePageviews']);
        \add_action('admin_post_cah_split_retry_make', [$this, 'handleRetryMake']);
        \add_action('admin_post_cah_split_reset_test_stats', [$this, 'handleResetTestStats']);
        \add_action('admin_post_cah_split_reprocess_unknown', [$this, 'handleReprocessUnknown']);
        \add_action('admin_post_cah_split_clear_logs', [$this, 'handleClearLogs']);
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
            \__('Logs', 'cah-split'),
            \__('Logs', 'cah-split'),
            self::CAPABILITY,
            self::LOGS_SLUG,
            [$this, 'renderLogs']
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

        $action = isset($_GET['action']) ? \sanitize_key((string) $_GET['action']) : '';
        $page   = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : '';
        $needsChart = ($page === self::MENU_SLUG)
            || ($page === self::TESTS_SLUG && $action === 'detail');
        if ($needsChart) {
            \wp_enqueue_script(
                'cah-split-chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
                [],
                '4.4.0',
                true
            );
        }
    }

    public function renderDashboard(): void
    {
        $tests    = $this->tests->all();
        $overview = $this->stats->overview(30);
        $ids      = \array_map(static fn(array $t): int => (int) $t['id'], $tests);
        $quick    = $this->stats->quickStatsForTests($ids, 30);
        $this->renderView('dashboard', [
            'tests'        => $tests,
            'overview'     => $overview,
            'quickStats'   => $quick,
            'variants'     => $this->variants,
            'failedCount'  => $this->leads->countFailed(MakeForwarder::MAX_ATTEMPTS),
        ]);
    }

    public function renderTests(): void
    {
        $action = isset($_GET['action']) ? \sanitize_key((string) $_GET['action']) : 'list';
        if ($action === 'edit' || $action === 'new') {
            $id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
            $test     = $id > 0 ? $this->tests->find($id) : null;
            $variants = $id > 0 ? $this->variants->forTest($id) : [];
            $this->renderView('test-edit', [
                'test'     => $test,
                'variants' => $variants,
            ]);
            return;
        }
        if ($action === 'detail') {
            $id   = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
            $test = $id > 0 ? $this->tests->find($id) : null;
            if ($test === null) {
                $this->redirectTo(self::TESTS_SLUG);
                return;
            }
            [$from, $to] = $this->parseDateRange();
            $this->renderView('test-detail', [
                'test'         => $test,
                'variantStats' => $this->stats->perVariant($id, $from, $to),
                'series'       => $this->stats->dailySeries($id, $from, $to),
                'utmSource'    => $this->stats->byUtm($id, 'utm_source', $from, $to),
                'utmCampaign'  => $this->stats->byUtm($id, 'utm_campaign', $from, $to),
                'significance' => $this->significance,
                'from'         => $from,
                'to'           => $to,
            ]);
            return;
        }
        $this->renderView('tests-list', [
            'tests'    => $this->tests->all(),
            'variants' => $this->variants,
        ]);
    }

    private function parseDateRange(): array
    {
        // The user picks dates in their dashboard timezone (Settings),
        // so default "today" / "30 days ago" must also be computed in that
        // zone — not via current_time()/gmdate() (which use the WP site zone
        // or UTC). The repository converts to UTC before hitting SQL.
        $tz   = $this->settings->dashboardTimezone();
        $from = isset($_GET['from']) ? \sanitize_text_field((string) $_GET['from']) : '';
        $to   = isset($_GET['to']) ? \sanitize_text_field((string) $_GET['to']) : '';

        $todayLocal = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $defFrom    = (new \DateTimeImmutable('now', $tz))->modify('-29 days')->format('Y-m-d');

        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $defFrom;
        }
        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $todayLocal;
        }

        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }

    public function renderLeads(): void
    {
        $filters = $this->parseLeadFilters();
        $page    = isset($_GET['paged']) ? \max(1, (int) $_GET['paged']) : 1;
        $perPage = 50;

        $this->renderView('leads-list', [
            'leads'       => $this->leads->query($filters, $page, $perPage),
            'totalLeads'  => $this->leads->count($filters),
            'page'        => $page,
            'perPage'     => $perPage,
            'filters'     => $filters,
            'allTests'    => $this->tests->all(),
            'allVariants' => $this->variants->all(),
        ]);
    }

    public function handleLeadsExport(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_export_leads');

        $filters  = $this->parseLeadFilters();
        $filename = 'cah-leads-' . \gmdate('Y-m-d-His') . '.csv';

        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }

        \nocache_headers();
        \header('Content-Type: text/csv; charset=utf-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = \fopen('php://output', 'w');
        \fputcsv($output, [
            'id', 'created_at', 'test_id', 'variant_id', 'visitor_id',
            'first_name', 'last_name', 'email', 'phone', 'state', 'zipcode',
            'service_type', 'attorney', 'fault', 'injury', 'timeframe', 'insured',
            'describe_accident', 'lead_stage',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_adname', 'utm_adid', 'utm_adsetid', 'utm_adsetname',
            'utm_campaignid', 'utm_placement', 'utm_sitesourcename', 'utm_creative', 'utm_state',
            'clickid', 'trustedform_cert_url', 'make_status', 'make_attempts', 'make_forwarded_at',
        ]);

        $this->leads->streamForExport(
            $filters,
            static function (array $row) use ($output): void {
                \fputcsv($output, [
                    $row['id'] ?? '',
                    $row['created_at'] ?? '',
                    $row['test_id'] ?? '',
                    $row['variant_id'] ?? '',
                    $row['visitor_id'] ?? '',
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['state'] ?? '',
                    $row['zipcode'] ?? '',
                    $row['service_type'] ?? '',
                    $row['attorney'] ?? '',
                    $row['fault'] ?? '',
                    $row['injury'] ?? '',
                    $row['timeframe'] ?? '',
                    $row['insured'] ?? '',
                    $row['describe_accident'] ?? '',
                    $row['lead_stage'] ?? '',
                    $row['utm_source'] ?? '',
                    $row['utm_medium'] ?? '',
                    $row['utm_campaign'] ?? '',
                    $row['utm_term'] ?? '',
                    $row['utm_content'] ?? '',
                    $row['utm_adname'] ?? '',
                    $row['utm_adid'] ?? '',
                    $row['utm_adsetid'] ?? '',
                    $row['utm_adsetname'] ?? '',
                    $row['utm_campaignid'] ?? '',
                    $row['utm_placement'] ?? '',
                    $row['utm_sitesourcename'] ?? '',
                    $row['utm_creative'] ?? '',
                    $row['utm_state'] ?? '',
                    $row['clickid'] ?? '',
                    $row['trustedform_cert_url'] ?? '',
                    $row['make_status'] ?? '',
                    $row['make_attempts'] ?? '',
                    $row['make_forwarded_at'] ?? '',
                ]);
            }
        );

        \fclose($output);
        exit;
    }

    private function parseLeadFilters(): array
    {
        $filters = [
            'test_id'    => isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0,
            'variant_id' => isset($_GET['variant_id']) ? (int) $_GET['variant_id'] : 0,
            'lead_stage' => isset($_GET['lead_stage']) ? \sanitize_key((string) $_GET['lead_stage']) : '',
            'utm_source' => isset($_GET['utm_source']) ? \sanitize_text_field((string) $_GET['utm_source']) : '',
            'state'      => isset($_GET['state']) ? \sanitize_text_field((string) $_GET['state']) : '',
            'email'      => isset($_GET['email']) ? \sanitize_text_field((string) $_GET['email']) : '',
            'phone'      => isset($_GET['phone']) ? \sanitize_text_field((string) $_GET['phone']) : '',
        ];
        $from = isset($_GET['from']) ? \sanitize_text_field((string) $_GET['from']) : '';
        $to   = isset($_GET['to']) ? \sanitize_text_field((string) $_GET['to']) : '';
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $filters['from'] = $from . ' 00:00:00';
        }
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $filters['to'] = $to . ' 23:59:59';
        }
        return $filters;
    }

    public function renderLogs(): void
    {
        if ($this->logsRepo === null) {
            // Should never happen post-1.0.14 since Plugin singleton always
            // injects this — guard for legacy upgrade paths anyway.
            \wp_die(\esc_html__('Logs repository not available. Re-activate the plugin.', 'cah-split'));
        }

        $filters = $this->parseLogFilters();
        $page    = isset($_GET['paged']) ? \max(1, (int) $_GET['paged']) : 1;
        $perPage = 100;

        $autoRefresh = !empty($_GET['auto']);

        $this->renderView('logs', [
            'logs'               => $this->logsRepo->query($filters, $page, $perPage),
            'totalLogs'          => $this->logsRepo->count($filters),
            'page'               => $page,
            'perPage'            => $perPage,
            'filters'            => $filters,
            'bySource'           => $this->logsRepo->countBySource(24),
            'byLevel'            => $this->logsRepo->countByLevel(24),
            'autoRefresh'        => $autoRefresh,
            'autoRefreshSeconds' => self::LOGS_AUTO_REFRESH_SECONDS,
        ]);
    }

    private function parseLogFilters(): array
    {
        $filters = [
            'level'  => isset($_GET['level'])  ? \sanitize_key((string) $_GET['level'])  : '',
            'source' => isset($_GET['source']) ? \sanitize_text_field((string) $_GET['source']) : '',
            'search' => isset($_GET['search']) ? \sanitize_text_field((string) $_GET['search']) : '',
        ];
        $from = isset($_GET['from']) ? \sanitize_text_field((string) $_GET['from']) : '';
        $to   = isset($_GET['to']) ? \sanitize_text_field((string) $_GET['to']) : '';
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $filters['from'] = $from . ' 00:00:00';
        }
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $filters['to'] = $to . ' 23:59:59';
        }
        return $filters;
    }

    public function handleClearLogs(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_clear_logs');

        $deleted = $this->logsRepo?->truncate() ?? 0;
        $this->redirectTo(self::LOGS_SLUG, ['cleared' => (string) $deleted]);
    }

    public function renderSettings(): void
    {
        $this->renderView('settings', ['settings' => $this->settings]);
    }

    public function handleSaveSettings(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_save_settings', 'cah_split_nonce');

        $webhook    = \esc_url_raw((string) ($_POST['make_webhook_url'] ?? ''));
        $ttlDays    = \max(1, (int) ($_POST['cookie_ttl_days'] ?? Settings::DEFAULT_COOKIE_TTL_DAYS));
        $dropTables = !empty($_POST['drop_tables_on_uninstall']);

        // Whitelist the timezone choice. Anything outside the published list
        // (or 'site' for WP default) is rejected back to 'site' instead of
        // letting an attacker poke arbitrary identifiers into get_option().
        $tzInput = isset($_POST['dashboard_timezone'])
            ? \sanitize_text_field((string) $_POST['dashboard_timezone'])
            : Settings::DASHBOARD_TZ_SITE;
        $tz = \array_key_exists($tzInput, Settings::DASHBOARD_TZ_CHOICES)
            ? $tzInput
            : Settings::DASHBOARD_TZ_SITE;

        $this->settings->update([
            'make_webhook_url'         => $webhook,
            'cookie_ttl_days'          => $ttlDays,
            'drop_tables_on_uninstall' => $dropTables,
            'dashboard_timezone'       => $tz,
        ]);

        $this->redirectTo(self::SETTINGS_SLUG, ['updated' => '1']);
    }

    public function handleSaveTest(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_save_test', 'cah_split_nonce');

        $id = isset($_POST['test_id']) ? (int) $_POST['test_id'] : 0;

        try {
            $testId = $this->tests->save([
                'name'         => (string) ($_POST['name'] ?? ''),
                'slug'         => (string) ($_POST['slug'] ?? ''),
                'trigger_path' => (string) ($_POST['trigger_path'] ?? ''),
                'status'       => (string) ($_POST['status'] ?? 'draft'),
            ], $id > 0 ? $id : null);

            $test = $this->tests->find($testId);
            if ($test === null) {
                throw new \RuntimeException(\__('Test could not be loaded after save.', 'cah-split'));
            }

            $rawVariants = isset($_POST['variants']) && \is_array($_POST['variants'])
                ? $_POST['variants']
                : [];

            $this->variants->replaceAll($testId, (string) $test['slug'], $rawVariants);

            \update_option('cah_split_needs_rewrite_flush', '1', false);

            $this->redirectTo(self::TESTS_SLUG, [
                'action'  => 'edit',
                'test_id' => (string) $testId,
                'saved'   => '1',
            ]);
        } catch (\Throwable $e) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                $e->getMessage(),
                60
            );
            $args = ['action' => 'edit', 'error' => '1'];
            if ($id > 0) {
                $args['test_id'] = (string) $id;
            } else {
                $args['action'] = 'new';
            }
            $this->redirectTo(self::TESTS_SLUG, $args);
        }
    }

    public function handleDeleteTest(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_delete_test');

        $id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
        if ($id > 0) {
            $this->tests->delete($id);
        }
        $this->redirectTo(self::TESTS_SLUG, ['deleted' => '1']);
    }

    public function handleCloneTest(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_clone_test');

        $id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
        if ($id > 0) {
            $newId = $this->tests->clone($id);
            if ($newId !== null) {
                $this->redirectTo(self::TESTS_SLUG, [
                    'action'  => 'edit',
                    'test_id' => (string) $newId,
                    'cloned'  => '1',
                ]);
                return;
            }
        }
        $this->redirectTo(self::TESTS_SLUG);
    }

    public function handleToggleStatus(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_toggle_status');

        $id     = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
        $status = isset($_GET['status']) ? \sanitize_key((string) $_GET['status']) : '';

        if ($id > 0 && $status !== '') {
            try {
                $this->tests->updateStatus($id, $status);
            } catch (\Throwable $e) {
                \set_transient(
                    'cah_split_error_' . \get_current_user_id(),
                    $e->getMessage(),
                    60
                );
            }
        }
        $this->redirectTo(self::TESTS_SLUG);
    }

    public function handlePrunePageviews(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_prune_pageviews', 'cah_split_nonce');

        $before = isset($_POST['before_date']) ? \sanitize_text_field((string) $_POST['before_date']) : '';
        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                \__('Provide a valid YYYY-MM-DD date.', 'cah-split'),
                60
            );
            $this->redirectTo(self::SETTINGS_SLUG);
            return;
        }

        $deleted = $this->pageviews->pruneOlderThan($before . ' 00:00:00');
        $this->redirectTo(self::SETTINGS_SLUG, ['pruned' => (string) $deleted]);
    }

    public function handleRetryMake(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_retry_make');

        try {
            $this->forwarder->retryPending();
        } catch (\Throwable $e) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                $e->getMessage(),
                60
            );
        }
        $this->redirectTo(self::MENU_SLUG, ['retried' => '1']);
    }

    public function handleResetTestStats(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_reset_test_stats');

        $id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
        if ($id <= 0) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                \__('Missing test ID.', 'cah-split'),
                60
            );
            $this->redirectTo(self::TESTS_SLUG);
            return;
        }

        if ($this->tests->find($id) === null) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                \__('Test not found.', 'cah-split'),
                60
            );
            $this->redirectTo(self::TESTS_SLUG);
            return;
        }

        try {
            $deletedPageviews = $this->pageviews->deleteByTestId($id);
            $deletedLeads     = $this->leads->deleteByTestId($id);
        } catch (\Throwable $e) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                $e->getMessage(),
                60
            );
            $this->redirectTo(self::TESTS_SLUG, [
                'action'  => 'edit',
                'test_id' => (string) $id,
            ]);
            return;
        }

        $this->redirectTo(self::TESTS_SLUG, [
            'action'             => 'edit',
            'test_id'            => (string) $id,
            'reset_stats'        => '1',
            'reset_pageviews'    => (string) $deletedPageviews,
            'reset_leads'        => (string) $deletedLeads,
        ]);
    }

    public function handleReprocessUnknown(): void
    {
        $this->assertCap();
        \check_admin_referer('cah_split_reprocess_unknown');

        $id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
        if ($id <= 0 || $this->tests->find($id) === null) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                \__('Test not found.', 'cah-split'),
                60
            );
            $this->redirectTo(self::TESTS_SLUG);
            return;
        }

        try {
            $stats = $this->reprocessor->reprocessTest($id);
        } catch (\Throwable $e) {
            \set_transient(
                'cah_split_error_' . \get_current_user_id(),
                $e->getMessage(),
                60
            );
            $this->redirectTo(self::TESTS_SLUG, [
                'action'  => 'edit',
                'test_id' => (string) $id,
            ]);
            return;
        }

        $this->redirectTo(self::TESTS_SLUG, [
            'action'           => 'edit',
            'test_id'          => (string) $id,
            'reprocessed'      => '1',
            'rp_scanned'       => (string) $stats['scanned'],
            'rp_updated'       => (string) $stats['updated'],
            'rp_qualified'     => (string) $stats['qualified'],
            'rp_disqualified'  => (string) $stats['disqualified'],
            'rp_still_unknown' => (string) $stats['still_unknown'],
            'rp_skipped'       => (string) $stats['skipped'],
            'rp_errors'        => (string) $stats['errors'],
        ]);
    }

    private function assertCap(): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('You do not have permission to perform this action.', 'cah-split'));
        }
    }

    private function redirectTo(string $page, array $args = []): void
    {
        $args['page'] = $page;
        \wp_safe_redirect(\add_query_arg($args, \admin_url('admin.php')));
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
