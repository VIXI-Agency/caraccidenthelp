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
        $path = CAH_SPLIT_PLUGIN_DIR . 'variants/' . \basename($htmlFile);
        if (!\is_readable($path)) {
            \status_header(500);
            echo \esc_html__('Variant file is missing or unreadable.', 'cah-split');
            return;
        }

        $html = (string) \file_get_contents($path);
        $html = $this->injectTrackingScripts($html, $test, $variant, $visitorId);

        \nocache_headers();
        \header('Content-Type: text/html; charset=' . \get_bloginfo('charset'));
        echo $html;
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

        $inline = '<script>window.cahSplit = ' . \wp_json_encode($context) . ';</script>';
        $src    = \esc_url(\rest_url('cah-split/v1/tracking.js'));
        $tag    = '<script src="' . $src . '" defer></script>';

        $snippet = "\n    " . $inline . "\n    " . $tag . "\n";

        if (\stripos($html, '</head>') !== false) {
            return \preg_replace('/<\/head>/i', $snippet . '</head>', $html, 1) ?? $html;
        }
        return $snippet . $html;
    }
}
