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

    /**
     * Resolve a top-level pretty path to its variant row.
     *
     * Used by Router::handleRequest to render plugin-hosted variants under a
     * clean URL like `/car-accident-b/` instead of `/_cah/v/<test>/<variant>/`.
     * Only matches variants that ALSO have a usable `html_file`; variants whose
     * pretty_path was set but later switched to External URL will not resolve
     * (and the field is cleared by replaceAll() in that case).
     *
     * The lookup is case-insensitive (MySQL default collation) and trims leading
     * slashes so callers can pass either `"car-accident-b"` or `"/car-accident-b"`.
     */
    public function findByPrettyPath(string $path): ?array
    {
        $path = \trim($path, "/ \t\n\r\0\x0B");
        if ($path === '') {
            return null;
        }
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE pretty_path = %s LIMIT 1",
                $path
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    /**
     * Sync variants for a test using UPSERT semantics.
     *
     * - Rows whose POST'd id matches an existing row are UPDATED (preserves variant_id → lead FK integrity).
     * - Rows without id (newly added in the form) are INSERTED.
     * - Existing rows whose id is not present in the submitted set are DELETED.
     *
     * This replaces the pre-1.0.5 DELETE-then-INSERT behavior that caused variant_id to
     * increment on every save and broke referential integrity with historical leads.
     */
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

        // Load existing rows keyed by id so we can detect inserts vs updates vs deletes.
        $existing       = $this->forTest($testId);
        $existingById   = [];
        foreach ($existing as $row) {
            $existingById[(int) $row['id']] = $row;
        }

        $now            = \current_time('mysql');
        $usedSlugs      = [];
        $usedPrettyPaths = [];
        $keptIds        = [];

        // Build a quick lookup of pretty_paths owned by OTHER tests, to detect
        // cross-test collisions (a pretty_path is a top-level URL on the site,
        // so it must be unique across all variants regardless of test).
        $reservedAcrossTests = [];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, pretty_path FROM {$this->table()} WHERE test_id <> %d AND pretty_path IS NOT NULL AND pretty_path <> ''",
                $testId
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $r) {
            $reservedAcrossTests[(string) $r['pretty_path']] = (int) $r['id'];
        }

        foreach (\array_values($visible) as $index => $variant) {
            $name = \sanitize_text_field((string) ($variant['name'] ?? ''));
            $slug = \sanitize_title((string) ($variant['slug'] ?? $name));
            if ($slug === '') {
                $slug = 'variant-' . ($index + 1);
            }
            $originalSlug = $slug;
            $suffix       = 1;
            while (\in_array($slug, $usedSlugs, true)) {
                $slug = $originalSlug . '-' . (++$suffix);
            }
            $usedSlugs[] = $slug;

            // Sanitize pretty_path. Only meaningful when the variant is
            // plugin-hosted (has an html_file); for External URL variants we
            // store NULL so admins can't accidentally route a top-level slug to
            // a 302-redirect-only variant.
            $prettyPathRaw = \trim((string) ($variant['pretty_path'] ?? ''));
            $prettyPath    = null;
            if ($prettyPathRaw !== '') {
                $candidate = \sanitize_title(\ltrim($prettyPathRaw, '/'));
                if ($candidate !== '') {
                    $prettyPath = $candidate;
                }
            }

            $htmlFile = \trim((string) ($variant['html_file'] ?? ''));
            if ($htmlFile !== '') {
                $htmlFile = \ltrim($htmlFile, '/');
                $htmlFile = \basename($htmlFile);
                $url      = $prettyPath !== null
                    ? '/' . $prettyPath . '/'
                    : '/_cah/v/' . $testSlug . '/' . $slug . '/';
            } else {
                $htmlFile   = null;
                $url        = \esc_url_raw((string) ($variant['url'] ?? ''));
                $prettyPath = null; // pretty_path only applies to plugin-hosted variants.
            }

            if ($url === '' || $url === null) {
                throw new \InvalidArgumentException(\sprintf(
                    /* translators: %s: variant name */
                    \__('Variant "%s" needs either an HTML file or an external URL.', 'cah-split'),
                    $name
                ));
            }

            // Validate pretty_path uniqueness within this test and across tests.
            if ($prettyPath !== null) {
                if (\in_array($prettyPath, $usedPrettyPaths, true)) {
                    throw new \InvalidArgumentException(\sprintf(
                        /* translators: %s: pretty path value */
                        \__('Pretty path "%s" is used by more than one variant in this test.', 'cah-split'),
                        $prettyPath
                    ));
                }
                if (isset($reservedAcrossTests[$prettyPath])) {
                    throw new \InvalidArgumentException(\sprintf(
                        /* translators: %s: pretty path value */
                        \__('Pretty path "%s" is already used by a variant in another test.', 'cah-split'),
                        $prettyPath
                    ));
                }
                // Reject reserved system slugs to avoid hijacking core paths.
                $reservedSystem = ['wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php', '_cah', 'feed', 'sitemap', 'sitemap.xml', 'robots.txt'];
                if (\in_array($prettyPath, $reservedSystem, true)) {
                    throw new \InvalidArgumentException(\sprintf(
                        /* translators: %s: reserved slug */
                        \__('Pretty path "%s" is reserved by WordPress and cannot be used.', 'cah-split'),
                        $prettyPath
                    ));
                }
                $usedPrettyPaths[] = $prettyPath;
            }

            $submittedId = isset($variant['id']) ? (int) $variant['id'] : 0;
            $data        = [
                'test_id'     => $testId,
                'name'        => $name,
                'slug'        => $slug,
                'url'         => $url,
                'html_file'   => $htmlFile,
                'pretty_path' => $prettyPath,
                'weight'      => (int) ($variant['weight'] ?? 0),
                'sort_order'  => (int) ($variant['sort_order'] ?? $index),
            ];

            if ($submittedId > 0 && isset($existingById[$submittedId])) {
                // UPDATE: preserves id, keeps leads.variant_id FK valid.
                $wpdb->update($table, $data, ['id' => $submittedId]);
                $keptIds[] = $submittedId;
            } else {
                // INSERT: genuinely new variant.
                $data['created_at'] = $now;
                $wpdb->insert($table, $data);
                $newId = (int) $wpdb->insert_id;
                if ($newId > 0) {
                    $keptIds[] = $newId;
                }
            }
        }

        // DELETE rows that the admin removed from the form (not in $keptIds).
        $toDelete = \array_diff(\array_keys($existingById), $keptIds);
        foreach ($toDelete as $deleteId) {
            $wpdb->delete($table, ['id' => (int) $deleteId]);
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
