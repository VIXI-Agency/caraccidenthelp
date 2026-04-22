<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class TestsRepository
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    private const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
    ];

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_tests';
    }

    public function all(): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A);
        return \is_array($rows) ? $rows : [];
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

    public function findBySlug(string $slug): ?array
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    public function activeByTriggerPath(string $path): ?array
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE trigger_path = %s AND status = %s LIMIT 1",
                $path,
                self::STATUS_ACTIVE
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    public function save(array $data, ?int $id = null): int
    {
        global $wpdb;
        $table = $this->table();
        $now   = \current_time('mysql');

        $name         = \sanitize_text_field((string) ($data['name'] ?? ''));
        $slug         = $this->ensureUniqueSlug(
            \sanitize_title((string) ($data['slug'] ?? $name)),
            $id
        );
        $triggerPath  = $this->normalizeTriggerPath((string) ($data['trigger_path'] ?? ''));
        $status       = \in_array($data['status'] ?? '', self::STATUSES, true)
            ? (string) $data['status']
            : self::STATUS_DRAFT;

        if ($name === '' || $triggerPath === '' || $slug === '') {
            throw new \InvalidArgumentException(\__('Test name, slug, and trigger path are all required.', 'cah-split'));
        }

        if ($status === self::STATUS_ACTIVE) {
            $conflict = $this->activeByTriggerPath($triggerPath);
            if ($conflict !== null && (int) $conflict['id'] !== (int) ($id ?? 0)) {
                throw new \RuntimeException(\sprintf(
                    /* translators: %s: trigger path */
                    \__('Another active test is already using the trigger path "%s". Pause or archive it first.', 'cah-split'),
                    $triggerPath
                ));
            }
        }

        $row = [
            'name'         => $name,
            'slug'         => $slug,
            'trigger_path' => $triggerPath,
            'status'       => $status,
            'updated_at'   => $now,
        ];

        if ($id === null) {
            $row['created_at'] = $now;
            $wpdb->insert($table, $row);
            return (int) $wpdb->insert_id;
        }

        $wpdb->update($table, $row, ['id' => $id]);
        return $id;
    }

    public function updateStatus(int $id, string $status): void
    {
        global $wpdb;
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(\__('Invalid test status.', 'cah-split'));
        }
        $test = $this->find($id);
        if ($test === null) {
            return;
        }
        if ($status === self::STATUS_ACTIVE) {
            $conflict = $this->activeByTriggerPath((string) $test['trigger_path']);
            if ($conflict !== null && (int) $conflict['id'] !== $id) {
                throw new \RuntimeException(\__('Another active test is already using this trigger path.', 'cah-split'));
            }
        }
        $wpdb->update(
            $this->table(),
            ['status' => $status, 'updated_at' => \current_time('mysql')],
            ['id' => $id]
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['id' => $id]);
        $wpdb->delete($wpdb->prefix . 'cah_variants', ['test_id' => $id]);
    }

    public function clone(int $id): ?int
    {
        $source = $this->find($id);
        if ($source === null) {
            return null;
        }
        $newName = $source['name'] . ' (copy)';
        return $this->save([
            'name'         => $newName,
            'slug'         => $source['slug'] . '-copy',
            'trigger_path' => $source['trigger_path'],
            'status'       => self::STATUS_DRAFT,
        ]);
    }

    private function normalizeTriggerPath(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }
        $parsed = \wp_parse_url($raw);
        $path   = $parsed['path'] ?? $raw;
        $path   = '/' . \ltrim((string) $path, '/');
        if ($path !== '/' && \str_ends_with($path, '/')) {
            $path = \rtrim($path, '/');
        }
        return $path;
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId): string
    {
        if ($slug === '') {
            return '';
        }
        global $wpdb;
        $table    = $this->table();
        $base     = $slug;
        $attempt  = 0;
        while (true) {
            $candidate = $attempt === 0 ? $base : $base . '-' . $attempt;
            $query = $wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND id != %d LIMIT 1",
                $candidate,
                (int) ($excludeId ?? 0)
            );
            $existing = $wpdb->get_var($query);
            if ($existing === null) {
                return $candidate;
            }
            $attempt++;
            if ($attempt > 100) {
                return $base . '-' . \wp_generate_password(6, false);
            }
        }
    }
}
