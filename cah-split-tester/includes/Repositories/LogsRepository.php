<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class LogsRepository
{
    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_log';
    }

    public function insert(string $level, string $source, string $message, ?string $context): void
    {
        global $wpdb;
        $wpdb->insert($this->table(), [
            'level'      => $level,
            'source'     => $source,
            'message'    => $message,
            'context'    => $context,
            'created_at' => \current_time('mysql'),
        ]);
    }

    /**
     * @param array{level?:string, source?:string, search?:string, from?:string, to?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function query(array $filters, int $page = 1, int $perPage = 100): array
    {
        global $wpdb;
        $table          = $this->table();
        [$where, $args] = $this->buildWhere($filters);
        $offset         = \max(0, ($page - 1) * $perPage);

        $sql    = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array{level?:string, source?:string, search?:string, from?:string, to?:string} $filters
     */
    public function count(array $filters): int
    {
        global $wpdb;
        $table          = $this->table();
        [$where, $args] = $this->buildWhere($filters);
        $sql            = "SELECT COUNT(*) FROM {$table} {$where}";
        if ($args === []) {
            return (int) $wpdb->get_var($sql);
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
    }

    /**
     * @return array<string,int>  source => count
     */
    public function countBySource(int $hours = 24): array
    {
        global $wpdb;
        $table = $this->table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source, COUNT(*) AS total
                 FROM {$table}
                 WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL %d HOUR)
                 GROUP BY source
                 ORDER BY total DESC",
                $hours
            ),
            ARRAY_A
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(string) $row['source']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * @return array<string,int>  level => count
     */
    public function countByLevel(int $hours = 24): array
    {
        global $wpdb;
        $table = $this->table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT level, COUNT(*) AS total
                 FROM {$table}
                 WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL %d HOUR)
                 GROUP BY level",
                $hours
            ),
            ARRAY_A
        );
        $out = ['info' => 0, 'warn' => 0, 'error' => 0];
        foreach ((array) $rows as $row) {
            $out[(string) $row['level']] = (int) $row['total'];
        }
        return $out;
    }

    public function pruneOlderThanDays(int $days): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)",
                $days
            )
        );
    }

    public function truncate(): int
    {
        global $wpdb;
        $table = $this->table();
        // TRUNCATE requires extra privileges on some shared hosts; DELETE FROM
        // is portable and the table never holds a huge volume because of the
        // pruning cron.
        return (int) $wpdb->query("DELETE FROM {$table}");
    }

    /**
     * @return array{0:string, 1:array<int,scalar>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $args    = [];

        if (!empty($filters['level'])) {
            $clauses[] = 'level = %s';
            $args[]    = (string) $filters['level'];
        }
        if (!empty($filters['source'])) {
            $clauses[] = 'source = %s';
            $args[]    = (string) $filters['source'];
        }
        if (!empty($filters['search'])) {
            global $wpdb;
            $clauses[] = '(message LIKE %s OR context LIKE %s)';
            $like      = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $args[]    = $like;
            $args[]    = $like;
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= %s';
            $args[]    = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= %s';
            $args[]    = (string) $filters['to'];
        }

        $where = $clauses === [] ? '' : 'WHERE ' . \implode(' AND ', $clauses);
        return [$where, $args];
    }
}
