<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Logger;

/** @var array<int,array<string,mixed>> $logs */
/** @var int $totalLogs */
/** @var int $page */
/** @var int $perPage */
/** @var array<string,mixed> $filters */
/** @var array<string,int> $bySource */
/** @var array<string,int> $byLevel */
/** @var bool $autoRefresh */
/** @var int $autoRefreshSeconds */

$totalPages = $perPage > 0 ? (int) ceil($totalLogs / $perPage) : 1;

$clearUrl = wp_nonce_url(
    admin_url('admin-post.php?action=cah_split_clear_logs'),
    'cah_split_clear_logs'
);

$selfUrl = add_query_arg(
    array_merge(
        ['page' => Admin::LOGS_SLUG],
        array_filter($filters, static fn($v): bool => $v !== null && $v !== '')
    ),
    admin_url('admin.php')
);

function cah_log_format_dt(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return esc_html($value);
    }
    return esc_html(date_i18n('Y-m-d H:i:s', $ts));
}
?>
<div class="wrap cah-split-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Logs', 'cah-split'); ?></h1>
    <a href="<?php echo esc_url($selfUrl); ?>" class="page-title-action">
        <?php esc_html_e('Refresh', 'cah-split'); ?>
    </a>
    <a href="<?php echo esc_url($clearUrl); ?>" class="page-title-action cah-danger"
       onclick="return confirm('<?php echo esc_js(__('Delete ALL log rows? This cannot be undone.', 'cah-split')); ?>');">
        <?php esc_html_e('Clear all logs', 'cah-split'); ?>
    </a>
    <hr class="wp-header-end" />

    <?php if (!empty($_GET['cleared'])) :
        $n = (int) $_GET['cleared'];
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(
                esc_html(_n('%s log row deleted.', '%s log rows deleted.', $n, 'cah-split')),
                number_format_i18n($n)
            ); ?></p>
        </div>
    <?php endif; ?>

    <div class="cah-metrics">
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Last 24h — info', 'cah-split'); ?></span>
            <span class="cah-metric-value" style="color:#1d2327;">
                <?php echo esc_html(number_format_i18n((int) ($byLevel['info'] ?? 0))); ?>
            </span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Last 24h — warn', 'cah-split'); ?></span>
            <span class="cah-metric-value" style="color:#7d5a00;">
                <?php echo esc_html(number_format_i18n((int) ($byLevel['warn'] ?? 0))); ?>
            </span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Last 24h — error', 'cah-split'); ?></span>
            <span class="cah-metric-value" style="color:#d63638;">
                <?php echo esc_html(number_format_i18n((int) ($byLevel['error'] ?? 0))); ?>
            </span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Total log rows', 'cah-split'); ?></span>
            <span class="cah-metric-value">
                <?php echo esc_html(number_format_i18n($totalLogs)); ?>
            </span>
            <span class="cah-metric-sub"><?php esc_html_e('matches current filters', 'cah-split'); ?></span>
        </div>
    </div>

    <?php if (!empty($bySource)) : ?>
        <div class="cah-log-sources">
            <strong><?php esc_html_e('Last 24h by source:', 'cah-split'); ?></strong>
            <?php foreach ($bySource as $src => $count) :
                $url = add_query_arg(['page' => Admin::LOGS_SLUG, 'source' => $src], admin_url('admin.php'));
                ?>
                <a href="<?php echo esc_url($url); ?>" class="cah-source-pill">
                    <code><?php echo esc_html($src); ?></code>
                    <span><?php echo esc_html(number_format_i18n($count)); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="cah-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(Admin::LOGS_SLUG); ?>" />

        <label><?php esc_html_e('Level', 'cah-split'); ?>
            <select name="level">
                <option value=""><?php esc_html_e('Any', 'cah-split'); ?></option>
                <?php foreach ([Logger::LEVEL_INFO, Logger::LEVEL_WARN, Logger::LEVEL_ERROR] as $lvl) : ?>
                    <option value="<?php echo esc_attr($lvl); ?>" <?php selected((string) ($filters['level'] ?? ''), $lvl); ?>>
                        <?php echo esc_html(ucfirst($lvl)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label><?php esc_html_e('Source', 'cah-split'); ?>
            <input type="text" name="source" value="<?php echo esc_attr((string) ($filters['source'] ?? '')); ?>"
                   placeholder="rest.lead.received" />
        </label>

        <label><?php esc_html_e('Search', 'cah-split'); ?>
            <input type="text" name="search" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>"
                   placeholder="email, lead_id, etc." />
        </label>

        <label><?php esc_html_e('From', 'cah-split'); ?>
            <input type="date" name="from" value="<?php echo esc_attr(substr((string) ($filters['from'] ?? ''), 0, 10)); ?>" />
        </label>

        <label><?php esc_html_e('To', 'cah-split'); ?>
            <input type="date" name="to" value="<?php echo esc_attr(substr((string) ($filters['to'] ?? ''), 0, 10)); ?>" />
        </label>

        <label class="cah-auto-refresh-label">
            <input type="checkbox" name="auto" value="1" <?php checked($autoRefresh); ?> />
            <?php printf(
                esc_html__('Auto-refresh every %ds', 'cah-split'),
                (int) $autoRefreshSeconds
            ); ?>
        </label>

        <button class="button button-primary"><?php esc_html_e('Apply', 'cah-split'); ?></button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . Admin::LOGS_SLUG)); ?>" class="button">
            <?php esc_html_e('Reset', 'cah-split'); ?>
        </a>
    </form>

    <?php if (empty($logs)) : ?>
        <div class="cah-empty">
            <p><?php esc_html_e('No log rows match the current filters yet. As soon as a /lead or /pageview request hits the plugin, it will appear here.', 'cah-split'); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped cah-logs-table">
            <thead>
                <tr>
                    <th style="width:160px;"><?php esc_html_e('Time', 'cah-split'); ?></th>
                    <th style="width:60px;"><?php esc_html_e('Level', 'cah-split'); ?></th>
                    <th style="width:170px;"><?php esc_html_e('Source', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Message', 'cah-split'); ?></th>
                    <th style="width:80px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) :
                    $id      = (int) ($log['id'] ?? 0);
                    $level   = (string) ($log['level']   ?? 'info');
                    $source  = (string) ($log['source']  ?? '');
                    $message = (string) ($log['message'] ?? '');
                    $context = (string) ($log['context'] ?? '');
                    $created = (string) ($log['created_at'] ?? '');
                    ?>
                    <tr class="cah-log-row cah-log-<?php echo esc_attr($level); ?>">
                        <td><?php echo cah_log_format_dt($created); ?></td>
                        <td>
                            <span class="cah-loglevel cah-loglevel-<?php echo esc_attr($level); ?>">
                                <?php echo esc_html($level); ?>
                            </span>
                        </td>
                        <td><code><?php echo esc_html($source); ?></code></td>
                        <td><?php echo esc_html($message); ?></td>
                        <td>
                            <?php if ($context !== '') : ?>
                                <button type="button" class="button-link cah-log-expand"
                                        data-log="<?php echo esc_attr((string) $id); ?>">
                                    <?php esc_html_e('Context', 'cah-split'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($context !== '') : ?>
                        <tr class="cah-log-context" id="cah-log-context-<?php echo esc_attr((string) $id); ?>" hidden>
                            <td colspan="5">
                                <pre><?php
                                    $decoded = json_decode($context, true);
                                    echo esc_html(
                                        is_array($decoded)
                                            ? (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                            : $context
                                    );
                                ?></pre>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1) :
            $base = add_query_arg(
                array_filter(
                    array_merge(['page' => Admin::LOGS_SLUG], $filters),
                    static fn($v): bool => $v !== null && $v !== ''
                ),
                admin_url('admin.php')
            );
            ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', $base),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $totalPages,
                        'prev_text' => __('&laquo;', 'cah-split'),
                        'next_text' => __('&raquo;', 'cah-split'),
                    ]); ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="description" style="margin-top:24px;">
        <?php esc_html_e('Logs older than 14 days are pruned automatically by daily cron. Use this page to verify that every /lead POST you expect is actually reaching the plugin. Compare the count of "rest.lead.received" rows in the last 24h against Hyros for the same window — if they match but "rest.lead.created" is lower, the gap is server-side. If "rest.lead.received" itself is lower than Hyros, the gap is client-side (cookie missing, fetch aborted, script not firing).', 'cah-split'); ?>
    </p>
</div>

<script>
(function () {
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.classList || !e.target.classList.contains('cah-log-expand')) { return; }
        var id = e.target.getAttribute('data-log');
        var row = document.getElementById('cah-log-context-' + id);
        if (!row) { return; }
        if (row.hasAttribute('hidden')) {
            row.removeAttribute('hidden');
            e.target.textContent = '<?php echo esc_js(__('Hide', 'cah-split')); ?>';
        } else {
            row.setAttribute('hidden', 'hidden');
            e.target.textContent = '<?php echo esc_js(__('Context', 'cah-split')); ?>';
        }
    });

    <?php if ($autoRefresh) : ?>
    var seconds = <?php echo (int) $autoRefreshSeconds; ?>;
    setTimeout(function () { window.location.reload(); }, seconds * 1000);
    <?php endif; ?>
})();
</script>
