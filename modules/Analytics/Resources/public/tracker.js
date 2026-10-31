/*!
 * GEREQ Analytics tracker 0.3.1 — first-party, consent-gated page analytics.
 * Standalone ES2018: no imports, no bundler, no external requests.
 * Config comes from data-* attributes on this <script> tag: data-site,
 * data-endpoint, data-consent-endpoint, data-config, data-consent-key,
 * data-consent-category, data-max-events, data-respect-dnt. Without data-site
 * the same-origin config endpoint is fetched instead.
 * Endpoint hardening: every attribute and /config URL is accepted only when it
 * resolves to this origin over http(s); otherwise the built-in
 * /api/public/analytics/{config,collect,consent} default path is used, so an
 * injected tag can never exfiltrate the visitor id or the consent state.
 * Privacy: no cookie and no beacon before the consent category is granted;
 * grq_no_track (opt-out) is the single exception written before consent;
 * grq_vid / grq_consent are SameSite=Lax, non-HttpOnly, 13 months; no IP.
 */
(function () {
'use strict';
var VERSION = '0.3.1', CC = 'grq_consent', VC = 'grq_vid', OC = 'grq_no_track',
    AGE = 34128000, OAGE = 31536000, MAXBODY = 30000, ENGAGE = 15000, CAP = 1800000, POLL = 5000;
var D = document, C = read(D.currentScript || lastTag());
var st = {
    started: 0, blocked: 0, granted: 0, visitor: null, consent: null, raw: null, queue: [],
    path: location.pathname + location.search, ms: 0, depth: 0, at: Date.now(), tickAt: 0,
    scrollAt: 0, visible: D.visibilityState !== 'hidden'
};

function at(node, name, fallback) {
    var value = node && node.getAttribute ? node.getAttribute(name) : null;
    return value === null || value === '' ? fallback : value;
}
/**
 * Same-origin allow-list for every URL that may carry the visitor id or the consent
 * state. `data-*` attributes (and the /config response) are attacker-reachable on a
 * compromised storefront (CMS payload, HTML import), so a value is accepted only when
 * it resolves against the current document to this origin over http(s); a different
 * origin, a relative-scheme value (javascript:, data:) or an unparsable string is
 * ignored and replaced by the built-in default path. Returns the original string so
 * the request bytes stay identical to the pre-hardening behaviour.
 */
function sameOrigin(value, fallback) {
    if (typeof value !== 'string' || value === '') { return fallback; }
    try {
        var url = new URL(value, location.href);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') { return fallback; }
        if (url.origin !== location.origin) { return fallback; }
        return value;
    } catch (e) { return fallback; }
}
/** The config endpoint of the last `script[data-site]` tag; its URLs are filtered like any other. */
function lastTag() {
    try { var all = D.querySelectorAll('script[data-site]'); return all.length ? all[all.length - 1] : null; }
    catch (e) { return null; }
}
function read(node) {
    var max = parseInt(at(node, 'data-max-events', '50'), 10);
    return {
        site: at(node, 'data-site', ''),
        endpoint: sameOrigin(at(node, 'data-endpoint', ''), '/api/public/analytics/collect'),
        consentUrl: sameOrigin(at(node, 'data-consent-endpoint', ''), '/api/public/analytics/consent'),
        configUrl: sameOrigin(at(node, 'data-config', ''), '/api/public/analytics/config'),
        key: at(node, 'data-consent-key', 'cookie-consent'),
        cat: at(node, 'data-consent-category', 'statistics'),
        max: max > 0 ? max : 50,
        dnt: at(node, 'data-respect-dnt', '1') !== '0'
    };
}
function unix() { return Math.floor(Date.now() / 1000); }
function ratio(v) { v = Math.round(v * 1000) / 1000; return v < 0 ? 0 : (v > 1 ? 1 : v); }
function parse(raw) {
    if (typeof raw !== 'string' || raw === '') { return null; }
    try { return JSON.parse(raw); } catch (e) { return null; }
}
function ck(name) {
    var parts = String(D.cookie || '').split('; ');
    for (var i = 0; i < parts.length; i++) {
        var eq = parts[i].indexOf('=');
        if (eq > 0 && parts[i].slice(0, eq) === name) { return decodeURIComponent(parts[i].slice(eq + 1)); }
    }
    return null;
}
function setCk(name, value, age) {
    var raw = name + '=' + encodeURIComponent(value) + ';path=/;max-age=' + age + ';SameSite=Lax';
    if (location.protocol === 'https:') { raw += ';Secure'; }
    try { D.cookie = raw; } catch (e) {}
}
function delCk(name) { try { D.cookie = name + '=;path=/;max-age=0;SameSite=Lax'; } catch (e) {} }
function store() {
    try { return window.localStorage ? window.localStorage.getItem(C.key) : null; } catch (e) { return null; }
}
function randomId() {
    var bytes = new Uint8Array(16), i;
    try { window.crypto.getRandomValues(bytes); }
    catch (e) { for (i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); } }
    var out = '';
    for (i = 0; i < 16; i++) { out += ('0' + bytes[i].toString(16)).slice(-2); }
    return out;
}
function visitorId() {
    var known = ck(VC);
    return known && /^[a-f0-9]{16,64}$/.test(known) ? known : randomId();
}
/** FNV-1a hash of a short DOM path: stable element id for the later heatmaps. */
function selector(el) {
    var parts = [], node = el, depth = 0;
    while (node && node.nodeType === 1 && depth < 4) {
        var part = node.tagName ? String(node.tagName).toLowerCase() : '';
        if (node.id) { part += '#' + node.id; }
        else if (typeof node.className === 'string') {
            var cls = node.className.split(/\s+/).slice(0, 2);
            if (cls[0]) { part += '.' + cls.join('.'); }
        }
        if (node.parentNode) {
            var index = 1, sibling = node;
            while ((sibling = sibling.previousElementSibling)) {
                if (sibling.tagName === node.tagName) { index++; }
            }
            part += ':' + index;
        }
        parts.unshift(part);
        node = node.parentNode;
        depth++;
    }
    var text = parts.join('>'), hash = 0x811c9dc5;
    for (var i = 0; i < text.length; i++) {
        hash ^= text.charCodeAt(i);
        hash = Math.imul(hash, 0x01000193);
    }
    return (hash >>> 0).toString(16);
}
/** Tolerant CMP reader: {statistics:true}, {categories:{…}} or {categories:[…]}. */
function isGranted(raw, category) {
    if (window.gereqCookie && typeof window.gereqCookie.allows === 'function' && !window.gereqCookie.allows(category)) { return false; }
    var data = parse(raw);
    if (data === true) { return true; }
    if (!data || typeof data !== 'object') { return false; }
    if (typeof data[category] === 'boolean') { return data[category]; }
    if (data[category] === 'granted' || data[category] === 'true') { return true; }
    var bag = data.categories || data.consent || null;
    if (Object.prototype.toString.call(bag) === '[object Array]') { return bag.indexOf(category) !== -1; }
    return !!bag && (bag[category] === true || bag[category] === 'granted' || bag[category] === 'true');
}
function sync() {
    try {
        if (st.blocked) { return; }
        // An opt-out cookie may appear mid-session (CMP bridge or ?grq_notrack=1).
        if (ck(OC)) { block(); return; }
        var raw = store();
        if (raw === st.raw) { return; }
        st.raw = raw;
        if (isGranted(raw, C.cat)) { allow(raw); }
        else if (st.granted || ck(CC)) { revoke(); }
    } catch (e) {}
}
/** Consent granted: write the ids, then start counting. Unreachable without consent. */
function allow(raw) {
    try {
        st.visitor = st.visitor || visitorId();
        st.consent = { v: 1, statistics: true, marketing: isGranted(raw, 'marketing'), replay: !!(window.gereqCookie && window.gereqCookie.allows('replay')), ts: unix() };
        setCk(CC, JSON.stringify(st.consent), AGE);
        setCk(VC, st.visitor, AGE);
        if (st.granted) { return; }
        st.granted = 1;
        pageview();
        D.dispatchEvent(new CustomEvent('gereq:analytics-consent'));
    } catch (e) {}
}
/** Consent revoked: stop, drop local ids, close and purge the session server-side. */
function revoke() {
    var body = null;
    try {
        if (st.visitor) {
            body = JSON.stringify({
                site: C.site, decision: 'revoked', visitor: st.visitor, ts: unix(),
                categories: { statistics: false, marketing: false }
            });
        }
    } catch (e) {}
    halt();
    delCk(VC);
    delCk(CC);
    st.visitor = null;
    st.consent = null;
    D.dispatchEvent(new CustomEvent('gereq:analytics-consent'));
    if (body) { post(C.consentUrl, body, 1); }
}
function halt() {
    st.granted = 0;
    st.queue = [];
    st.ms = 0;
    st.depth = 0;
}
/** Hard opt-out: stop and forget the local ids. Nothing is sent afterwards. */
function block() {
    st.blocked = 1;
    halt();
    delCk(VC);
    delCk(CC);
    st.visitor = null;
    st.consent = null;
    D.dispatchEvent(new CustomEvent('gereq:analytics-consent'));
}
/** Opt-out and bot checks run before any storage write or network call. */
function noTrack() {
    // grq_no_track is the single cookie written before consent on purpose: it records the
    // visitor's refusal, so the opt-out must survive without a CMP decision (and is checked
    // again by the server-side TrafficClassifier).
    if (String(location.search || '').indexOf('grq_notrack=1') !== -1) { setCk(OC, '1', OAGE); return true; }
    if (ck(OC)) { return true; }
    try { if (navigator.webdriver === true) { return true; } } catch (e) {}
    try {
        var flag = D.documentElement.dataset.gereqNoTrack;
        if (typeof flag !== 'undefined' && flag !== '0' && flag !== 'false') { return true; }
    } catch (e) {}
    if (!C.dnt) { return false; }
    try {
        return navigator.doNotTrack === '1' || window.doNotTrack === '1'
            || navigator.msDoNotTrack === '1' || navigator.globalPrivacyControl === true;
    } catch (e) { return false; }
}
function scrolled() {
    try {
        var height = Math.max(
            D.body ? D.body.scrollHeight : 0,
            D.documentElement ? D.documentElement.scrollHeight : 0
        ), view = window.innerHeight || 0;
        if (height <= view) { return 0; }
        return ratio(((window.pageYOffset || D.documentElement.scrollTop || 0) + view) / height);
    } catch (e) { return 0; }
}
/** Visibility-aware engaged time; long gaps (sleep/wake) are capped at 1 min. */
function accrue() {
    var time = Date.now();
    if (st.visible) {
        var gap = time - st.at;
        st.ms += Math.min(gap > 0 ? gap : 0, 60000);
    }
    st.at = time;
    st.depth = Math.max(st.depth, scrolled());
}
function queueEngagement() {
    if (st.ms <= 0 && st.depth <= 0) { return; }
    enqueue('engagement', { engaged_ms: Math.min(st.ms, CAP), scroll_depth: ratio(st.depth) });
    st.ms = 0;
}
function pageview() {
    st.ms = 0;
    st.depth = 0;
    st.at = Date.now();
    st.tickAt = st.at;
    enqueue('pageview', {});
    flush(1);
}
function enqueue(type, props) {
    try {
        if (!st.granted || st.blocked || !C.site) { return; }
        st.queue.push({ t: type, ts: unix(), props: props || {} });
        if (st.queue.length >= C.max) { flush(1); }
    } catch (e) {}
}
function payload() {
    return {
        site: C.site, consent: st.consent, visitor: st.visitor,
        page: {
            url: location.pathname + location.search, title: D.title || '',
            referrer: D.referrer || '',
            lang: (D.documentElement && D.documentElement.lang) || navigator.language || ''
        },
        viewport: { w: window.innerWidth || 0, h: window.innerHeight || 0 }
    };
}
/** Batch, cap the body below the 32 KB server limit, never throw into the page. */
function flush(beacon) {
    try {
        if (!st.granted || st.blocked || !st.queue.length) { return; }
        var data = payload();
        data.events = st.queue.splice(0, st.queue.length);
        var body = JSON.stringify(data);
        while (body.length > MAXBODY && data.events.length > 1) {
            data.events.shift();
            body = JSON.stringify(data);
        }
        post(C.endpoint, body, beacon);
    } catch (e) { st.queue = []; }
}
function post(url, body, beacon) {
    // Belt and braces on top of the filters in read()/loadConfig(): a beacon carries the
    // visitor id and the consent state, so it leaves only for this origin.
    if (!sameOrigin(url, '')) { return; }
    try {
        if (beacon && navigator.sendBeacon) {
            if (navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) { return; }
        }
    } catch (e) {}
    try {
        fetch(url, {
            method: 'POST', keepalive: true, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: body
        }).catch(function () {});
    } catch (e) {}
}
/** Normalised viewport coordinates + element hash: the heatmap phase data. */
function onClick(event) {
    try {
        if (!st.granted || st.blocked || event.button !== 0 || event.defaultPrevented) { return; }
        if (!event.target || event.target.nodeType !== 1) { return; }
        enqueue('click', {
            x: ratio(event.clientX / (window.innerWidth || 1)),
            y: ratio(event.clientY / (window.innerHeight || 1)),
            sel: selector(event.target),
            w: window.innerWidth || 0, h: window.innerHeight || 0
        });
    } catch (e) {}
}
function onScroll() {
    var time = Date.now();
    if (time - st.scrollAt < 250) { return; }
    st.scrollAt = time;
    st.depth = Math.max(st.depth, scrolled());
}
function onVisibility() {
    try {
        if (D.visibilityState === 'hidden') {
            st.visible = 0;
            accrue();
            queueEngagement();
            flush(1);
            return;
        }
    } catch (e) { return; }
    st.visible = 1;
    st.at = Date.now();
}
function onPageHide() {
    try {
        st.visible = 0;
        accrue();
        queueEngagement();
        flush(1);
    } catch (e) {}
}
/** SPA route change: close the running pageview, open a new one. */
function routeChanged() {
    try {
        if (!st.granted || st.blocked) { return; }
        var next = location.pathname + location.search;
        if (next === st.path) { return; }
        st.path = next;
        accrue();
        queueEngagement();
        pageview();
    } catch (e) {}
}
function tick() {
    try {
        if (!st.granted) { return; }
        accrue();
        var time = Date.now();
        if (time - st.tickAt < ENGAGE || (!st.ms && !st.depth)) { return; }
        st.tickAt = time;
        queueEngagement();
        flush(1);
    } catch (e) {}
}
function patchHistory() {
    try {
        if (!window.history || window.history.__grqPatched) { return; }
        window.history.__grqPatched = true;
        var names = ['pushState', 'replaceState'];
        for (var i = 0; i < names.length; i++) {
            (function (name) {
                var original = window.history[name];
                if (typeof original !== 'function') { return; }
                window.history[name] = function () {
                    var result = original.apply(this, arguments);
                    setTimeout(routeChanged, 0);
                    return result;
                };
            })(names[i]);
        }
    } catch (e) {}
}
function start() {
    try {
        if (st.started) { return; }
        st.started = 1;
        if (!C.site) { halt(); return; }
        if (noTrack()) { block(); return; }
        sync();
        setInterval(function () { sync(); tick(); }, POLL);
        D.addEventListener('visibilitychange', onVisibility, true);
        D.addEventListener('click', onClick, true);
        D.addEventListener('gereq:consent-changed', sync, true);
        addEventListener('pagehide', onPageHide, true);
        addEventListener('scroll', onScroll, true);
        addEventListener('popstate', routeChanged, true);
        addEventListener('storage', sync, true);
        patchHistory();
    } catch (e) {}
}
function loadConfig() {
    try {
        fetch(C.configUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !data.site || data.enabled === false) { return; }
                C.site = data.site;
                // Re-validate the config-provided URLs: the response is first-party, but a
                // tampered /config must never be able to redirect the visitor id or the
                // consent state. `tracker_url` is part of the public contract yet never
                // consumed by the tracker (the host page already loaded this script), so it
                // is deliberately dropped instead of being stored.
                if (data.endpoint) { C.endpoint = sameOrigin(data.endpoint, C.endpoint); }
                if (data.consent) {
                    C.key = data.consent.storage_key || C.key;
                    C.cat = data.consent.category || C.cat;
                }
                if (data.beacon_max_events) { C.max = parseInt(data.beacon_max_events, 10) || C.max; }
                if (typeof data.respect_dnt === 'boolean') { C.dnt = data.respect_dnt; }
                start();
            }).catch(function () {});
    } catch (e) {}
}
// Public surface: a CMP bridge can force a consent re-check, host pages may
// report custom events (analytics_events.name / value / props).
window.gereqAnalytics = {
    version: VERSION,
    isEnabled: function () { return !!(st.granted && !st.blocked && C.site); },
    syncConsent: sync,
    track: function (name, props, value) {
        try {
            if (!name || !st.granted || st.blocked || !C.site) { return false; }
            if (name === 'session_replay' && (!st.consent || st.consent.replay !== true)) { return false; }
            var data = props && typeof props === 'object' ? props : {};
            data.name = String(name);
            if (typeof value !== 'undefined') { data.value = value; }
            enqueue('event', data);
            flush(1);
            return true;
        } catch (e) { return false; }
    }
};
if (C.site) { start(); } else { loadConfig(); }
})();
