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
        } catch (e) {}
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
            }).catch(function () {});
        } catch (e) {}
    }

    function trackPageview() {
        beacon(ctx.rest_base + '/pageview', pageviewPayload());
    }

    function submitLead(makePayload) {
        var body = {
            test_id: ctx.test_id || null,
            variant_id: ctx.variant_id || null,
            visitor_id: ctx.visitor_id || null,
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
            if (!res.ok) { return { success: false, status: res.status }; }
            return res.json().catch(function () { return { success: false }; });
        });
    }

    window.cahSplit.trackPageview = trackPageview;
    window.cahSplit.submitLead = submitLead;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackPageview);
    } else {
        trackPageview();
    }
})();
