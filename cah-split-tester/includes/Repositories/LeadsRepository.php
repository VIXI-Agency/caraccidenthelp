<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

use VIXI\CahSplit\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadsRepository
{
    public const MAKE_STATUS_PENDING = 'pending';
    public const MAKE_STATUS_SUCCESS = 'success';
    public const MAKE_STATUS_FAILED  = 'failed';
    public const MAKE_STATUS_SKIPPED = 'skipped';

    public function __construct(private readonly ?Logger $logger = null)
    {
    }

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_leads';
    }

    /**
     * Insert a new lead row.
     *
     * Throws on DB failure. Previously this method swallowed `$wpdb->insert`
     * returning false (truncation, schema mismatch, charset error, dropped
     * connection) and returned `(int) $wpdb->insert_id` which is `0` after a
     * failure. The caller in RestApi::handleLead would still respond
     * `success: true, lead_id: 0` and the lead vanished with no audit trail.
     * This is the prime suspect for the plugin-vs-Hyros undercount.
     *
     * Now: if `$wpdb->insert` returns false, log the row + `$wpdb->last_error`
     * and throw a RuntimeException so the REST handler returns 500.
     */
    public function create(array $data): int
    {
        global $wpdb;
        $now  = \current_time('mysql');
        $row  = \array_merge([
            'make_status'  => self::MAKE_STATUS_PENDING,
            'make_attempts' => 0,
            'created_at'   => $now,
        ], $data);

        $result = $wpdb->insert($this->table(), $row);
        if ($result === false) {
            $this->logger?->error('leads.repo.insert', 'wpdb->insert returned false', [
                'wpdb_last_error' => (string) $wpdb->last_error,
                'test_id'         => $row['test_id']    ?? null,
                'variant_id'      => $row['variant_id'] ?? null,
                'visitor_id'      => $row['visitor_id'] ?? null,
                'lead_stage'      => $row['lead_stage'] ?? null,
                'email'           => $row['email']      ?? null,
                'phone'           => $row['phone']      ?? null,
            ]);
            throw new \RuntimeException('Lead DB insert failed: ' . (string) $wpdb->last_error);
        }
        $id = (int) $wpdb->insert_id;
        $this->logger?->info('leads.repo.insert', 'lead inserted', [
            'lead_id'    => $id,
            'test_id'    => $row['test_id']    ?? null,
            'variant_id' => $row['variant_id'] ?? null,
            'lead_stage' => $row['lead_stage'] ?? null,
        ]);
        return $id;
    }

    /**
     * v1.0.23: find a recent lead matching the same submission so we can
     * suppress duplicate inserts caused by the path-b.js sendBeacon fallback
     * (fetch + beacon racing). Match heuristic:
     *   - same email (when present), OR same phone (when present)
     *   - AND same visitor_id (when both rows have one) OR within $windowSec
     * Returns the matching lead id, or 0 if none found.
     */
    public function findRecentDuplicate(string $email, string $phone, string $visitorId, int $windowSec = 300): int
    {
        global $wpdb;
        if ($email === '' && $phone === '') {
            return 0;
        }
        $table  = $this->table();
        $window = (int) \max(60, $windowSec);
        // Build WHERE: (email match OR phone match) AND created_at within window.
        // visitor_id is an additional disambiguator but not required (sendBeacon
        // fires the same JSON, so visitor_id will match exactly when present).
        $clauses = [];
        $args    = [];
        if ($email !== '') {
            $clauses[] = 'email = %s';
            $args[]    = $email;
        }
        if ($phone !== '') {
            $clauses[] = 'phone = %s';
            $args[]    = $phone;
        }
        $where = '(' . \implode(' OR ', $clauses) . ')';
        $sql = "SELECT id FROM {$table}
                WHERE {$where}
                  AND created_at >= (NOW() - INTERVAL %d SECOND)
                ORDER BY id DESC
                LIMIT 1";
        $args[] = $window;
        $prepared = $wpdb->prepare($sql, $args);
        $id = $wpdb->get_var($prepared);
        return $id !== null ? (int) $id : 0;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    public function markForwardSuccess(int $id, ?string $response = null): void
    {
        global $wpdb;
        $wpdb->update($this->table(), [
            'make_status'       => self::MAKE_STATUS_SUCCESS,
            'make_forwarded_at' => \current_time('mysql'),
            'make_response'     => $response,
        ], ['id' => $id]);
    }

    public function markForwardSkipped(int $id, string $reason = 'skip_make flag set by client'): void
    {
        global $wpdb;
        $wpdb->update($this->table(), [
            'make_status'       => self::MAKE_STATUS_SKIPPED,
            'make_forwarded_at' => \current_time('mysql'),
            'make_response'     => $reason,
        ], ['id' => $id]);
    }

    public function markForwardFailed(int $id, string $response): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET make_status = %s, make_attempts = make_attempts + 1, make_response = %s WHERE id = %d",
            self::MAKE_STATUS_FAILED,
            $response,
            $id
        ));
    }

    public function findRetryable(int $maxAttempts = 3, int $limit = 25): array
    {
        global $wpdb;
        $table = $this->table();
        // Pick up both explicitly-failed rows AND stuck-pending rows (>=5 minutes old).
        // The latter guards against non-blocking dispatch paths that never updated
        // status, or crashes between insert and forward(). created_at is MySQL
        // datetime in WP-local time; the 5-min window is measured against NOW().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE make_attempts < %d
                   AND (
                     make_status = %s
                     OR (make_status = %s AND created_at < (NOW() - INTERVAL 5 MINUTE))
                   )
                 ORDER BY id ASC
                 LIMIT %d",
                $maxAttempts,
                self::MAKE_STATUS_FAILED,
                self::MAKE_STATUS_PENDING,
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }

    public function countFailed(int $maxAttempts = 3): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE make_attempts < %d
               AND (
                 make_status = %s
                 OR (make_status = %s AND created_at < (NOW() - INTERVAL 5 MINUTE))
               )",
            $maxAttempts,
            self::MAKE_STATUS_FAILED,
            self::MAKE_STATUS_PENDING
        ));
    }

    public function query(array $filters, int $page = 1, int $perPage = 50): array
    {
        global $wpdb;
        $table = $this->table();
        [$where, $args] = $this->buildWhere($filters);

        $offset = \max(0, ($page - 1) * $perPage);
        $sql = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }

    public function count(array $filters): int
    {
        global $wpdb;
        $table = $this->table();
        [$where, $args] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$table} {$where}";
        if ($args === []) {
            return (int) $wpdb->get_var($sql);
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
    }

    public function streamForExport(array $filters, callable $handler, int $chunkSize = 500): void
    {
        $page = 1;
        while (true) {
            $rows = $this->query($filters, $page, $chunkSize);
            if (\count($rows) === 0) {
                return;
            }
            foreach ($rows as $row) {
                $handler($row);
            }
            if (\count($rows) < $chunkSize) {
                return;
            }
            $page++;
        }
    }

    private function buildWhere(array $filters): array
    {
        global $wpdb;
        $clauses = [];
        $args    = [];

        if (!empty($filters['test_id'])) {
            $clauses[] = 'test_id = %d';
            $args[]    = (int) $filters['test_id'];
        }
        if (!empty($filters['variant_id'])) {
            $clauses[] = 'variant_id = %d';
            $args[]    = (int) $filters['variant_id'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= %s';
            $args[]    = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= %s';
            $args[]    = (string) $filters['to'];
        }
        if (!empty($filters['lead_stage'])) {
            $clauses[] = 'lead_stage = %s';
            $args[]    = (string) $filters['lead_stage'];
        }
        if (!empty($filters['utm_source'])) {
            $clauses[] = 'utm_source = %s';
            $args[]    = (string) $filters['utm_source'];
        }
        if (!empty($filters['state'])) {
            $clauses[] = 'state = %s';
            $args[]    = \strtolower((string) $filters['state']);
        }
        if (!empty($filters['email'])) {
            $clauses[] = 'email LIKE %s';
            $args[]    = '%' . $wpdb->esc_like((string) $filters['email']) . '%';
        }
        if (!empty($filters['phone'])) {
            $digits = \preg_replace('/\D+/', '', (string) $filters['phone']) ?? '';
            if ($digits !== '') {
                $clauses[] = 'phone LIKE %s';
                $args[]    = '%' . $wpdb->esc_like($digits) . '%';
            }
        }
        if (!empty($filters['source'])) {
            $source = (string) $filters['source'];
            if ($source === '__null__') {
                $clauses[] = '(source IS NULL OR source = %s)';
                $args[]    = '';
            } else {
                $clauses[] = 'source = %s';
                $args[]    = $source;
            }
        }

        $where = $clauses === [] ? '' : 'WHERE ' . \implode(' AND ', $clauses);
        return [$where, $args];
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

    /**
     * Return rows with lead_stage='unknown' that still have a raw_payload
     * available, so the reprocessor can parse them again with the current
     * parser/stage logic. Limited to 500 per batch to keep memory bounded.
     */
    public function findUnknownByTestId(int $testId, int $limit = 500): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, raw_payload FROM {$table}
                 WHERE test_id = %d AND lead_stage = %s AND raw_payload IS NOT NULL AND raw_payload <> ''
                 ORDER BY id ASC
                 LIMIT %d",
                $testId,
                'unknown',
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }

    /**
     * Update a lead's parsed columns + lead_stage from a (re-)parse.
     * Only writes columns whose key is present in $fields, so we don't
     * blow away existing meta (test_id, visitor_id, ip_hash, etc).
     */
    public function updateParsedFields(int $id, array $fields, string $stage): bool
    {
        global $wpdb;
        $table = $this->table();

        $allowed = [
            'service_type', 'attorney', 'fault', 'injury', 'timeframe',
            'state', 'zipcode', 'insured', 'first_name', 'last_name',
            'email', 'phone', 'describe_accident', 'twilio_lookup_status', 'trustedform_cert_url',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'utm_adname', 'utm_adid', 'utm_adsetid',
            'utm_adsetname', 'utm_campaignid', 'utm_placement',
            'utm_sitesourcename', 'utm_creative', 'utm_state', 'clickid',
        ];

        $update = [];
        foreach ($allowed as $col) {
            if (\array_key_exists($col, $fields)) {
                $update[$col] = $fields[$col];
            }
        }
        $update['lead_stage'] = $stage;

        $result = $wpdb->update($table, $update, ['id' => $id]);
        return $result !== false;
    }
}
