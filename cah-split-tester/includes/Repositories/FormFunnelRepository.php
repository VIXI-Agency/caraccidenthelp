<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

use VIXI\CahSplit\FormFunnelStepCatalog;
use VIXI\CahSplit\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class FormFunnelRepository
{
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_form_funnel_events';
    }

    /**
     * @param array{
     *   test_id:int,
     *   variant_id:int,
     *   visitor_id:string,
     *   event_type:string,
     *   step_number:int,
     *   step_name:string
     * } $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $row = \array_merge(
            ['created_at' => \current_time('mysql')],
            $data
        );
        $wpdb->insert($this->table(), $row);
        return (int) $wpdb->insert_id;
    }

    public function deleteByTestId(int $testId): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE test_id = %d",
            $testId
        ));
    }

    /** @return array{event_rows:int,funnel:any} */
    public function buildReport(int $testId, ?int $variantId, string $fromLocal, string $toLocal): array
    {
        global $wpdb;

        $fromSql = $this->localStringForSql($fromLocal);
        $toSql   = $this->localStringForSql($toLocal);

        $evTable = $this->table();

        $variantEvSql = '';
        $paramsEv     = [$testId, $fromSql, $toSql];

        if ($variantId !== null && $variantId > 0) {
            $variantEvSql = ' AND variant_id = %d';
            $paramsEv[]   = $variantId;
        }

        // v1.0.27: do NOT mix in legacy historical pageviews for funnel math.
        // "Total page views" in this report now means visitors seen by the new
        // first-party step tracker (any funnel event in-range), so step totals
        // stay comparable to step completions after rollout.
        $trackedVisitorsSql = "SELECT COUNT(DISTINCT visitor_id) FROM {$evTable}
            WHERE test_id = %d AND created_at BETWEEN %s AND %s{$variantEvSql}";
        $pageviewsCount = (int) $wpdb->get_var($wpdb->prepare($trackedVisitorsSql, ...$paramsEv));

        $rawPvSql = "SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS unique_visitors
            FROM {$evTable}
            WHERE test_id = %d AND event_type = 'form_view'
            AND created_at BETWEEN %s AND %s{$variantEvSql}";
        $rawPvRow = $wpdb->get_row($wpdb->prepare($rawPvSql, ...$paramsEv), ARRAY_A);

        $evSql = "SELECT step_number, COUNT(DISTINCT visitor_id) AS cnt
            FROM {$evTable}
            WHERE test_id = %d AND event_type = 'step_completed'
            AND created_at BETWEEN %s AND %s{$variantEvSql}
            GROUP BY step_number";
        $evRows = $wpdb->get_results($wpdb->prepare($evSql, ...$paramsEv), ARRAY_A) ?: [];

        $completionsByStep = [];
        foreach ($evRows as $row) {
            $step = (int) $row['step_number'];
            if ($step >= 1 && $step <= FormFunnelStepCatalog::maxStep()) {
                $completionsByStep[$step] = (int) $row['cnt'];
            }
        }

        $eventRowsCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$evTable} WHERE test_id = %d
            AND created_at BETWEEN %s AND %s{$variantEvSql}",
            ...$paramsEv
        ));

        $funnel = FormFunnelStepCatalog::computeRows($pageviewsCount, $completionsByStep);

        return [
            'event_rows' => $eventRowsCount,
            'funnel'     => $funnel,
            'pageviews'  => [
                'total'           => (int) ($rawPvRow['pageviews'] ?? 0),
                'unique_visitors' => (int) ($rawPvRow['unique_visitors'] ?? 0),
            ],
        ];
    }

    private function localStringForSql(string $local): string
    {
        return $local;
    }
}
