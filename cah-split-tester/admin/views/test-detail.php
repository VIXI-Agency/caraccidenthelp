<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;

/** @var array<string,mixed> $test */
/** @var array<int,array<string,mixed>> $variantStats */
/** @var array<string,mixed> $series */
/** @var array<int,array<string,mixed>> $utmSource */
/** @var array<int,array<string,mixed>> $utmCampaign */
/** @var string $from */
/** @var string $to */

$editUrl = admin_url('admin.php?page=' . Admin::TESTS_SLUG . '&action=edit&test_id=' . (int) $test['id']);

$variantLookup = [];
foreach ($variantStats as $vs) {
    $variantLookup[(int) $vs['variant_id']] = (string) $vs['name'];
}

$start = new DateTimeImmutable($from);
$end   = new DateTimeImmutable($to);
$days  = [];
$cursor = $start;
while ($cursor <= $end) {
    $days[] = $cursor->format('Y-m-d');
    $cursor = $cursor->modify('+1 day');
}

$chartData = ['labels' => $days, 'pageviews' => [], 'leads' => []];
foreach ($variantStats as $vs) {
    $chartData['pageviews'][(string) $vs['name']] = array_fill(0, count($days), 0);
    $chartData['leads'][(string) $vs['name']]     = array_fill(0, count($days), 0);
}
foreach ($series['pageviews'] as $row) {
    $name = $variantLookup[(int) $row['variant_id']] ?? 'Variant #' . (int) $row['variant_id'];
    $idx  = array_search((string) $row['day'], $days, true);
    if ($idx !== false && isset($chartData['pageviews'][$name])) {
        $chartData['pageviews'][$name][$idx] = (int) $row['total'];
    }
}
foreach ($series['leads'] as $row) {
    $name = $variantLookup[(int) $row['variant_id']] ?? 'Variant #' . (int) $row['variant_id'];
    $idx  = array_search((string) $row['day'], $days, true);
    if ($idx !== false && isset($chartData['leads'][$name])) {
        $chartData['leads'][$name][$idx] = (int) $row['total'];
    }
}
?>
<div class="wrap cah-split-wrap">
    <h1 class="wp-heading-inline">
        <?php echo esc_html((string) $test['name']); ?>
        <span class="cah-status cah-status-<?php echo esc_attr((string) $test['status']); ?>">
            <?php echo esc_html((string) $test['status']); ?>
        </span>
    </h1>
    <a href="<?php echo esc_url($editUrl); ?>" class="page-title-action"><?php esc_html_e('Edit', 'cah-split'); ?></a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=' . Admin::TESTS_SLUG)); ?>" class="page-title-action"><?php esc_html_e('All tests', 'cah-split'); ?></a>
    <hr class="wp-header-end" />

    <p class="description">
        <?php esc_html_e('Trigger', 'cah-split'); ?>: <code><?php echo esc_html((string) $test['trigger_path']); ?></code>
    </p>

    <form method="get" class="cah-daterange">
        <input type="hidden" name="page" value="<?php echo esc_attr(Admin::TESTS_SLUG); ?>" />
        <input type="hidden" name="action" value="detail" />
        <input type="hidden" name="test_id" value="<?php echo esc_attr((string) (int) $test['id']); ?>" />
        <label><?php esc_html_e('From', 'cah-split'); ?>:
            <input type="date" name="from" value="<?php echo esc_attr(substr($from, 0, 10)); ?>" />
        </label>
        <label><?php esc_html_e('To', 'cah-split'); ?>:
            <input type="date" name="to" value="<?php echo esc_attr(substr($to, 0, 10)); ?>" />
        </label>
        <button class="button"><?php esc_html_e('Apply', 'cah-split'); ?></button>
    </form>

    <h2><?php esc_html_e('Per-variant results', 'cah-split'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Variant', 'cah-split'); ?></th>
                <th><?php esc_html_e('Weight', 'cah-split'); ?></th>
                <th><?php esc_html_e('Pageviews', 'cah-split'); ?></th>
                <th><?php esc_html_e('Leads', 'cah-split'); ?></th>
                <th><?php esc_html_e('CR', 'cah-split'); ?></th>
                <th><?php esc_html_e('Qualified', 'cah-split'); ?></th>
                <th><?php esc_html_e('Qualified CR', 'cah-split'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($variantStats as $vs) :
                $pv  = (int) $vs['pageviews'];
                $lds = (int) $vs['leads'];
                $ql  = (int) $vs['qualified_leads'];
                $cr  = $pv > 0 ? ($lds / $pv) * 100 : 0.0;
                $qcr = $pv > 0 ? ($ql / $pv) * 100 : 0.0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $vs['name']); ?></strong> <small><?php echo esc_html((string) $vs['slug']); ?></small></td>
                    <td><?php echo esc_html((string) (int) $vs['weight']); ?>%</td>
                    <td><?php echo esc_html(number_format_i18n($pv)); ?></td>
                    <td><?php echo esc_html(number_format_i18n($lds)); ?></td>
                    <td><?php echo esc_html(number_format_i18n($cr, 2)); ?>%</td>
                    <td><?php echo esc_html(number_format_i18n($ql)); ?></td>
                    <td><?php echo esc_html(number_format_i18n($qcr, 2)); ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e('Daily trend', 'cah-split'); ?></h2>
    <div class="cah-chart-wrap">
        <canvas id="cah-daily-chart" height="120"></canvas>
    </div>

    <div class="cah-utm-grid">
        <div>
            <h2><?php esc_html_e('Top UTM sources', 'cah-split'); ?></h2>
            <?php if (empty($utmSource)) : ?>
                <p class="description"><?php esc_html_e('No leads yet for this range.', 'cah-split'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Variant', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Pageviews', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Leads', 'cah-split'); ?></th>
                            <th><?php esc_html_e('CR', 'cah-split'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($utmSource as $row) :
                            $pv  = (int) $row['pageviews'];
                            $lds = (int) $row['leads'];
                            $cr  = $pv > 0 ? ($lds / $pv) * 100 : 0.0;
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $row['bucket']); ?></code></td>
                                <td><?php echo esc_html($variantLookup[(int) $row['variant_id']] ?? ('#' . (int) $row['variant_id'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($pv)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($lds)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($cr, 2)); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div>
            <h2><?php esc_html_e('Top UTM campaigns', 'cah-split'); ?></h2>
            <?php if (empty($utmCampaign)) : ?>
                <p class="description"><?php esc_html_e('No leads yet for this range.', 'cah-split'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Campaign', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Variant', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Pageviews', 'cah-split'); ?></th>
                            <th><?php esc_html_e('Leads', 'cah-split'); ?></th>
                            <th><?php esc_html_e('CR', 'cah-split'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($utmCampaign as $row) :
                            $pv  = (int) $row['pageviews'];
                            $lds = (int) $row['leads'];
                            $cr  = $pv > 0 ? ($lds / $pv) * 100 : 0.0;
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $row['bucket']); ?></code></td>
                                <td><?php echo esc_html($variantLookup[(int) $row['variant_id']] ?? ('#' . (int) $row['variant_id'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($pv)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($lds)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($cr, 2)); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var data = <?php echo wp_json_encode($chartData); ?>;
    if (!window.Chart || !data || !data.labels || !data.labels.length) { return; }

    var palette = ['#2271b1', '#d63638', '#00a32a', '#dba617', '#8c8f94', '#2c5aa0', '#7e57c2'];
    var datasets = [];
    var idx = 0;
    Object.keys(data.pageviews).forEach(function (variant) {
        var color = palette[idx % palette.length];
        datasets.push({
            label: variant + ' — pageviews',
            data: data.pageviews[variant],
            borderColor: color,
            backgroundColor: color + '22',
            tension: 0.2,
            yAxisID: 'y'
        });
        datasets.push({
            label: variant + ' — leads',
            data: data.leads[variant] || [],
            borderColor: color,
            borderDash: [5, 3],
            tension: 0.2,
            yAxisID: 'y1'
        });
        idx++;
    });

    var ctx = document.getElementById('cah-daily-chart');
    if (!ctx) { return; }
    new Chart(ctx, {
        type: 'line',
        data: { labels: data.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { position: 'left',  title: { display: true, text: 'Pageviews' } },
                y1: { position: 'right', title: { display: true, text: 'Leads' }, grid: { drawOnChartArea: false } }
            }
        }
    });
})();
</script>
