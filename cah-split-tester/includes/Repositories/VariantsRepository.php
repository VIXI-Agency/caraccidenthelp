<?php

declare(strict_types=1);

namespace VIXI\CahSplit\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class VariantsRepository
{
    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cah_variants';
    }

    public function all(): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results(
            "SELECT v.*, t.name AS test_name FROM {$table} v
             LEFT JOIN {$wpdb->prefix}cah_tests t ON t.id = v.test_id
             ORDER BY t.name ASC, v.sort_order ASC, v.id ASC",
            ARRAY_A
        );
        if (!\is_array($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['name'] = ($row['test_name'] ?? '') . ' / ' . ($row['name'] ?? '');
        }
        return $rows;
    }

    public function forTest(int $testId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE test_id = %d ORDER BY sort_order ASC, id ASC",
                $testId
            ),
            ARRAY_A
        );
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

    public function findByTestAndSlug(int $testId, string $slug): ?array
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE test_id = %d AND slug = %s LIMIT 1",
                $testId,
                $slug
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    public function replaceAll(int $testId, string $testSlug, array $variants): void
    {
        $weights = \array_map(static fn(array $v): int => (int) ($v['weight'] ?? 0), $variants);
        $sum     = \array_sum($weights);
        $visible = \array_filter($variants, static fn(array $v): bool => (string) ($v['name'] ?? '') !== '');

        if (\count($visible) === 0) {
            throw new \InvalidArgumentException(\__('At least one variant is required.', 'cah-split'));
        }
        if ($sum !== 100) {
            throw new \RuntimeException(\sprintf(
                /* translators: %d: current weight sum */
                \__('Variant weights must sum to 100. Current sum is %d.', 'cah-split'),
                $sum
            ));
        }

        global $wpdb;
        $table = $this->table();

        $wpdb->delete($table, ['test_id' => $testId]);

        $now      = \current_time('mysql');
        $usedSlugs = [];
        foreach (\array_values($visible) as $index => $variant) {
            $name = \sanitize_text_field((string) ($variant['name'] ?? ''));
            $slug = \sanitize_title((string) ($variant['slug'] ?? $name));
            if ($slug === '') {
                $slug = 'variant-' . ($index + 1);
            }
            $originalSlug = $slug;
            $suffix = 1;
            while (\in_array($slug, $usedSlugs, true)) {
                $slug = $originalSlug . '-' . (++$suffix);
            }
            $usedSlugs[] = $slug;

            $htmlFile = \trim((string) ($variant['html_file'] ?? ''));
            if ($htmlFile !== '') {
                $htmlFile = \ltrim($htmlFile, '/');
                $htmlFile = \basename($htmlFile);
                $url      = '/_cah/v/' . $testSlug . '/' . $slug . '/';
            } else {
                $htmlFile = null;
                $url      = \esc_url_raw((string) ($variant['url'] ?? ''));
            }

            if ($url === '' || $url === null) {
                throw new \InvalidArgumentException(\sprintf(
                    /* translators: %s: variant name */
                    \__('Variant "%s" needs either an HTML file or an external URL.', 'cah-split'),
                    $name
                ));
            }

            $wpdb->insert($table, [
                'test_id'    => $testId,
                'name'       => $name,
                'slug'       => $slug,
                'url'        => $url,
                'html_file'  => $htmlFile,
                'weight'     => (int) ($variant['weight'] ?? 0),
                'sort_order' => (int) ($variant['sort_order'] ?? $index),
                'created_at' => $now,
            ]);
        }
    }

    public function pickWeighted(int $testId): ?array
    {
        $variants = $this->forTest($testId);
        if (\count($variants) === 0) {
            return null;
        }
        $pool   = \array_values(\array_filter(
            $variants,
            static fn(array $v): bool => (int) $v['weight'] > 0
        ));
        if (\count($pool) === 0) {
            return $variants[0];
        }
        $totalWeight = 0;
        foreach ($pool as $variant) {
            $totalWeight += (int) $variant['weight'];
        }
        $roll = \random_int(1, $totalWeight);
        $cum  = 0;
        foreach ($pool as $variant) {
            $cum += (int) $variant['weight'];
            if ($roll <= $cum) {
                return $variant;
            }
        }
        return $pool[\array_key_last($pool)];
    }
}
