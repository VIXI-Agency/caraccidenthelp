<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PageviewsRepository
{
    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_pageviews';
    }

    public function create(array $data): int
    {
        global $wpdb;
        $row = \array_merge(['created_at' => \current_time('mysql')], $data);
        $wpdb->insert($this->table(), $row);
        return (int) $wpdb->insert_id;
    }

    public function pruneOlderThan(string $beforeDate): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $beforeDate
        ));
    }

    public function countOlderThan(string $beforeDate): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at < %s",
            $beforeDate
        ));
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
}
