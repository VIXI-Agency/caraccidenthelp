=== CAH Split Tester ===
Contributors: vixi-agency
Tags: a/b testing, split testing, lead generation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 0.1.0
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

= 0.1.0 =
* Phase 1 scaffolding: plugin bootstrap, activation migrations for the four tables (tests, variants, pageviews, leads), admin menu with Dashboard / Tests / Leads / Settings pages, functional Settings page (Make webhook URL, cookie TTL, drop-tables-on-uninstall toggle, auto-generated IP hash salt).
