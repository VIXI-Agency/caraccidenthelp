(function () {
    'use strict';

    var ctx = window.cahSplit || {};
    if (!ctx.rest_base) {
        return;
    }

    function readQuery() {
        var out = {};
        try {
            var sp = new URLSearchParams(window.location.search);
            sp.forEach(function (value, key) { out[key] = value; });
        } catch (e) {}
        return out;
    }

    function pick(obj, keys) {
        var out = {};
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            if (typeof obj[k] !== 'undefined' && obj[k] !== null) {
                out[k] = obj[k];
            }
        }
        return out;
    }

    function pageviewPayload() {
        var q = readQuery();
        var base = {
            test_id: ctx.test_id || null,
            variant_id: ctx.variant_id || null,
            visitor_id: ctx.visitor_id || null,
            referrer: document.referrer || '',
            path: window.location.pathname
        };
        var utms = pick(q, [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'clickid'
        ]);
        Object.keys(utms).forEach(function (k) { base[k] = utms[k]; });
        return base;
    }

    function beacon(url, payload) {
        try {
            var body = JSON.stringify(payload);
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(url, blob)) { return; }
            }
        } catch (e) {
            // Surface for QA — Hostinger has no debug.log so the only place
            // these failures can show up is the browser console.
            try { console.warn('[cah-split] sendBeacon threw', e); } catch (_) {}
        }
        try {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ctx.nonce || ''
                },
                body: JSON.stringify(payload),
                keepalive: true
            }).catch(function (err) {
                try { console.warn('[cah-split] beacon fetch failed', err); } catch (_) {}
            });
        } catch (e) {
            try { console.warn('[cah-split] beacon fetch threw', e); } catch (_) {}
        }
    }

    function trackPageview() {
        beacon(ctx.rest_base + '/pageview', pageviewPayload());
    }

    function submitLead(makePayload) {
        var body = {
            test_id: ctx.test_id || null,
            variant_id: ctx.variant_id || null,
            visitor_id: ctx.visitor_id || null,
            source: 'path_a_html_v1',
            make_payload: makePayload
        };
        return fetch(ctx.rest_base + '/lead', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': ctx.nonce || ''
            },
            body: JSON.stringify(body),
            keepalive: true
        }).then(function (res) {
            if (!res.ok) {
                try { console.warn('[cah-split] /lead non-2xx', res.status); } catch (_) {}
                return { success: false, status: res.status };
            }
            return res.json().catch(function (err) {
                try { console.warn('[cah-split] /lead body parse failed', err); } catch (_) {}
                return { success: false };
            });
        }).catch(function (err) {
            // Network failure, CORS, fetch aborted by navigation, etc. Used to
            // be silently swallowed — the form's outer .catch would then
            // discard everything. Now at least DevTools shows it.
            try { console.warn('[cah-split] /lead fetch failed', err); } catch (_) {}
            return { success: false, error: String(err && err.message || err) };
        });
    }

    window.cahSplit.trackPageview = trackPageview;
    window.cahSplit.submitLead = submitLead;

    /**
     * Persists multi-step form analytics to WordPress (first-party). Does not
     * replace GTM — complements it when tags are blocked.
     */
    function trackFunnel(evt) {
        if (!ctx.rest_base || !evt) {
            return;
        }
        var body = {
            test_id: ctx.test_id || null,
            variant_id: ctx.variant_id || null,
            visitor_id: ctx.visitor_id || null,
            event_type: evt.event_type,
            step_number: evt.step_number,
            step_name: evt.step_name || ''
        };
        beacon(ctx.rest_base + '/form-funnel', body);
    }
    window.cahSplit.trackFunnel = trackFunnel;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackPageview);
    } else {
        trackPageview();
    }
})();
