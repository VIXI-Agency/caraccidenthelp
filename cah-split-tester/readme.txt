=== CAH Split Tester ===
Contributors: vixi-agency
Tags: a/b testing, split testing, lead generation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 1.0.3
License: Proprietary

Generic A/B/N split testing for caraccidenthelp.net. WordPress is the source of truth for leads; Make.com is forwarded server-side after the lead is persisted.

== Description ==

Single-client plugin built for Triggerfish Leads LLC by VIXI Agency.

* Router intercepts a configured trigger path and 302s to a weighted-random variant.
* Variant HTML files live inside the plugin and are served via a plugin-rendered route with tracking injected server-side.
* Pageviews and full form payloads are persisted to custom tables in WordPress.
* Existing Make.com scenario keeps receiving the same payload shape, forwarded server-side after the lead is saved, with retry via cron on failure.
* Admin dashboard is observational only — no auto-pause, no auto-winner, no auto-rebalance.

== Installation ==

1. Upload the `cah-split-tester` directory to `/wp-content/plugins/`.
2. Activate through the "Plugins" menu in WordPress.
3. Go to **Split Tester -> Settings** and confirm the Make.com webhook URL and cookie TTL.

== Development ==

PHP 8.1+, strict types, namespace `VIXI\CahSplit`, PSR-4 autoload defined in `composer.json`.

The plugin ships with a hand-rolled PSR-4 autoloader used as a fallback when no `vendor/autoload.php` is present, so it works when unzipped into a WP install with no extra steps. To regenerate an optimized Composer classmap, run:

    composer dump-autoload -o

from the plugin root. No runtime dependencies are required.

== Changelog ==

= 1.0.3 =
* Bundle the site's public/ asset directory inside the plugin at variants/public/ and inject <base href="{plugin_url}variants/"> into every plugin-hosted variant so relative image/style URLs like public/logo-full.webp resolve correctly regardless of the variant URL path (was broken: relative paths resolved to /_cah/v/.../public/... and 404'd).
* Router: when a visitor's cookie points at a variant whose weight is now 0, re-bucket instead of sticking. Previously a visitor bucketed to variant A before variant A's weight was set to 0 stayed on A forever, bypassing weighted selection among currently-active variants.

= 1.0.2 =
* Router and VariantRenderer now emit `nocache_headers()` plus `X-LiteSpeed-Cache-Control: no-cache` so LiteSpeed Cache (and any other compliant proxy) does not cache trigger-path redirects or variant HTML. Without this, cached responses would send the same variant and the same `window.cahSplit` context (same `visitor_id`) to every subsequent visitor, breaking weighted bucketing and per-visitor tracking. Encountered live on a LiteSpeed-hosted staging site.

= 1.0.1 =
* Replace the HTML-file text input with a dropdown of files actually present in the plugin's variants/ directory, so admins cannot save a filename that doesn't exist on disk. The dropdown includes an "External URL" option for off-file variants and preserves any previously-saved filename marked as "(missing)" so it can be corrected.
* VariantRenderer error message now reports the filename it tried to load and lists the files currently available, making misconfiguration self-diagnosing.
* Admin JS reindexes select elements (not just inputs) when rows are added or removed.

= 1.0.0 =
* Phase 6: two-proportion z-test significance badge (informational only, no auto-actions) on test detail; manual "Prune old pageviews" tool in Settings; manual "Retry failed Make.com forwards" button in Settings and Dashboard banner.

= 0.5.0 =
* Phase 5: leads admin page with filters (test, variant, date range, stage, utm_source, state, email, phone), paginated at 50/page with row-expand raw payload, and a streamed CSV export that respects the current filters.

= 0.4.0 =
* Phase 4: StatsRepository, real dashboard metrics, test detail view with per-variant results, Chart.js daily trend chart, and UTM source / campaign breakdowns.

= 0.3.0 =
* Phase 3: /wp-json/cah-split/v1/lead endpoint persists leads to WP with the 5-rule server-side lead_stage computation, MakeForwarder pushes to Make.com with cah_lead_id correlation, hourly wp_cron retries failed forwards up to 3 times, /pageview endpoint logs impressions.

= 0.2.0 =
* Phase 2: template_redirect router with weighted variant selection + cookie persistence + query-string preservation, plugin-rendered /_cah/v/{test-slug}/{variant-slug}/ route with server-side tracking injection, full tests CRUD (list, edit, clone, delete, toggle status) with weight-sum validation, and migration of index.html into variants/v1.html with the single allowed submit-endpoint edit.

= 0.1.0 =
* Phase 1: plugin bootstrap, activation migrations for the four tables (tests, variants, pageviews, leads), admin menu with Dashboard / Tests / Leads / Settings pages, functional Settings page (Make webhook URL, cookie TTL, drop-tables-on-uninstall toggle, auto-generated IP hash salt).
