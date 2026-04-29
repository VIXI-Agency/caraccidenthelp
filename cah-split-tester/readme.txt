=== CAH Split Tester ===
Contributors: vixi-agency
Tags: a/b testing, split testing, lead generation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 1.0.13
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

= 1.0.13 =
* Added an optional **Pretty path** field per variant. When set, a plugin-hosted variant (one with an `HTML file` selected) is served at a clean top-level URL like `/car-accident-b/` instead of the default `/_cah/v/<test-slug>/<variant-slug>/` path. The plugin renders the variant in place (no 302 redirect) so the address bar stays on the pretty URL.
* Pretty paths only resolve when WordPress itself has no real page/post matching the URL (`is_404()`). Any existing WP page or post with that slug always wins, so the field is safe to use without risking hijack of real content.
* Pretty paths are validated server-side: trimmed, sanitized via `sanitize_title()`, deduplicated within the test, and checked against pretty paths in other tests as well as a small reserved-slug list (`wp-admin`, `wp-content`, `wp-includes`, `wp-json`, `wp-login.php`, `_cah`, `feed`, `sitemap`, `sitemap.xml`, `robots.txt`). Pretty paths only apply to variants with an HTML file; choosing External URL clears the stored value automatically.
* DB migration: new `pretty_path VARCHAR(190) DEFAULT NULL` column on `wp_cah_variants` plus `KEY idx_pretty_path (pretty_path)`. `dbDelta` handles this on plugin update — no manual SQL required.
* Admin form: new "Pretty path" column on the variants editor. Empty = keep the default `/_cah/v/...` URL. Add-variant button JS updated to include the field. Helper text updated.
* `VariantsRepository::findByPrettyPath()` exposes the lookup. `Router::handleRequest()` calls it before the trigger-path branch and renders the matched variant directly with `status_header(200)` and the existing no-cache headers used elsewhere in the router (`X-LiteSpeed-Cache-Control: no-cache` + `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`).
* For variants whose `html_file` is set, the stored `url` column is now `/<pretty_path>/` when a pretty path exists, otherwise the legacy `/_cah/v/<test>/<variant>/`. The legacy URL keeps working as a hidden alias because the existing rewrite rule (`^_cah/v/...`) is unchanged.

= 1.0.12 =
* Added a **Dashboard timezone** selector to the plugin Settings page. The dashboard now interprets date-range filters and buckets the per-day chart in the chosen zone instead of always-UTC. Lead and pageview rows continue to be stored in UTC; this change only affects how stored timestamps are read and displayed.
* New `dashboard_timezone` option, default `'site'` (= `wp_timezone()`). Whitelisted choices: site default, UTC, and the seven IANA US zones (`America/New_York`, `America/Chicago`, `America/Denver`, `America/Phoenix`, `America/Los_Angeles`, `America/Anchorage`, `Pacific/Honolulu`).
* `Settings::dashboardTimezone()` returns a resolved `\DateTimeZone` instance with a safe fallback chain (chosen value -> site timezone -> UTC).
* `Admin::parseDateRange()` now computes default "from" / "to" in the dashboard timezone instead of WP site time / UTC.
* `StatsRepository` accepts an optional `Settings` injection. `overview()`, `quickStatsForTests()`, `perVariant()`, and `byUtm()` convert local date strings to UTC for `WHERE created_at` clauses. `dailySeries()` groups via `DATE(created_at + INTERVAL <offset> SECOND)` so per-day buckets line up with the chosen zone without depending on populated MySQL `mysql.time_zone_name` tables (Hostinger ships them empty). DST-boundary days inherit the offset that is current at query time, which is acceptable for an observational dashboard.
* Settings UI shows the resolved current local time alongside the dropdown so the chosen value can be sanity-checked at a glance.

= 1.0.11 =
* Added "Re-process unknown leads" button on the test edit screen (Maintenance section, sibling to Danger zone). Iterates every lead with stage `unknown` for the test, decodes its stored `raw_payload`, and re-runs the current `LeadPayloadParser::parse()` + `LeadStage::compute()` pipeline against it, mirroring the body-shape handling in `RestApi::handleLead()` (supports both `make_payload` shape and the `skip_make` / `form_meta.fields` fallback shape). Updates only the parsed columns (`service_type`, `attorney`, `fault`, `injury`, `timeframe`, name, email, phone, state, zipcode, UTM set, TrustedForm cert URL) plus `lead_stage` — meta columns (`test_id`, `visitor_id`, `ip_hash`, `created_at`, `make_*`) are preserved. Idempotent and safe to run repeatedly. Primary use case: recover historical leads stored as `unknown` before the v1.0.7 / v1.0.9 parser fixes shipped, so dashboard CR for early variants reflects real qualification rates.
* New repository methods: `LeadsRepository::findUnknownByTestId(int $testId, int $limit = 500)` returns up to N rows with `lead_stage='unknown'` AND `raw_payload IS NOT NULL` for a given test, oldest first; `LeadsRepository::updateParsedFields(int $id, array $fields, string $stage)` writes only the whitelisted parsed columns + `lead_stage`.
* New service: `VIXI\CahSplit\LeadReprocessor` returns per-batch stats `{scanned, updated, qualified, disqualified, still_unknown, skipped, errors}`. Surfaced in the admin success notice after a run.
* New admin-post action `cah_split_reprocess_unknown` (capability: `manage_options`, nonce-protected).

= 1.0.10 =
* Dashboard: added "Unique" column next to Pageviews on both the main Tests overview and the per-test detail page. Unique = distinct visitor_ids (cookie-based) over the same window. The pageviews÷unique multiplier is shown in parentheses (e.g. `1.4x`) so refresh-rage / bot-style traffic anomalies are visible at a glance. CR remains pageview-based (industry standard, matches Google Optimize / VWO / Optimizely behaviour) so refresh-driven friction reflected in the denominator.
* Overview metric "Pageviews (last N days)" now shows total unique visitors as a sub-label.
* StatsRepository::overview(), quickStatsForTests() and perVariant() now include `unique_visitors` via `COUNT(DISTINCT visitor_id)`. The visitor_id index on cah_pageviews keeps the count cheap.
* Default `cookie_ttl_days` is now 30 (was 60). Existing installs keep their saved value — update via Settings if you want the new default.

= 1.0.9 =
* Critical fix: `LeadPayloadParser::isFlatAssoc()` now detects payload shape from VALUES instead of keys. Previously, when Make.com sent `fields` as an associative object (keyed by Growform field IDs like `buttons_485431231808561`, `text_921418548778799`, `hidden_clickid`) the parser misclassified the payload as flat querystring shape because the keys are not sequential integers. That dispatched real Make.com submissions to `parseFlat()` which looks for keys like `firstName`/`email`/`state`, found none, and stored the lead as `unknown` with no name/email/phone/state/service. Detection now scans values: if any entry is a field-object containing `label`, `type`, or `value`, the whole payload is parsed as Make shape via the original `parse()` path. Flat shape (scalar values like `firstName=Jose`) still routes to `parseFlat()`. Effect: leads from real Growform iframe submissions on Variant 3 "Control" (and any future variant that submits via Make webhook with the field-object shape) will now show real name/email/phone/state/service in the dashboard and be classified correctly by `LeadStage::compute()`.

= 1.0.8 =
* Added "Reset test stats" button in the test edit screen (Danger zone). Deletes every pageview and lead recorded for the test while preserving variants, weights, trigger path and Make.com configuration. Useful for starting a clean measurement after fixing tracking issues without touching SQL.
* New repository methods: `LeadsRepository::deleteByTestId()` and `PageviewsRepository::deleteByTestId()`.
* New admin-post action `cah_split_reset_test_stats` (capability: manage_options, nonce-protected).

= 1.0.7 =
* Fix: `LeadPayloadParser::parse()` now auto-detects between two payload shapes and dispatches to the right parser. The original Make.com Growform shape (a list of `['label'=>'...', 'value'=>'...']` objects) is parsed exactly as before. The new flat associative-array shape (used by the `/thank-you/` `skip_make` flow, where Growform's querystring keys like `firstName`, `email`, `phone`, `state`, `type_of_service`, `accindent_happen`, etc. are sent as a plain key=>value map under `body.fields`) is now parsed via a new `parseFlat()` method that maps the querystring keys onto the same canonical column names + raw values that the rest of the plugin expects. Effect: leads coming from `/thank-you/` Growform redirects now show real name/email/phone/state/service/stage in the dashboard instead of `unknown`, and `LeadStage::compute()` can correctly classify them as `qualified` / `disqualified` instead of always returning `unknown`. TrustedForm cert URL is read from either `TrustedForm_certUrl` or `trustedform_cert_url`. UTM parameters are read straight from their canonical querystring keys.

= 1.0.6 =
* Feat: `/lead` REST endpoint now accepts a `skip_make: true` flag in the body. When set, the lead is persisted and counted in the dashboard but the Make.com forward is skipped — `make_status` is set to a new `skipped` state instead of `pending`/`success`/`failed`. Use case: variants whose form (e.g. Growform) already POSTs directly to the Make webhook from inside an iframe; reporting the lead from the WP `/thank-you/` page would otherwise double-fire the same Make scenario. With `skip_make` set, `make_payload` becomes optional — if omitted, fields are read from a top-level `fields` object or `form_meta.fields` so the dashboard still gets phone/email/state/etc. The hourly retry cron does not pick up `skipped` rows.
* Feat: `LeadsRepository` exposes a new `MAKE_STATUS_SKIPPED = 'skipped'` constant and `markForwardSkipped(int $id, string $reason)` method. Existing dashboard column already echoes `make_status` raw, so the new state surfaces automatically (style with `.cah-make-skipped` if a custom badge color is desired).

= 1.0.5 =
* Fix: `VariantsRepository::replaceAll()` now performs a true UPSERT keyed on the submitted variant id rather than DELETE-then-INSERT. Previously every test save would delete all variants and re-insert them, incrementing `variant_id` each time and breaking referential integrity with historical leads (e.g. a 2-variant test's ids would grow to 11, 12, ... after a handful of saves, and `leads.variant_id` would orphan-point to deleted rows). Rows matched by id are UPDATED in place, genuinely new rows are INSERTed, and rows removed from the form are DELETEd. The edit form now submits a hidden `variants[i][id]` to drive this.
* Fix: uninstall cleanup now force-removes the plugin directory (with chmod fallback) if WP's filesystem abstraction left it behind, which happens on Hostinger/LiteSpeed hosts where the PHP uid differs from the web-server uid. Stale `cah-split-tester/` directories that survived a delete would previously block a fresh upload with a "destination folder already exists" error. Scoped strictly to our own plugin slug under wp-content/plugins/ for safety.

= 1.0.4 =
* Fix: switch Make.com forward to blocking mode in the /lead REST handler so `make_status` is actually updated to `success` / `failed`. Previously non-blocking dispatch returned true immediately and status stayed at `pending` forever, even though the HTTP request to Make was fired. Adds ~1–3s perceived latency at submit time but is the only way MakeForwarder sees the response.
* Fix: `LeadsRepository::findRetryable()` now also picks up `pending` rows older than 5 minutes, so any legacy rows stuck from prior non-blocking dispatches self-heal via the hourly retry cron.
* Fix: variant HTML submit handler now polls up to 2 seconds for `window.cahSplit.submitLead` to be attached (tracking.js is deferred), logging an error if it times out. Previously a fast user could submit before the deferred script attached and the lead would be silently lost while the 800ms thank-you redirect still ran.
* Fix: Router and VariantRenderer now additionally call `do_action('litespeed_control_set_nocache', ...)` and `litespeed_control_set_private`, in addition to the existing `X-LiteSpeed-Cache-Control: no-cache` header, to work with LiteSpeed's newer ESI-driven cache control where raw headers alone are not honored if the request already hit the edge cache. Also emits an explicit full `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` header.
* Fix: admin test-edit External URL input changed from `type="url"` to `type="text" inputmode="url"` so the browser does not block saves when the field contains a relative path like `/_cah/v/main/...`. Server-side validation of the field is unchanged.

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
