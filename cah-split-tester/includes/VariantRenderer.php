<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class VariantRenderer
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function render(array $test, array $variant, string $visitorId): void
    {
        $htmlFile = (string) ($variant['html_file'] ?? '');
        if ($htmlFile === '') {
            \status_header(404);
            return;
        }
        $file = \basename($htmlFile);
        $path = CAH_SPLIT_PLUGIN_DIR . 'variants/' . $file;

        if (!\file_exists($path)) {
            \status_header(500);
            $available = self::availableFiles();
            $hint = empty($available)
                ? \__('No .html files are present in the plugin\'s variants/ directory.', 'cah-split')
                : \sprintf(\__('Available files: %s', 'cah-split'), \implode(', ', $available));
            echo \esc_html(\sprintf(
                /* translators: %1$s: html file name, %2$s: hint listing available files */
                \__('Variant file "%1$s" was not found in the plugin\'s variants/ directory. %2$s', 'cah-split'),
                $file,
                $hint
            ));
            return;
        }
        if (!\is_readable($path)) {
            \status_header(500);
            echo \esc_html(\sprintf(
                /* translators: %s: html file name */
                \__('Variant file "%s" exists but is not readable. Check file permissions.', 'cah-split'),
                $file
            ));
            return;
        }

        $html = (string) \file_get_contents($path);
        $html = $this->injectTrackingScripts($html, $test, $variant, $visitorId);

        // Variant HTML contains per-visitor visitor_id baked into window.cahSplit,
        // so it MUST NOT be cached at the edge — otherwise every visitor gets the
        // first-visitor's UUID and Set-Cookie never reaches the browser.
        \nocache_headers();
        if (!\headers_sent()) {
            \header('X-LiteSpeed-Cache-Control: no-cache');
            \header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true);
            \header('Pragma: no-cache', true);
        }
        if (\function_exists('do_action')) {
            \do_action('litespeed_control_set_nocache', 'cah-split:variant-render');
            \do_action('litespeed_control_set_private', 'cah-split:variant-render');
        }
        \header('Content-Type: text/html; charset=' . \get_bloginfo('charset'));
        echo $html;
    }

    public static function availableFiles(): array
    {
        $dir = CAH_SPLIT_PLUGIN_DIR . 'variants/';
        $matches = \glob($dir . '*.html');
        if (!\is_array($matches)) {
            return [];
        }
        $out = [];
        foreach ($matches as $path) {
            $out[] = \basename($path);
        }
        \sort($out);
        return $out;
    }

    private function injectTrackingScripts(string $html, array $test, array $variant, string $visitorId): string
    {
        $context = [
            'test_id'      => (int) $test['id'],
            'test_slug'    => (string) $test['slug'],
            'variant_id'   => (int) $variant['id'],
            'variant_slug' => (string) $variant['slug'],
            'visitor_id'   => $visitorId,
            'rest_base'    => \esc_url_raw(\rest_url('cah-split/v1')),
            'nonce'        => \wp_create_nonce('wp_rest'),
        ];

        $variantsUrl = CAH_SPLIT_PLUGIN_URL . 'variants/';
        $base   = '<base href="' . \esc_url($variantsUrl) . '">';
        $inline = '<script>window.cahSplit = ' . \wp_json_encode($context) . ';</script>';
        $src    = \esc_url(\rest_url('cah-split/v1/tracking.js'));
        $tag    = '<script src="' . $src . '" defer></script>';

        $snippet = "\n    " . $base . "\n    " . $inline . "\n    " . $tag . "\n";

        if (\preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + \strlen($m[0][0]);
            return \substr($html, 0, $offset) . $snippet . \substr($html, $offset);
        }
        return $snippet . $html;
    }
}
