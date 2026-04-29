<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Repositories\StatsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;

/** @var array<int,array<string,mixed>> $tests */
/** @var array<string,mixed> $overview */
/** @var array<int,array<string,int>> $quickStats */
/** @var VariantsRepository $variants */
/** @var int $failedCount */

$newUrl = admin_url('admin.php?page=' . Admin::TESTS_SLUG . '&action=new');
$retryUrl = wp_nonce_url(
    admin_url('admin-post.php?action=cah_split_retry_make'),
    'cah_split_retry_make'
);
?>
<div class="wrap cah-split-wrap">
    <h1><?php esc_html_e('Split Tester — Dashboard', 'cah-split'); ?></h1>

    <?php if (!empty($_GET['retried'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Make.com retry triggered.', 'cah-split'); ?></p></div>
    <?php endif; ?>

    <?php if ($failedCount > 0) : ?>
        <div class="notice notice-warning">
            <p>
                <?php printf(
                    esc_html(_n(
                        '%s lead failed to forward to Make.com and is pending retry.',
                        '%s leads failed to forward to Make.com and are pending retry.',
                        $failedCount,
                        'cah-split'
                    )),
                    number_format_i18n($failedCount)
                ); ?>
                <a href="<?php echo esc_url($retryUrl); ?>" class="button button-secondary" style="margin-left:8px;">
                    <?php esc_html_e('Retry now', 'cah-split'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <div class="cah-metrics">
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Active tests', 'cah-split'); ?></span>
            <span class="cah-metric-value"><?php echo esc_html(number_format_i18n((int) $overview['active_tests'])); ?></span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php printf(esc_html__('Pageviews (last %d days)', 'cah-split'), (int) $overview['window_days']); ?></span>
            <span class="cah-metric-value"><?php echo esc_html(number_format_i18n((int) $overview['pageviews'])); ?></span>
            <span class="cah-metric-sub"><?php printf(esc_html__('%s unique visitors', 'cah-split'), number_format_i18n((int) ($overview['unique_visitors'] ?? 0))); ?></span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php printf(esc_html__('Leads (last %d days)', 'cah-split'), (int) $overview['window_days']); ?></span>
            <span class="cah-metric-value"><?php echo esc_html(number_format_i18n((int) $overview['leads'])); ?></span>
            <span class="cah-metric-sub"><?php printf(esc_html__('%s qualified', 'cah-split'), number_format_i18n((int) $overview['qualified'])); ?></span>
        </div>
        <div class="cah-metric">
            <span class="cah-metric-label"><?php esc_html_e('Overall CR', 'cah-split'); ?></span>
            <span class="cah-metric-value"><?php echo esc_html(number_format_i18n((float) $overview['cr'], 2)); ?>%</span>
        </div>
    </div>

    <h2><?php esc_html_e('Tests', 'cah-split'); ?> <a href="<?php echo esc_url($newUrl); ?>" class="page-title-action"><?php esc_html_e('Add New', 'cah-split'); ?></a></h2>

    <?php if (empty($tests)) : ?>
        <div class="cah-empty">
            <p><?php esc_html_e('No tests yet. Create your first one to start routing traffic.', 'cah-split'); ?></p>
            <a href="<?php echo esc_url($newUrl); ?>" class="button button-primary"><?php esc_html_e('Add your first test', 'cah-split'); ?></a>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Status', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Variants', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Pageviews (30d)', 'cah-split'); ?></th>
                    <th title="<?php esc_attr_e('Distinct cookie-based visitors. Pageviews÷Unique should be roughly 1.2–2 for healthy traffic.', 'cah-split'); ?>"><?php esc_html_e('Unique (30d)', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Leads (30d)', 'cah-split'); ?></th>
                    <th><?php esc_html_e('CR', 'cah-split'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $test) :
                    $id    = (int) $test['id'];
                    $vars  = $variants->forTest($id);
                    $stat  = $quickStats[$id] ?? ['pageviews' => 0, 'unique_visitors' => 0, 'leads' => 0, 'qualified' => 0];
                    $cr    = $stat['pageviews'] > 0 ? ($stat['leads'] / $stat['pageviews']) * 100 : 0;
                    $uniq  = (int) ($stat['unique_visitors'] ?? 0);
                    $ratio = $uniq > 0 ? ($stat['pageviews'] / $uniq) : 0;
                    $detail = admin_url('admin.php?page=' . Admin::TESTS_SLUG . '&action=detail&test_id=' . $id);
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($detail); ?>"><?php echo esc_html((string) $test['name']); ?></a></strong>
                            <br /><code><?php echo esc_html((string) $test['trigger_path']); ?></code>
                        </td>
                        <td>
                            <span class="cah-status cah-status-<?php echo esc_attr((string) $test['status']); ?>"><?php echo esc_html((string) $test['status']); ?></span>
                        </td>
                        <td><?php echo count($vars); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $stat['pageviews'])); ?></td>
                        <td>
                            <?php echo esc_html(number_format_i18n($uniq)); ?>
                            <?php if ($uniq > 0) : ?>
                                <small style="color:#646970;">(<?php echo esc_html(number_format_i18n($ratio, 1)); ?>x)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(number_format_i18n((int) $stat['leads'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($cr, 2)); ?>%</td>
                        <td><a href="<?php echo esc_url($detail); ?>"><?php esc_html_e('View', 'cah-split'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
