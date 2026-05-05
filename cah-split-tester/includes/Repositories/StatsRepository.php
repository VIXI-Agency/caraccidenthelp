<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

use VIXI\CahSplit\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class StatsRepository
{
    public function __construct(
        private readonly ?Settings $settings = null
    ) {
    }

    /**
     * Resolve the dashboard timezone (falls back to UTC if no Settings
     * instance was injected, e.g. from legacy callers).
     */
    private function tz(): \DateTimeZone
    {
        if ($this->settings !== null) {
            return $this->settings->dashboardTimezone();
        }
        return new \DateTimeZone('UTC');
    }

    /**
     * Convert a 'YYYY-MM-DD HH:MM:SS' string interpreted in the dashboard
     * timezone to its equivalent UTC string for SQL WHERE clauses against
     * created_at columns (which are stored in UTC).
     */
    private function localStringToUtc(string $local): string
    {
        try {
            $dt = new \DateTimeImmutable($local, $this->tz());
        } catch (\Throwable $e) {
            // If the input is malformed, fall back to treating it as UTC
            // verbatim — better than throwing inside the dashboard render.
            return $local;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Current offset (seconds) between the dashboard timezone and UTC at
     * "now". Used for DATE() bucketing in the daily series query — see
     * the comment in dailySeries() for the DST caveat.
     */
    private function tzOffsetSeconds(): int
    {
        return (new \DateTimeImmutable('now', $this->tz()))->getOffset();
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
        // "Last N days" is anchored on midnight LOCAL TIME today, then we
        // walk N days back. That makes "Last 7 days" feel right to a human:
        // it covers the previous 7 calendar days in the dashboard timezone,
        // not a rolling 7×24 hour window in UTC. We then convert the local
        // boundary to UTC before issuing the SQL.
        $tz        = $this->tz();
        $localNow  = new \DateTimeImmutable('now', $tz);
        $sinceLocal = $localNow->modify('-' . (int) $days . ' days');
        $since = $sinceLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

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
        // Same anchoring as overview() — N local days back, then to UTC.
        $tz         = $this->tz();
        $sinceLocal = (new \DateTimeImmutable('now', $tz))->modify('-' . (int) $days . ' days');
        $since      = $sinceLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

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

        // $from / $to come from the date-range picker as local-time strings
        // (e.g. '2026-04-28 00:00:00' meaning midnight local). Translate to
        // UTC before hitting the SQL because created_at is stored as UTC.
        $fromUtc = $this->localStringToUtc($from);
        $toUtc   = $this->localStringToUtc($to);

        // "Comparable" leads = leads that do NOT trip an obvious disqualifier
        // (has_attorney, fault=yes, injury=no, timeframe>2yr, non-MVA service).
        // Rationale: some upstream forms (e.g. Growform) silently drop these
        // before they reach our DB, so an apples-to-apples qualified rate
        // requires us to ALSO exclude them from the denominator on our side.
        // This does NOT delete or hide rows — it only adjusts the metric.
        $disqExpr = "(
            l.attorney = 'has_attorney'
            OR l.fault = 'yes'
            OR l.injury = 'no'
            OR l.timeframe IN ('longer_than_2_year','within_2_year')
            OR (l.service_type IS NOT NULL AND l.service_type <> ''
                AND l.service_type NOT IN (
                    'car_accident','truck_accident','trucking_accident',
                    'motorcycle_accident','rideshare_accident',
                    'pedestrian_accident','bicycle_accident'
                ))
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
            $wpdb->prepare($query, $testId, $fromUtc, $toUtc, $testId, $fromUtc, $toUtc, $testId),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }

    public function dailySeries(int $testId, string $from, string $to): array
    {
        global $wpdb;
        $pv = $this->pageviewsTable();
        $ld = $this->leadsTable();

        $fromUtc = $this->localStringToUtc($from);
        $toUtc   = $this->localStringToUtc($to);

        // To bucket rows by LOCAL calendar day (instead of UTC), shift the
        // stored UTC timestamp by the current dashboard-timezone offset, then
        // call DATE() on the result. Using a fixed offset rather than
        // CONVERT_TZ() avoids depending on MySQL's tz tables being populated
        // (Hostinger / shared MySQL often have them empty).
        // Caveat: on the day a DST transition lands within the date range,
        // a small handful of rows near midnight may bucket on the "wrong"
        // side by 1 hour. Acceptable for an A/B dashboard but flagged here.
        $offsetSeconds = $this->tzOffsetSeconds();

        $pvRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at + INTERVAL %d SECOND) AS day, variant_id, COUNT(*) AS total
             FROM {$pv}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $offsetSeconds,
            $testId,
            $fromUtc,
            $toUtc
        ), ARRAY_A) ?: [];

        $ldRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at + INTERVAL %d SECOND) AS day, variant_id, COUNT(*) AS total
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY day, variant_id
             ORDER BY day ASC",
            $offsetSeconds,
            $testId,
            $fromUtc,
            $toUtc
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

        $fromUtc = $this->localStringToUtc($from);
        $toUtc   = $this->localStringToUtc($to);

        $leadAgg = $wpdb->get_results($wpdb->prepare(
            "SELECT {$field} AS bucket, variant_id, COUNT(*) AS leads,
                SUM(CASE WHEN lead_stage = 'qualified' THEN 1 ELSE 0 END) AS qualified
             FROM {$ld}
             WHERE test_id = %d AND created_at BETWEEN %s AND %s AND {$field} IS NOT NULL AND {$field} <> ''
             GROUP BY bucket, variant_id
             ORDER BY leads DESC
             LIMIT %d",
            $testId,
            $fromUtc,
            $toUtc,
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

        $pvArgs = \array_merge([$testId, $fromUtc, $toUtc], \array_values($buckets));
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
