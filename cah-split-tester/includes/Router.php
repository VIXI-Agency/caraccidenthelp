<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\TestsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Router
{
    public const QUERY_VAR_MATCH        = 'cah_split_variant';
    public const QUERY_VAR_TEST_SLUG    = 'cah_test_slug';
    public const QUERY_VAR_VARIANT_SLUG = 'cah_variant_slug';

    public const COOKIE_PREFIX = 'cah_variant_';

    public function __construct(
        private readonly TestsRepository $tests,
        private readonly VariantsRepository $variants,
        private readonly Settings $settings,
        private readonly VariantRenderer $renderer,
    ) {
    }

    public function boot(): void
    {
        \add_action('init', [$this, 'registerRewriteRules']);
        \add_filter('query_vars', [$this, 'registerQueryVars']);
        \add_action('init', [$this, 'maybeFlush'], 20);
        \add_action('template_redirect', [$this, 'handleRequest'], 1);
    }

    public function registerRewriteRules(): void
    {
        \add_rewrite_rule(
            '^_cah/v/([^/]+)/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR_MATCH . '=1&'
                . self::QUERY_VAR_TEST_SLUG . '=$matches[1]&'
                . self::QUERY_VAR_VARIANT_SLUG . '=$matches[2]',
            'top'
        );
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = self::QUERY_VAR_MATCH;
        $vars[] = self::QUERY_VAR_TEST_SLUG;
        $vars[] = self::QUERY_VAR_VARIANT_SLUG;
        return $vars;
    }

    public function maybeFlush(): void
    {
        if (\get_option(Activator::FLUSH_FLAG_OPTION) !== '1') {
            return;
        }
        \flush_rewrite_rules(false);
        \delete_option(Activator::FLUSH_FLAG_OPTION);
    }

    public function handleRequest(): void
    {
        if ((int) \get_query_var(self::QUERY_VAR_MATCH) === 1) {
            $this->renderVariantRoute();
            return;
        }

        if (\is_admin()) {
            return;
        }

        $path = $this->currentPath();
        if ($path === null) {
            return;
        }

        $test = $this->tests->activeByTriggerPath($path);
        if ($test === null) {
            return;
        }

        $this->routeToVariant((int) $test['id']);
    }

    private function renderVariantRoute(): void
    {
        $testSlug    = (string) \get_query_var(self::QUERY_VAR_TEST_SLUG);
        $variantSlug = (string) \get_query_var(self::QUERY_VAR_VARIANT_SLUG);

        $test = $this->tests->findBySlug($testSlug);
        if ($test === null) {
            \status_header(404);
            exit;
        }

        $variant = $this->variants->findByTestAndSlug((int) $test['id'], $variantSlug);
        if ($variant === null || empty($variant['html_file'])) {
            \status_header(404);
            exit;
        }

        $visitorId = $this->ensureVisitorCookie((int) $test['id'], (int) $variant['id']);

        $this->renderer->render($test, $variant, $visitorId);
        exit;
    }

    private function routeToVariant(int $testId): void
    {
        $cookie = $this->readCookie($testId);
        $variant = null;

        if ($cookie !== null) {
            $variant = $this->variants->find($cookie['variant_id']);
            if ($variant === null
                || (int) $variant['test_id'] !== $testId
                || (int) $variant['weight'] <= 0) {
                $variant = null;
            }
        }

        if ($variant === null) {
            $variant = $this->variants->pickWeighted($testId);
        }

        if ($variant === null) {
            return;
        }

        $visitorId = $cookie['visitor_id'] ?? $this->generateVisitorId();
        $this->writeCookie($testId, (int) $variant['id'], $visitorId);

        $this->sendNoCacheHeaders('router-redirect');

        $target = $this->appendQueryString((string) $variant['url']);
        \wp_redirect($target, 302);
        exit;
    }

    /**
     * Aggressively mark the current response as non-cacheable across CDNs/caches.
     * LiteSpeed was previously serving the 302 + variant pages from edge cache, which
     * baked `visitor_id` into HTML and prevented Set-Cookie from reaching the browser.
     * We now call the LiteSpeed ESI action hook in addition to WP's nocache_headers()
     * and the raw X-LiteSpeed-Cache-Control header, so LSCache bypasses this request
     * even when its own cache rules would otherwise hit.
     */
    private function sendNoCacheHeaders(string $reason): void
    {
        \nocache_headers();
        if (!\headers_sent()) {
            \header('X-LiteSpeed-Cache-Control: no-cache');
            \header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true);
            \header('Pragma: no-cache', true);
        }
        // LSCache public API — no-ops if LiteSpeed Cache plugin is inactive.
        if (\function_exists('do_action')) {
            \do_action('litespeed_control_set_nocache', 'cah-split:' . $reason);
            \do_action('litespeed_control_set_private', 'cah-split:' . $reason);
        }
    }

    private function ensureVisitorCookie(int $testId, int $variantId): string
    {
        $cookie = $this->readCookie($testId);
        if ($cookie !== null && $cookie['variant_id'] === $variantId) {
            return $cookie['visitor_id'];
        }
        $visitorId = $cookie['visitor_id'] ?? $this->generateVisitorId();
        $this->writeCookie($testId, $variantId, $visitorId);
        return $visitorId;
    }

    private function readCookie(int $testId): ?array
    {
        $name = self::COOKIE_PREFIX . $testId;
        if (empty($_COOKIE[$name])) {
            return null;
        }
        $raw = (string) $_COOKIE[$name];
        if (!\str_contains($raw, '.')) {
            return null;
        }
        [$variantPart, $visitorPart] = \explode('.', $raw, 2);
        $variantId = (int) $variantPart;
        $visitorId = \sanitize_text_field($visitorPart);
        if ($variantId <= 0 || !$this->isValidUuid($visitorId)) {
            return null;
        }
        return ['variant_id' => $variantId, 'visitor_id' => $visitorId];
    }

    private function writeCookie(int $testId, int $variantId, string $visitorId): void
    {
        if (\headers_sent()) {
            return;
        }
        $name  = self::COOKIE_PREFIX . $testId;
        $value = $variantId . '.' . $visitorId;
        $expires = \time() + $this->settings->cookieTtlSeconds();
        \setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => \defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain'   => \defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => \is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }

    private function generateVisitorId(): string
    {
        return \wp_generate_uuid4();
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function currentPath(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') {
            return null;
        }
        $parsed = \wp_parse_url((string) $uri);
        $path   = $parsed['path'] ?? '/';
        $path   = '/' . \ltrim((string) $path, '/');
        if ($path !== '/' && \str_ends_with($path, '/')) {
            $path = \rtrim($path, '/');
        }
        return $path;
    }

    private function appendQueryString(string $target): string
    {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if ($query === '') {
            return $target;
        }
        $separator = \str_contains($target, '?') ? '&' : '?';
        return $target . $separator . $query;
    }
}
