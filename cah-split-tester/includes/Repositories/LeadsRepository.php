<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadsRepository
{
    public const MAKE_STATUS_PENDING = 'pending';
    public const MAKE_STATUS_SUCCESS = 'success';
    public const MAKE_STATUS_FAILED  = 'failed';
    public const MAKE_STATUS_SKIPPED = 'skipped';

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_leads';
    }

    public function create(array $data): int
    {
        global $wpdb;
        $now  = \current_time('mysql');
        $row  = \array_merge([
            'make_status'  => self::MAKE_STATUS_PENDING,
            'make_attempts' => 0,
            'created_at'   => $now,
        ], $data);

        $wpdb->insert($this->table(), $row);
        return (int) $wpdb->insert_id;
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
            'email', 'phone', 'describe_accident', 'trustedform_cert_url',
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
