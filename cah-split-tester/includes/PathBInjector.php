<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-injects the Path B observability script (assets/path-b.js) into the
 * /thank-you/ and /diminished-value-claim/ WordPress pages.
 *
 * Why this exists: pasting the snippet manually into the WP page body fails
 * for users without `unfiltered_html` capability — KSES strips out the
 * `<script>` tags and only the HTML comment survives. Auto-injecting
 * via `wp_footer` bypasses KSES entirely and keeps the snippet versioned
 * with the plugin.
 *
 * Path matching is derived from `LeadStage::URL_QUALIFIED` and
 * `LeadStage::URL_DISQUALIFIED` so if those constants change, the injector
 * follows automatically.
 */
final class PathBInjector
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function boot(): void
    {
        \add_action('wp_footer', [$this, 'maybeInject'], 99);
    }

    public function maybeInject(): void
    {
        if (\is_admin()) {
            return;
        }

        $current = $this->currentPath();
        if ($current === null) {
            return;
        }

        if (!\in_array($current, $this->targetPaths(), true)) {
            return;
        }

        $bootData = [
            'rest'    => \esc_url_raw(\rest_url('cah-split/v1')),
            'test_id' => 2,
        ];

        $src = CAH_SPLIT_PLUGIN_URL . 'assets/path-b.js?v=' . CAH_SPLIT_VERSION;

        echo "\n<!-- cah-split-tester Path B observability (auto-injected) -->\n";
        echo '<script>window.cahPathB = ' . \wp_json_encode($bootData) . ';</script>' . "\n";
        echo '<script src="' . \esc_url($src) . '" defer></script>' . "\n";
    }

    /**
     * @return list<string>
     */
    private function targetPaths(): array
    {
        // v1.0.22: include all three Growform redirect destinations so the
        // observability snippet captures Path B leads regardless of which
        // disqualified bucket Growform routed them to (no-injury →
        // /diminished-value-claim/, everything else → /finished/).
        $urls = [
            LeadStage::URL_QUALIFIED,
            LeadStage::URL_DISQUALIFIED_NO_INJURY,
            LeadStage::URL_DISQUALIFIED_OTHER,
        ];
        $out = [];
        foreach ($urls as $url) {
            $parsed = \wp_parse_url((string) $url);
            if (!\is_array($parsed) || !isset($parsed['path'])) {
                continue;
            }
            $path = '/' . \trim((string) $parsed['path'], '/');
            if ($path !== '/' && !\in_array($path, $out, true)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    private function currentPath(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') {
            return null;
        }
        $parsed = \wp_parse_url((string) $uri);
        if (!\is_array($parsed) || !isset($parsed['path'])) {
            return null;
        }
        return '/' . \trim((string) $parsed['path'], '/');
    }
}
