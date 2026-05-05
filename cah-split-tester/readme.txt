=== CAH Split Tester ===
Contributors: vixi-agency
Tags: a/b testing, split testing, lead generation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 1.0.22
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

= 1.0.22 =
* **REVERSAL of v1.0.19 + v1.0.21 — `service_type` IS a disqualifier.** Per the upstream Growform UI screenshots and direct confirmation from the client (Kaleb), only `car_accident`, `motorcycle_accident`, and `trucking_accident` can produce a `qualified` lead. Every other accident type (bicycle/e-bike, pedestrian, work, other) is automatically `disqualified` regardless of attorney/fault/injury/timeframe answers. v1.0.19 and v1.0.21 had removed this whitelist on the wrong assumption that service_type didn't matter; v1.0.22 restores the original v1.0.18 behaviour in all three places where the rule lived:
    * `variants/v1.html` — restored `QUALIFIED_SERVICES = ['car_accident','motorcycle_accident','trucking_accident']` filter on the HTML V1 client classifier.
    * `includes/Repositories/StatsRepository.php::perVariant()` — restored the `service_type` rule inside `$disqExpr` so Comparable QR / Comparable Leads exclude non-MVA leads from the denominator (matches v1.0.18).
    * `includes/LeadStage.php::compute()` — restored `QUALIFIED_SERVICES` whitelist as a required condition for `STAGE_QUALIFIED`. `service_type` is also required again for stage classification (a missing service_type yields `STAGE_UNKNOWN`).
* **New `URL_DISQUALIFIED_OTHER` redirect (`/finished/`)** matching Growform's official 3-URL waterfall:
    1. qualified → `/thank-you/?lead_stage=qualified-lead`
    2. disqualified AND `injury='no'` → `/diminished-value-claim/?lead_stage=disqualified-lead`
    3. disqualified (everything else, catch-all) → `/finished/?lead_stage=disqualified-lead`
  `LeadStage::redirectUrl()` now accepts an optional `$fields` argument and routes accordingly. The legacy `URL_DISQUALIFIED` constant is preserved as an alias of `URL_DISQUALIFIED_NO_INJURY` for backwards compatibility.
* **`PathBInjector` updated** to also auto-inject the Path B observability snippet on `/finished/` so leads dropped to the catch-all are still captured. After deploying v1.0.22, the same Path B snippet should be pasted into (or trusted to be auto-injected on) the `/finished/` WordPress page.
* **`StatsRepository` & `test-detail.php` footnote** wording updated to mention non-MVA service type as a disqualifier again, matching the restored SQL.
* **One-time SQL rollback (provided alongside this release)** reverts the 17 historical V1 leads incorrectly promoted to `qualified` by the v1.0.19 SQL recovery, using the existing `inz_cah_leads_v1019_backup` table.
* **v1.0.20 admin UX (Q-columns + Form badges) is preserved** — those changes were pure UX with no logic dependency and remain useful.

= 1.0.21 =
* **CRITICAL: Fix server-side qualified logic in `LeadStage::compute()`** — the third and most impactful copy of the same `service_type` whitelist bug fixed in v1.0.19. The PHP backend was hardcoding `QUALIFIED_SERVICES = ['car_accident','motorcycle_accident','trucking_accident']` as a REQUIRED condition for `lead_stage = qualified`. Every lead with any other service_type (bicycle/e-bike, pedestrian, accident-or-injury-at-work, other) was silently overridden to `disqualified` at the server, regardless of attorney/fault/injury/timeframe answers. This affected **BOTH** Growform-fed Control AND HTML V1 — unlike the v1.0.19 JS fix which only affected HTML V1, this server fix corrects every variant. SQL audit of test_id=2 confirmed Growform was sending leads with `attorney="I Have An Attorney"` correctly, but the server was overriding many other accident types to disqualified before saving. Going forward, ALL accident types are treated equally; disqualification depends ONLY on attorney/fault/injury/timeframe.
* **Required-fields list trimmed** — `service_type` is no longer required to compute a stage (only attorney/fault/injury/timeframe). A lead with all four answers but no service_type is now classified normally instead of being marked `unknown`.
* **Zero schema/UX changes; pure logic fix.** Existing rows in DB are unchanged — a separate one-time SQL recovery (provided alongside this release) re-classifies historical disqualified leads that meet the 4 real qualifier rules.

= 1.0.20 =
* **Leads page — new question columns** (`admin/views/leads-list.php`): added `Attorney`, `Fault`, `Injury`, `Timeframe` columns so operators can audit each lead's qualification at a glance without expanding the raw payload. Disqualifying answers (`has_attorney`, `fault=yes`, `injury=no`, `timeframe IN (within_2_year, longer_than_2_year)`) are highlighted in red so the disqualification reason is visually obvious. Empty/NULL values render as a faint em-dash so it's clear when a form did not submit a value for that field (key for diagnosing Growform vs HTML mapping mismatches).
* **Leads page — new "Form" column** with friendly badges: `HTML` (green) for `path_a_html_v1`, `Growform` (blue) for any `path_b_*` source. The internal `Source` slug column is preserved for deep debugging but the new `Form` column makes day-to-day filtering much clearer.
* **Per-variant table footnote fix** (`admin/views/test-detail.php`): removed the stale “non-MVA service” clause from the Comparable Leads tooltip and footnote text, to match the v1.0.19 logic change where `service_type` is no longer a disqualifier. The on-screen text now matches what the SQL actually does.
* **Zero schema changes, zero behaviour changes on lead capture or forwarding.** Pure admin UX additions — the new columns read from existing fields already populated by `RestApi::record()`.

= 1.0.19 =
* **Fix qualified logic in HTML V1 form (`variants/v1.html`)** — removed the hardcoded `QUALIFIED_SERVICES` whitelist that was auto-disqualifying any lead whose `service_type` wasn't `car_accident`, `motorcycle_accident`, or `trucking_accident`. Per business rules, **`service_type` does NOT disqualify** — every accident type (car, motorcycle, truck, bicycle/e-bike, pedestrian, accident-or-injury-at-work, other) is potentially qualified. Disqualification depends ONLY on attorney/fault/injury/timeframe. This bug caused HTML V1 to under-count qualified leads — SQL audit confirmed ~12 leads were silently lost to this in 4 days of test_id=2 alone, and the gap is even larger in production because the bug had been live since v1.0.0.
* **Fix Comparable QR metric in `StatsRepository::perVariant()`** — v1.0.18 incorrectly excluded leads with non-MVA `service_type` from the comparable cohort. Removed the `service_type` rule from `$disqExpr` so the metric now uses the same 4 real disqualifiers used in production: `attorney='has_attorney'`, `fault='yes'`, `injury='no'`, `timeframe IN ('within_2_year','longer_than_2_year')`. Retroactive across all historical data — Comparable QR and Comparable Leads will recalculate immediately on next dashboard load.
* **Zero impact on data, schema, Make.com forwarding, or admin UX** — only changes JS classification logic going forward (HTML V1 will now persist + qualify previously-blocked accident types) and recalculates Comparable QR display. Existing leads in DB are unchanged.

= 1.0.18 =
* **New "Comparable QR" metric on the per-variant table** — apples-to-apples qualified-rate that excludes obvious disqualifications (has_attorney, fault=yes, injury=no, timeframe within/longer than 2 years, non-MVA service_type) from the denominator. This is the fair number to compare HTML v1 against Growform-fed variants, because Growform silently filters disqualified users client-side BEFORE they hit the DB (they get redirected to `/finished/?lead_stage=disqualified-lead&...` and `/thank-you/` is never loaded, so no row is ever inserted). HTML v1, in contrast, persists EVERY submission — qualified and disqualified alike. Comparing raw QR between the two is misleading; Comparable QR levels the playing field by removing leads that Growform would have dropped upstream.
* **New "Comparable Leads" column** — total leads minus obvious disqualifications, with a `(−N)` indicator showing how many were excluded. Lets operators see the size of the comparable cohort at a glance.
* **`StatsRepository::perVariant()`** now computes `comparable_leads` and `disqualified_obvious` per variant inside the existing aggregation subquery — single round-trip, no N+1, fully retroactive across all historical leads.
* **Explanation paragraph** at the bottom of the per-variant table documents the disqualification criteria and explains why Comparable QR exists, so future operators don't have to dig through code to understand the metric.
* **Zero impact on data, forms, Make.com, or anything else** — display-only addition. No schema changes, no migrations, no behavior changes on lead capture or forwarding. Existing "Total Leads" and "Qualified %" columns are unchanged.

= 1.0.17 =
* **Capture-everything: every form submission becomes a row in `wp_cah_leads`, no exceptions.** Visitors who reach `/thank-you/` directly without going through the split test (no `cah_variant_2` cookie) used to fire `rest.lead_skip.no-cookie` and be lost from the dashboard. Now they create a real lead row tagged `source='path_b_no_cookie'`, with NULL `variant_id`. Same for cookie-corruption cases (`path_b_parse_failed_no_dot`, `path_b_parse_failed_no_ids`) and for visitors whose Growform redirect was missing `?lead_stage=` (`path_b_missing_stage`, lead_stage='unknown'). Total `wp_cah_leads` row count for a day should now match Hyros total for the same window. The dashboard CR will look LOWER than before because the unattributed leads inflate the lead count without inflating pageviews — that's the correct behavior; the previous numbers were under-counting.
* **New `source` column on `wp_cah_leads`** (`VARCHAR(64) DEFAULT NULL`, indexed). Whitelist enforced server-side in `RestApi::ALLOWED_SOURCES`:
    * `path_a_html_v1` — submitted by HTML v1 form via tracking.js
    * `path_b_growform` — Growform → /thank-you/ with valid cookie + lead_stage (full attribution)
    * `path_b_no_cookie` — visitor reached /thank-you/ without cah_variant_<id> cookie (direct visit / Safari ITP / cookie expired)
    * `path_b_parse_failed_no_dot` — cookie present but malformed (no '.')
    * `path_b_parse_failed_no_ids` — cookie parts didn't yield valid ids
    * `path_b_missing_stage` — cookie OK but ?lead_stage param missing/invalid
    * `unknown` — fallback when an unrecognized source is sent
  Source is also reflected in the `rest.lead.created` log row so the Logs page shows the breakdown.
* **Admin Leads page** got a new "Source" filter (with explicit "(no source)" option for legacy rows from before 1.0.17) and a new "Source" column with color-coded pills next to "Stage". A new leftmost "ID" column shows `#<id>` so individual leads are unambiguously addressable. The CSV export adds a `source` column right after `visitor_id`.
* **`PathBInjector` service** auto-injects the new `assets/path-b.js` on `/thank-you/` and `/diminished-value-claim/` via `wp_footer` priority 99, with a small inline `window.cahPathB = {rest, test_id}` boot block. This bypasses WordPress KSES, which strips `<script>` tags from page content for users without `unfiltered_html` capability — that's why pasting the snippet into the WP page editor failed before. Path matching is derived from `LeadStage::URL_QUALIFIED` and `LeadStage::URL_DISQUALIFIED` so the constants stay the single source of truth. Operator NOTE: if you previously pasted the older snippet manually into either page, REMOVE it to avoid double-execution. The injected script has a `window.__cahPathBLoaded` guard, but cleaner is a clean page.
* **`assets/path-b.js`** is the new always-capture client. Same skip-reason categorization (no-cookie, parse-failed-*, from-cah-form, missing-stage, dedup-session, fetch-failed) but now no-cookie / parse-failed / missing-stage POST to `/lead` instead of `/lead-skip`, with the appropriate `source` tag. Only `from-cah-form` (HTML v1 already submitted) and `dedup-session` (page reload) and `fetch-failed` remain as `/lead-skip` calls — those are legitimate dedup/error paths where a row should NOT be created.
* **`assets/tracking.js`** (Path A) now tags every lead `source='path_a_html_v1'` in the POST body so HTML v1 leads are clearly distinguished from Path B leads.
* **DB migration**: dbDelta adds `source VARCHAR(64) DEFAULT NULL` column + `KEY idx_source (source)` to `wp_cah_leads`. No data is touched. Pre-1.0.17 leads will display "—" in the Source column and can be filtered as "(no source)".

= 1.0.16 =
* **New `/lead-skip` REST endpoint** — closes the last visibility gap on Path B (Growform → /thank-you/ snippet). The snippet today silently aborts in 6 cases (no `cah_variant_2` cookie, malformed cookie, `from_cah_form=1`, missing `lead_stage`, `sessionStorage` dedup hit, fetch failure). With v1.0.16, the snippet is updated to call `/lead-skip` with a `reason` tag BEFORE every silent return, so the admin Logs page shows exactly how often each path fires. Reasons are whitelisted server-side: `no-cookie`, `parse-failed-no-dot`, `parse-failed-no-ids`, `from-cah-form`, `missing-stage`, `dedup-session`, `fetch-failed`, `unknown`. Each surfaces as its own `rest.lead_skip.<reason>` source pill in the 24h source breakdown.
* The endpoint is anonymous (matches `/lead` and `/pageview`), never validates auth, never rejects; always returns HTTP 200 with `{success:true, reason}`. Context recorded includes `test_id`, `variant_id`, `lead_stage` (sent and received), `has_email`, `has_phone`, `url`, `referrer`, `ip_hash`, `user_agent`, content-type, and a truncated cookie value when relevant. Truncated to 200 chars per field to keep `wp_cah_log` rows reasonable.
* **Admin form UX rename** — column "External URL" renamed to "Variant URL" with title-attribute tooltip clarifying that the field is the redirect target after bucketing (auto-filled for plugin-hosted, manual for external). Placeholder updated from `leave empty if using an HTML file` to `https://example.com/my-page — or leave empty for plugin-hosted`. The help block above the table is now a proper bullet list explaining plugin-hosted vs external vs pretty path. Same change applied to the "Add variant" dynamic JS row builder so new rows match the existing UI exactly.
* **No DB schema changes**, no migrations. dbDelta still runs idempotently.

== Path B operator note (1.0.16) ==

After deploying 1.0.16, replace the snippet pasted into both `/thank-you/` and `/diminished-value-claim/` WordPress page bodies with the new version that calls `/lead-skip` before every silent return. The new snippet is documented inside the plugin under `docs/path-b-thank-you-snippet.html` (also in the GitHub release notes). Until the snippet is updated, the old snippet keeps working — the `/lead-skip` endpoint just doesn't get called and you don't see the skip pills.

= 1.0.15 =
* **Hotfix to 1.0.14 observability — close two visibility gaps that left the Logs page empty for normal traffic.**
* `RestApi::handlePageview` was instrumented for 400 rejections only; successful pageviews were not logged. Added `rest.pageview.received` info log on every successful `/pageview` insert with test/variant/visitor IDs, UTM source/campaign, referer, path, IP hash, and User-Agent. Now any plugin-hosted variant visit produces a log row as soon as `tracking.js` fires.
* `Router` had no logger at all — every 302 redirect, every pretty-path render, every legacy `/_cah/v/` render was invisible. Added `?Logger $logger = null` to `Router::__construct` (wired in `Plugin::__construct`) and emit:
  * `router.bucket` (info) — every time a visitor on the trigger path is bucketed and 302'd; includes `cookie_hit` flag so sticky-vs-fresh assignments are visible at a glance, plus the redirect target so external Growform variants are also auditable.
  * `router.pretty_render` (info) — every time a plugin-hosted variant is rendered in place via its `pretty_path`.
  * `router.legacy_render` (info) — every time the `/_cah/v/<test>/<variant>/` legacy URL is rendered.
  * `router.bucket.no_variants` (warn) — the rare case where the trigger path matched an active test but no variant qualified (e.g., all weights are 0). Helps explain visitors who hit the trigger and got a normal WP 200 with no redirect.
* No DB schema changes. dbDelta still runs idempotently via `Activator::migrateIfNeeded`.

= 1.0.14 =
* **Observability foundation.** Added DB-backed plugin log (`wp_cah_log`) with a new admin "Logs" submenu (Split Tester → Logs). Every `/lead` and `/pageview` REST hit now produces a row, including 400 rejections (with first 500 chars of the raw body, content-type, IP hash, and User-Agent), 500 errors (with `$wpdb->last_error` and the row that failed to insert), successful inserts (test/variant/stage/has_email/has_phone), and Make.com forward outcomes (HTTP code + first 500 chars of response). Sources are short tags like `rest.lead.received`, `rest.lead.created`, `rest.lead.400`, `rest.lead.500`, `leads.repo.insert`, `make.forward.ok`, `make.forward.non2xx`, `make.forward.wp_error`, so admins can filter by source pill.
* **Critical fix:** `LeadsRepository::create()` now throws `RuntimeException` with `$wpdb->last_error` when `$wpdb->insert()` returns false, instead of silently returning `lead_id=0` and letting `RestApi::handleLead` respond `success: true, lead_id: 0`. Suspected primary cause of the plugin-vs-Hyros undercount: any insert that hit a charset/truncation/schema error was vanishing with no audit trail.
* **Logger** is a thin service that dual-writes to `wp_cah_log` AND PHP `error_log()` so existing log readers keep working. Context is stored as JSON, individual rows truncated at 500 chars (message) and 20 KB (context). Logger failures never propagate to the request — they fall back to `error_log`.
* **Admin Logs page** features: 24h info/warn/error metric tiles, last-24h source breakdown as clickable filter pills, level + source + free-text search + date-range filters, paginated 100/page, expandable context (pretty-printed JSON), "Clear all logs" button (capability + nonce protected), optional auto-refresh every 10s for live monitoring during QA. Pre-styled level badges via `.cah-loglevel-*`. Custom helper text on the page describes how to use `rest.lead.received` vs `rest.lead.created` counts to localize a plugin-vs-Hyros gap to client-side or server-side.
* **Cron**: new daily `cah_split_prune_logs` event prunes log rows older than 14 days. `Cron::unschedule()` now removes both events on deactivation. `LogsRepository` exposes `pruneOlderThanDays()`, `truncate()`, `count()`, `query()`, `countBySource()`, `countByLevel()`.
* **MakeForwarder** error_log calls migrated to `Logger->error/info` so retries and webhook outcomes are visible in the Logs page (existing error_log output is preserved via dual write).
* **tracking.js** `.catch(function(){})` blocks now log to `console.warn` so QA can spot `/lead` non-2xx, body parse failures, network errors, and `sendBeacon`/keepalive failures in DevTools instead of having them silently swallowed. No behavior change otherwise.
* **DB migration:** `dbDelta` adds `wp_cah_log (id, level, source, message, context LONGTEXT, created_at)` with three indexes (level+date, source+date, created_at). Uninstaller drop-tables list updated.

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
