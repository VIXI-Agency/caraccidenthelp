/*!
 * CAH Split Tester — Path B (thank-you / disqualified) capture script.
 *
 * Auto-injected by PathBInjector on /thank-you/ and /diminished-value-claim/.
 *
 * Capture-everything policy (v1.0.17+):
 *   - cookie present + lead_stage valid       → POST /lead with source='path_b_growform'        → row in wp_cah_leads
 *   - cookie absent (direct visit / expired)  → POST /lead with source='path_b_no_cookie'        → row in wp_cah_leads (variant_id NULL)
 *   - cookie malformed (no '.')               → POST /lead with source='path_b_parse_failed_no_dot' → row (variant_id NULL)
 *   - cookie parts not parseable to ids       → POST /lead with source='path_b_parse_failed_no_ids' → row (variant_id NULL)
 *   - cookie OK but ?lead_stage missing       → POST /lead with source='path_b_missing_stage'   → row (lead_stage='unknown')
 *   - ?from_cah_form=1 (HTML v1 already submitted) → POST /lead-skip 'from-cah-form'            → no row (legit dedup)
 *   - sessionStorage already saw this lead    → POST /lead-skip 'dedup-session'                  → no row (legit dedup, page reload)
 *   - /lead POST itself fails                 → POST /lead-skip 'fetch-failed'                   → no row (technical error)
 *
 * Compare against Hyros now means: total `wp_cah_leads` rows for the day
 * should match Hyros total. The `source` column tells you WHERE each lead
 * came from.
 */
(function () {
    'use strict';

    if (window.__cahPathBLoaded) { return; }
    window.__cahPathBLoaded = true;

    var REST = (window.cahPathB && window.cahPathB.rest) ||
        (window.location.origin + '/wp-json/cah-split/v1');

    var TEST_ID = (window.cahPathB && window.cahPathB.test_id) || 2;

    function logSkip(reason, extra) {
        extra = extra || {};
        extra.reason = reason;
        try {
            var body = JSON.stringify(extra);
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(REST + '/lead-skip', blob)) { return; }
            }
            fetch(REST + '/lead-skip', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: body
            }).catch(function () {});
        } catch (e) {
            try { console.warn('[cah-split] lead-skip beacon failed', e); } catch (_) {}
        }
    }

    var params = {};
    try {
        new URLSearchParams(window.location.search).forEach(function (v, k) { params[k] = v; });
    } catch (e) {}

    var pageContext = {
        url: window.location.pathname + (window.location.search ? window.location.search.substring(0, 200) : ''),
        referrer: (document.referrer || '').substring(0, 200),
        has_email: !!params.email,
        has_phone: !!params.phone,
        lead_stage_received: params.lead_stage || '',
        from_cah_form: params.from_cah_form || ''
    };

    /**
     * Always-capture lead submitter. Sends to /lead with the given source tag.
     * variantId/visitorId may be null when the cookie was missing or unparseable.
     */
    function captureLead(source, variantId, visitorId) {
        var body = {
            test_id:    TEST_ID,
            variant_id: variantId || null,
            visitor_id: visitorId || null,
            skip_make:  true,
            source:     source,
            fields:     params,
            form_meta: {
                form_name:         'Growform via /thank-you/ (' + source + ')',
                variant_id:        variantId || null,
                lead_stage:        params.lead_stage || '',
                trusted_form_cert: params.TrustedForm_certUrl || ''
            }
        };
        var json = JSON.stringify(body);

        // v1.0.23: sendBeacon as primary delivery on pagehide/unload events,
        // fetch as primary on normal load. Beacon guarantees the POST survives
        // tab closes / fast redirects (race condition that lost ~6 real leads
        // 1-4 May 2026 according to Growform CSV cross-reference).
        var beaconSent = false;
        function sendViaBeacon() {
            if (beaconSent) { return true; }
            try {
                if (navigator.sendBeacon) {
                    var blob = new Blob([json], { type: 'application/json' });
                    if (navigator.sendBeacon(REST + '/lead', blob)) {
                        beaconSent = true;
                        return true;
                    }
                }
            } catch (e) {}
            return false;
        }
        // Fire beacon if the page is being hidden/unloaded before fetch completes.
        var onHide = function () { sendViaBeacon(); };
        try {
            window.addEventListener('pagehide', onHide, { once: true });
            window.addEventListener('beforeunload', onHide, { once: true });
        } catch (e) {}

        return fetch(REST + '/lead', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
            body: json
        }).then(function (res) {
            if (!res || !res.ok) {
                // HTTP error → try beacon as fallback before logging skip.
                sendViaBeacon();
                logSkip('fetch-failed', Object.assign({}, pageContext, {
                    test_id:     TEST_ID,
                    variant_id:  variantId,
                    source:      source,
                    http_status: res ? res.status : 0
                }));
            }
        }).catch(function (err) {
            // Network / abort → beacon fallback.
            sendViaBeacon();
            logSkip('fetch-failed', Object.assign({}, pageContext, {
                test_id:    TEST_ID,
                variant_id: variantId,
                source:     source,
                err:        String(err && err.message || err).substring(0, 120)
            }));
        });
    }

    var cookieName = 'cah_variant_' + TEST_ID + '=';
    var c = document.cookie.split('; ').find(function (x) { return x.indexOf(cookieName) === 0; });

    // ── Cookie absent → still capture, mark unattributed ─────────────
    if (!c) {
        captureLead('path_b_no_cookie', null, null);
        return;
    }

    var raw = c.split('=')[1];
    var parts = raw.split('.');
    if (parts.length < 2) {
        captureLead('path_b_parse_failed_no_dot', null, null);
        return;
    }

    var vid = parseInt(parts[0], 10);
    var uid = parts.slice(1).join('.');
    if (!vid || !uid) {
        captureLead('path_b_parse_failed_no_ids', null, null);
        return;
    }

    // ── HTML v1 already submitted before redirecting here. Don't dup. ─
    if (params.from_cah_form === '1') {
        logSkip('from-cah-form', Object.assign({}, pageContext, {
            test_id:    TEST_ID,
            variant_id: vid,
            lead_stage: params.lead_stage
        }));
        return;
    }

    // ── lead_stage missing → still capture with stage=unknown ────────
    if (params.lead_stage !== 'qualified-lead' && params.lead_stage !== 'disqualified-lead') {
        captureLead('path_b_missing_stage', vid, uid);
        return;
    }

    // ── Per-session dedup (page reload protection). ──────────────────
    var key = 'cah_lead_sent_' + (params.email || params.phone || params.TrustedForm_certUrl || Date.now());
    if (sessionStorage.getItem(key)) {
        logSkip('dedup-session', Object.assign({}, pageContext, {
            test_id:    TEST_ID,
            variant_id: vid,
            lead_stage: params.lead_stage
        }));
        return;
    }
    sessionStorage.setItem(key, '1');

    // ── Happy path: full attribution ─────────────────────────────────
    captureLead('path_b_growform', vid, uid);
})();
