<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;

/** @var array<int,array<string,mixed>>|array{} $tests */
/** @var array<string,mixed>|null $report */
/** @var array<string,mixed>|null $overview */
/** @var array<int,array<string,mixed>> $variantOptions */
/** @var string $fromDate */
/** @var string $toDate */
/** @var int $testId */
/** @var int $variantId */
/** @var string $variantName */
/** @var string $timezoneLabel */
/** @var array<string,mixed>|null $test */

$baseUrl       = esc_url(admin_url('admin.php?page=' . Admin::FUNNEL_SLUG));
$funnelSteps   = $report['funnel']['steps'] ?? [];
$pageviewsKpi   = (int) ($report['funnel']['pageviews'] ?? 0);
$completionsKpi = (int) ($report['funnel']['completions'] ?? 0);
$crPct          = (float) ($report['funnel']['conversion_rate'] ?? 0);
$eventRows      = (int) ($overview['event_row_count'] ?? 0);
$rawPageviews   = (int) ($report['pageviews']['total'] ?? 0);
$rawUniquePv    = (int) ($report['pageviews']['unique_visitors'] ?? 0);
$funnelStale    = $tests !== [] && $eventRows === 0 && $pageviewsKpi > 50;
?>

<?php if ($tests === []) : ?>
    <div class="notice notice-warning" style="max-width:800px;"><p><?php esc_html_e('Create a split test first, then funnel data will populate when visitors use the HTML v1 variant.', 'cah-split'); ?></p></div>
    <?php return; ?>
<?php endif; ?>

<div class="wrap cah-funnel-app">

    <header class="cah-funnel-hero">
        <div class="cah-funnel-hero-inner">
            <div>
                <h1><?php esc_html_e('Multi-step form funnel', 'cah-split'); ?></h1>
                <p class="cah-funnel-hero-tag"><?php printf(
                    esc_html__('Live first-party telemetry from HTML v1 (%1$s). This view is locked to v1.html only.', 'cah-split'),
                    '<code style="opacity:.85;">path_a_html_v1</code>'
                ); ?></p>
            </div>
            <form method="get" action="<?php echo $baseUrl; ?>" class="cah-funnel-filters-wrap">
                <input type="hidden" name="page" value="<?php echo esc_attr(Admin::FUNNEL_SLUG); ?>"/>
                <input type="hidden" name="test_id" value="<?php echo esc_attr((string) $testId); ?>"/>
                <input type="hidden" name="variant_id" value="<?php echo esc_attr((string) $variantId); ?>"/>
                <div class="cah-funnel-fixed-scope">
                    <span class="cah-funnel-fixed-label"><?php esc_html_e('Scope', 'cah-split'); ?></span>
                    <strong>
                        <?php
                        if (!empty($variantName)) {
                            echo esc_html($variantName . ' (v1)');
                        } else {
                            esc_html_e('HTML v1 only', 'cah-split');
                        }
                        ?>
                    </strong>
                </div>
                <label>
                    <span><?php esc_html_e('From', 'cah-split'); ?></span>
                    <input type="text" class="cah-date-input" name="from" value="<?php echo esc_attr($fromDate); ?>" autocomplete="off"/>
                </label>
                <label>
                    <span><?php esc_html_e('To', 'cah-split'); ?></span>
                    <input type="text" class="cah-date-input" name="to" value="<?php echo esc_attr($toDate); ?>" autocomplete="off"/>
                </label>
                <button type="submit" class="button primary"><?php esc_html_e('Apply', 'cah-split'); ?></button>
            </form>
        </div>
    </header>

    <?php if ($funnelStale) : ?>
        <div class="cah-fn-notice">        <?php printf(
            esc_html__('Large pageview volume but zero funnel-row events stored for this slice. Confirm plugin v%s+ is deployed and caching is bypassed on variant URLs.', 'cah-split'),
            esc_attr(\defined('CAH_SPLIT_VERSION') ? \CAH_SPLIT_VERSION : '?')
        ); ?></div>
    <?php endif; ?>

    <section class="cah-fn-kpi-grid">
        <article class="cah-fn-kpi-card">
            <p class="cah-fn-kpi-label"><?php esc_html_e('Total tracked visits', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Distinct visitor IDs with at least one funnel event (step_completed or form_abandon) in the selected range.', 'cah-split'); ?>">i</span></p>
            <p class="cah-fn-kpi-value"><?php echo esc_html(\number_format_i18n(\max(0, $pageviewsKpi))); ?></p>
            <p class="cah-fn-kpi-sub"><?php esc_html_e('Distinct visitors seen by funnel events in period', 'cah-split'); ?></p>
        </article>
        <article class="cah-fn-kpi-card">
            <p class="cah-fn-kpi-label"><?php esc_html_e('PageViews', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Counted only from HTML v1 form events (`form_view`) emitted by v1.html for this date range. Not sourced from the general dashboard pageviews table.', 'cah-split'); ?>">i</span></p>
            <p class="cah-fn-kpi-value"><?php echo esc_html(\number_format_i18n(\max(0, $rawPageviews))); ?></p>
            <p class="cah-fn-kpi-sub"><?php printf(esc_html__('%s unique visitors', 'cah-split'), \number_format_i18n(\max(0, $rawUniquePv))); ?></p>
        </article>
        <article class="cah-fn-kpi-card">
            <p class="cah-fn-kpi-label"><?php esc_html_e('Funnel event rows', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Raw stored events (step_completed + form_abandon). Can be higher than tracked visits because one visitor emits multiple events.', 'cah-split'); ?>">i</span></p>
            <p class="cah-fn-kpi-value"><?php echo esc_html(\number_format_i18n(\max(0, $eventRows))); ?></p>
            <p class="cah-fn-kpi-sub"><?php esc_html_e('All step_completed + abandon payloads', 'cah-split'); ?></p>
        </article>
        <article class="cah-fn-kpi-card">
            <p class="cah-fn-kpi-label"><?php esc_html_e('Form completions', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Distinct visitor IDs that completed step 12 (Describe) in the selected range.', 'cah-split'); ?>">i</span></p>
            <p class="cah-fn-kpi-value"><?php echo esc_html(\number_format_i18n(\max(0, $completionsKpi))); ?></p>
            <p class="cah-fn-kpi-sub"><?php esc_html_e('Distinct visitors that completed step 12 in period', 'cah-split'); ?></p>
        </article>
        <article class="cah-fn-kpi-card">
            <p class="cah-fn-kpi-label"><?php esc_html_e('Conversion rate', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Completions divided by Total tracked visits for the same range and filters.', 'cah-split'); ?>">i</span></p>
            <p class="cah-fn-kpi-value"><?php echo esc_html(\number_format_i18n(\max(0.0, $crPct), 2)); ?>%</p>
            <p class="cah-fn-kpi-sub"><?php esc_html_e('Completions ÷ tracked visits', 'cah-split'); ?></p>
        </article>
    </section>

    <section class="cah-fn-layout">
        <article class="cah-fn-panel">
            <div class="cah-fn-panel-head"><?php esc_html_e('Questions & abandonment', 'cah-split'); ?></div>
            <div class="cah-fn-table-wrap">
                <table class="cah-fn-table">
                    <thead>
                    <tr>
                        <th style="width:44px"><?php esc_html_e('#', 'cah-split'); ?></th>
                        <th><?php esc_html_e('Question', 'cah-split'); ?></th>
                        <th><?php esc_html_e('Total', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Step 1 uses tracked visits. For later steps, Total = entrants inferred from the previous step completion logic.', 'cah-split'); ?>">i</span></th>
                        <th><?php esc_html_e('Completions', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Distinct visitor IDs that completed this step.', 'cah-split'); ?>">i</span></th>
                        <th><?php esc_html_e('% Completed', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Completions / Total for this row.', 'cah-split'); ?>">i</span></th>
                        <th><?php esc_html_e('Abandonments', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Calculated as Total - Completions (funnel math), not just explicit form_abandon events.', 'cah-split'); ?>">i</span></th>
                        <th><?php esc_html_e('% Abandon', 'cah-split'); ?> <span class="cah-tip" data-tip="<?php esc_attr_e('Abandonments / Total for this row.', 'cah-split'); ?>">i</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($funnelSteps as $row) :
                        $pctC = (float) $row['pct_completed'];
                        $pctA = (float) $row['pct_abandon'];
                        $pill = $pctC >= 80 ? 'cah-fn-pill cah-fn-pill--ok' : 'cah-fn-pill cah-fn-pill--risk';
                        ?>
                        <tr>
                            <td class="cah-fn-num"><?php echo esc_html((string) ((int) $row['step'])); ?></td>
                            <td>
                                <div class="cah-fn-q"><?php echo esc_html((string) $row['title']); ?></div>
                                <p class="cah-fn-meta"><?php echo esc_html((string) $row['question']); ?></p>
                            </td>
                            <td class="cah-fn-num"><?php echo esc_html(\number_format_i18n((int) $row['total'])); ?></td>
                            <td class="cah-fn-num"><?php echo esc_html(\number_format_i18n((int) $row['completed'])); ?></td>
                            <td><span class="<?php echo esc_attr($pill); ?>"><?php echo esc_html(\number_format_i18n(\max(0.0, $pctC), 2)); ?>%</span></td>
                            <td class="cah-fn-num"><?php echo esc_html(\number_format_i18n((int) $row['abandonments'])); ?></td>
                            <td class="cah-fn-num"><?php echo esc_html(\number_format_i18n(\max(0.0, $pctA), 2)); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <footer class="cah-fn-hint-bar">
                <?php printf(
                    esc_html__('Date range uses %1$s. Step 8 (Insured) is conditional — low totals are expected.', 'cah-split'),
                    '<strong>' . esc_html($timezoneLabel) . '</strong>'
                ); ?>
                — <?php esc_html_e(
                    '“Total” for step ≥2 is completions of the prior inferred step except Name, which entrants = Zip completions.',
                    'cah-split'
                ); ?>
            </footer>
        </article>

        <aside class="cah-fn-chart-pane cah-fn-panel">
            <div class="cah-fn-panel-head"><?php esc_html_e('Completion % by step', 'cah-split'); ?></div>
            <?php if ($funnelSteps === []) : ?>
                <div class="cah-fn-empty"><?php esc_html_e('No rows to chart.', 'cah-split'); ?></div>
            <?php else : ?>
                <div class="cah-fn-chart-canvas-wrap">
                    <canvas id="cahFunnelChart" height="560" width="340" aria-label="<?php esc_attr_e('Completion rate bar chart', 'cah-split'); ?>"></canvas>
                </div>
            <?php endif; ?>
        </aside>
    </section>
</div>

