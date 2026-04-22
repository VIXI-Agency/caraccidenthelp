<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class StatsRepository
{
    private function pageviewsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_pageviews';
    }

    private function leadsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_leads';
    }

    private function testsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_tests';
    }

    private function variantsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_variants';
    }

    public function overview(int $days = 30): array
    {
        global $wpdb;
        $pv     = $this->pageviewsTable();
        $ld     = $this->leadsTable();
        $t      = $this->testsTable();
        $since  = \gmdate('Y-m-d H:i:s', \time() - ($days * DAY_IN_SECONDS));

        $activeTests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE status = %s",
            'active'
        ));
        $pageviews = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pv} WHERE created_at >= %s",
            $since
        ));
        $leads = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ld} WHERE created_at >= %s",
            $since
        ));
        $qualified = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ld} WHERE created_at >= %s AND lead_stage = %s",
            $since,
            'qualified'
        ));

        $cr = $pageviews > 0 ? ($leads / $pageviews) * 100.0 : 0.0;

        return [
            'active_tests'  => $activeTests,
            'pageviews'     => $pageviews,
            'leads'         => $leads,
            'qualified'     => $qualified,
            'cr'            => $cr,
            'window_days'   => $days,
        ];
    }

    public function quickStatsForTests(array $testIds, int $days = 30): array
    {
        if (empty($testIds)) {
            return [];
        }
        global $wpdb;
        $pv    = $this->pageviewsTable();
        $ld    = $this->leadsTable();
        $since = \gmdate('Y-m-d H:i:s', \time() - ($days * DAY_IN_SECONDS));

        $placeholders = \implode(',', \array_fill(0, \count($testIds), '%d'));
        $args         = \array_merge($testIds, [$since]);

        $pageviewsQuery = "SELECT test_id, COUNT(*) AS total FROM {$pv}
            WHERE test_id IN ({$placeholders}) AND created_at >= %s
            GROUP BY test_id";
        $pvRows = $wpdb->get_results($wpdb->prepare($pageviewsQuery, ...$args), ARRAY_A);

        $leadsQuery = "SELECT test_id, COUNT(*) AS total,
            SUM(CASE WHEN lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified
            FROM {$ld}
            WHERE test_id IN ({$placeholders}) AND created_at >= %s
            GROUP BY test_id";
        $ldRows = $wpdb->get_results($wpdb->prepare($leadsQuery, ...$args), ARRAY_A);

        $out = [];
        foreach ($testIds as $id) {
            $out[(int) $id] = ['pageviews' => 0, 'leads' => 0, 'qualified' => 0];
        }
        foreach ($pvRows ?? [] as $row) {
            $out[(int) $row['test_id']]['pageviews'] = (int) $row['total'];
        }
        foreach ($ldRows ?? [] as $row) {
            $out[(int) $row['test_id']]['leads']     = (int) $row['total'];
            $out[(int) $row['test_id']]['qualified'] = (int) $row['qualified'];
        }
        return $out;
    }

    public function perVariant(int $testId, string $from, string $to): array
    {
        global $wpdb;
        $pv = $this->pageviewsTable();
        $ld = $this->leadsTable();
        $vt = $this->variantsTable();

        $query = "
            SELECT
                v.id AS variant_id,
                v.name,
                v.slug,
                v.weight,
                COALESCE(pv.total, 0) AS pageviews,
                COALESCE(ld.total, 0) AS leads,
                COALESCE(ld.qualified, 0) AS qualified_leads
            FROM {$vt} v
            LEFT JOIN (
                SELECT variant_id, COUNT(*) AS total FROM {$pv}
                WHERE test_id = %d AND created_at BETWEEN %s AND %s
                GROUP BY variant_id
            ) pv ON pv.variant_id = v.id
            LEFT JOIN (
                SELECT variant_id, COUNT(*) AS total,
                    SUM(CASE WHEN lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified
                FROM {$ld}
                WHERE test_id = %d AND created_at BETWEEN %s AND %s
                GROUP BY variant_id
            ) ld ON ld.variant_id = v.id
            WHERE v.test_id = %d
            ORDER BY v.sort_order ASC, v.id ASC
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($query, $testId, $from, $to, $testId, $from, $to, $testId),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }

    public function dailySeries(int $testId, string $from, string $to): array
    {
        global $wpdb;
        $pv = $this->pageviewsTable();
        $ld = $this->leadsTable();

        $pvRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, variant_id, COUNT(*) AS total
             FROM {$pv}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $testId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        $ldRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, variant_id, COUNT(*) AS total
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $testId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        return [
            'pageviews' => $pvRows,
            'leads'     => $ldRows,
        ];
    }

    public function byUtm(int $testId, string $field, string $from, string $to, int $limit = 25): array
    {
        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_adname', 'utm_term', 'clickid'];
        if (!\in_array($field, $allowed, true)) {
            return [];
        }
        global $wpdb;
        $ld = $this->leadsTable();
        $pv = $this->pageviewsTable();

        $leadAgg = $wpdb->get_results($wpdb->prepare(
            "SELECT {$field} AS bucket, variant_id, COUNT(*) AS leads,
                SUM(CASE WHEN lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s AND {$field} IS NOT NULL AND {$field} <> ''
             GROUP BY bucket, variant_id
             ORDER BY leads DESC
             LIMIT %d",
            $testId,
            $from,
            $to,
            $limit * 10
        ), ARRAY_A) ?: [];

        $buckets = \array_unique(\array_map(
            static fn(array $r): string => (string) $r['bucket'],
            $leadAgg
        ));
        if (empty($buckets)) {
            return [];
        }
        $bucketPlaceholders = \implode(',', \array_fill(0, \count($buckets), '%s'));

        $pvArgs = \array_merge([$testId, $from, $to], \array_values($buckets));
        $pvAgg = $wpdb->get_results($wpdb->prepare(
            "SELECT {$field} AS bucket, variant_id, COUNT(*) AS pageviews
             FROM {$pv}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s AND {$field} IN ({$bucketPlaceholders})
             GROUP BY bucket, variant_id",
            ...$pvArgs
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($leadAgg as $row) {
            $key = $row['bucket'] . '|' . $row['variant_id'];
            $out[$key] = [
                'bucket'          => (string) $row['bucket'],
                'variant_id'      => (int) $row['variant_id'],
                'pageviews'       => 0,
                'leads'           => (int) $row['leads'],
                'qualified_leads' => (int) $row['qualified'],
            ];
        }
        foreach ($pvAgg as $row) {
            $key = $row['bucket'] . '|' . $row['variant_id'];
            if (!isset($out[$key])) {
                $out[$key] = [
                    'bucket'          => (string) $row['bucket'],
                    'variant_id'      => (int) $row['variant_id'],
                    'pageviews'       => 0,
                    'leads'           => 0,
                    'qualified_leads' => 0,
                ];
            }
            $out[$key]['pageviews'] = (int) $row['pageviews'];
        }

        \uasort($out, static fn(array $a, array $b): int => $b['leads'] <=> $a['leads']);
        return \array_slice(\array_values($out), 0, $limit);
    }
}
