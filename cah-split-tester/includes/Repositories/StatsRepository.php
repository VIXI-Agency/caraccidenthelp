<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

use VIXI\CahSplit\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class StatsRepository
{
    public function __construct(?Settings $settings = null)
    {
        // Kept for constructor compatibility with Plugin; timestamp storage is
        // site-local, so Settings no longer affects StatsRepository queries.
    }

    /**
     * created_at is stored with WordPress' current_time('mysql'), so report
     * windows must use the WordPress site timezone, not MySQL/UTC.
     */
    private function storageTimezone(): \DateTimeZone
    {
        return \function_exists('wp_timezone') ? \wp_timezone() : new \DateTimeZone('UTC');
    }

    /**
     * created_at is stored with WordPress' current_time('mysql'), i.e. the
     * site's local wall-clock time, not UTC. Date filters therefore need to
     * compare the local picker strings directly instead of converting them.
     */
    private function localStringForSql(string $local): string
    {
        return $local;
    }

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
        // "Last N days" is anchored on local dashboard time. created_at rows
        // are stored in WordPress local time, so keep the SQL boundary local.
        $tz        = $this->storageTimezone();
        $localNow  = new \DateTimeImmutable('now', $tz);
        $sinceLocal = $localNow->modify('-' . (int) $days . ' days');
        $since = $sinceLocal->format('Y-m-d H:i:s');

        $activeTests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE status = %s",
            'active'
        ));
        $pageviews = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pv} WHERE created_at >= %s",
            $since
        ));
        $uniqueVisitors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id) FROM {$pv} WHERE created_at >= %s",
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
            'active_tests'    => $activeTests,
            'pageviews'       => $pageviews,
            'unique_visitors' => $uniqueVisitors,
            'leads'           => $leads,
            'qualified'       => $qualified,
            'cr'              => $cr,
            'window_days'     => $days,
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
        // Same anchoring as overview() — N local days back.
        $tz         = $this->storageTimezone();
        $sinceLocal = (new \DateTimeImmutable('now', $tz))->modify('-' . (int) $days . ' days');
        $since      = $sinceLocal->format('Y-m-d H:i:s');

        $placeholders = \implode(',', \array_fill(0, \count($testIds), '%d'));
        $args         = \array_merge($testIds, [$since]);

        $pageviewsQuery = "SELECT test_id,
                COUNT(*) AS total,
                COUNT(DISTINCT visitor_id) AS unique_visitors
            FROM {$pv}
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
            $out[(int) $id] = ['pageviews' => 0, 'unique_visitors' => 0, 'leads' => 0, 'qualified' => 0];
        }
        foreach ($pvRows ?? [] as $row) {
            $out[(int) $row['test_id']]['pageviews']       = (int) $row['total'];
            $out[(int) $row['test_id']]['unique_visitors'] = (int) $row['unique_visitors'];
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

        // $from / $to come from the date-range picker as local-time strings.
        // created_at is stored in WordPress local time, so compare directly.
        $fromSql = $this->localStringForSql($from);
        $toSql   = $this->localStringForSql($to);

        // "Comparable" leads = leads that do NOT trip an obvious disqualifier.
        // Per the actual business rules used by Growform AND HTML V1
        // (confirmed by the client — see v1.0.22 reversal notes), the five
        // real disqualifiers are:
        //   - attorney = 'has_attorney'
        //   - fault    = 'yes'
        //   - injury   = 'no'
        //   - timeframe IN ('longer_than_2_year','within_2_year')
        //   - service_type NOT IN ('car_accident','motorcycle_accident','trucking_accident')
        //
        // v1.0.18 had this rule, v1.0.19 incorrectly removed it on the wrong
        // assumption that service_type was not a disqualifier. v1.0.22
        // restores it so Comparable QR matches the real qualifier definition.
        //
        // Rationale for Comparable QR: some upstream forms (e.g. Growform)
        // silently drop disqualifiers before they reach our DB, so an
        // apples-to-apples qualified rate requires us to ALSO exclude them
        // from the denominator on our side. This does NOT delete or hide rows.
        $disqExpr = "(
            l.attorney = 'has_attorney'
            OR l.fault = 'yes'
            OR l.injury = 'no'
            OR l.timeframe IN ('longer_than_2_year','within_2_year')
            OR (l.service_type IS NOT NULL
                AND l.service_type <> ''
                AND l.service_type NOT IN ('car_accident','motorcycle_accident','trucking_accident'))
        )";

        $query = "
            SELECT
                v.id AS variant_id,
                v.name,
                v.slug,
                v.weight,
                COALESCE(pv.total, 0) AS pageviews,
                COALESCE(pv.unique_visitors, 0) AS unique_visitors,
                COALESCE(ld.total, 0) AS leads,
                COALESCE(ld.qualified, 0) AS qualified_leads,
                COALESCE(ld.comparable_leads, 0) AS comparable_leads,
                COALESCE(ld.disqualified_obvious, 0) AS disqualified_obvious
            FROM {$vt} v
            LEFT JOIN (
                SELECT variant_id,
                       COUNT(*) AS total,
                       COUNT(DISTINCT visitor_id) AS unique_visitors
                FROM {$pv}
                WHERE test_id = %d AND created_at BETWEEN %s AND %s
                GROUP BY variant_id
            ) pv ON pv.variant_id = v.id
            LEFT JOIN (
                SELECT l.variant_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN l.lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified,
                    SUM(CASE WHEN {$disqExpr} THEN 0 ELSE 1 END) AS comparable_leads,
                    SUM(CASE WHEN {$disqExpr} THEN 1 ELSE 0 END) AS disqualified_obvious
                FROM {$ld} l
                WHERE l.test_id = %d AND l.created_at BETWEEN %s AND %s
                GROUP BY l.variant_id
            ) ld ON ld.variant_id = v.id
            WHERE v.test_id = %d
            ORDER BY v.sort_order ASC, v.id ASC
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($query, $testId, $fromSql, $toSql, $testId, $fromSql, $toSql, $testId),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }

    public function dailySeries(int $testId, string $from, string $to): array
    {
        global $wpdb;
        $pv = $this->pageviewsTable();
        $ld = $this->leadsTable();

        $fromSql = $this->localStringForSql($from);
        $toSql   = $this->localStringForSql($to);

        $pvRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, variant_id, COUNT(*) AS total
             FROM {$pv}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $testId,
            $fromSql,
            $toSql
        ), ARRAY_A) ?: [];

        $ldRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, variant_id, COUNT(*) AS total
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $testId,
            $fromSql,
            $toSql
        ), ARRAY_A) ?: [];

        $qlRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, variant_id, COUNT(*) AS total
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s AND lead_stage = %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $testId,
            $fromSql,
            $toSql,
            'qualified'
        ), ARRAY_A) ?: [];

        return [
            'pageviews' => $pvRows,
            'leads'     => $ldRows,
            'qualified' => $qlRows,
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

        $fromSql = $this->localStringForSql($from);
        $toSql   = $this->localStringForSql($to);

        $leadAgg = $wpdb->get_results($wpdb->prepare(
            "SELECT {$field} AS bucket, variant_id, COUNT(*) AS leads,
                SUM(CASE WHEN lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s AND {$field} IS NOT NULL AND {$field} <> ''
             GROUP BY bucket, variant_id
             ORDER BY leads DESC
             LIMIT %d",
            $testId,
            $fromSql,
            $toSql,
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

        $pvArgs = \array_merge([$testId, $fromSql, $toSql], \array_values($buckets));
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
