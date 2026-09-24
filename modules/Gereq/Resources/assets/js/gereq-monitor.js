(function () {
    var script = document.currentScript;
    if (!script) {
        return;
    }

    var key = script.getAttribute('data-key');
    if (!key) {
        return;
    }
    var isTest = key.indexOf('pk_test_') === 0;

    var apiOrigin = '';
    try {
        apiOrigin = new URL(script.src).origin;
    } catch (e) {
        return;
    }

    var pageOrigin = window.location.origin;
    var visitorStorageKey = 'gereq_sec_visitor_' + key;
    var startedAt = Date.now();
    var hadPointer = false;
    var hadScroll = false;
    var hadKeydown = false;
    var firstInteractAt = null;
    var sent = false;
    var blocked = false;

    function markInteract() {
        if (firstInteractAt === null) {
            firstInteractAt = Date.now();
        }
    }

    window.addEventListener('pointerdown', function () {
        hadPointer = true;
        markInteract();
    }, { once: true, passive: true });
    window.addEventListener('scroll', function () {
        hadScroll = true;
        markInteract();
    }, { once: true, passive: true });
    window.addEventListener('keydown', function () {
        hadKeydown = true;
        markInteract();
    }, { once: true });

    function pluginsCount() {
        try {
            return navigator.plugins ? navigator.plugins.length : -1;
        } catch (e) {
            return -1;
        }
    }

    function collectSignals() {
        var tti = firstInteractAt === null ? Date.now() - startedAt : firstInteractAt - startedAt;
        return {
            webdriver: !!(navigator.webdriver),
            plugins: pluginsCount(),
            tti_ms: tti,
            had_pointer: hadPointer,
            had_scroll: hadScroll,
            had_keydown: hadKeydown,
            honeypot_touched: false,
        };
    }

    function setVisitor(id) {
        if (!id) {
            return;
        }
        try {
            sessionStorage.setItem(visitorStorageKey, id);
        } catch (e) {}
        window.dispatchEvent(new CustomEvent('gereq-security:visitor', { detail: { visitor: id } }));
    }

    function softBlock() {
        if (blocked) {
            return;
        }
        blocked = true;
        var forms = document.querySelectorAll('form');
        Array.prototype.forEach.call(forms, function (form) {
            form.setAttribute('data-gereq-security-skip', '');
            Array.prototype.forEach.call(form.elements, function (el) {
                try {
                    el.disabled = true;
                } catch (e) {}
            });
        });

        if (document.getElementById('gereq-monitor-block')) {
            return;
        }
        var banner = document.createElement('div');
        banner.id = 'gereq-monitor-block';
        banner.setAttribute('role', 'alert');
        banner.style.cssText = [
            'position:fixed',
            'left:16px',
            'right:16px',
            'bottom:72px',
            'z-index:2147483646',
            'padding:14px 16px',
            'border-radius:12px',
            'background:#0A0B0A',
            'color:#F5F5EF',
            'font:600 14px/1.4 ui-sans-serif,system-ui,sans-serif',
            'box-shadow:0 10px 30px rgba(10,11,10,.28)',
        ].join(';');
        banner.textContent = 'Tato stránka je dočasně chráněna proti automatickému odesílání.';
        document.body.appendChild(banner);
    }

    function ensureBadge() {
        if (script.getAttribute('data-badge') === 'false') {
            return;
        }
        if (document.getElementById('gereq-monitor-badge')) {
            return;
        }

        if (!document.getElementById('gereq-monitor-badge-style')) {
            var style = document.createElement('style');
            style.id = 'gereq-monitor-badge-style';
            style.textContent = [
                '#gereq-monitor-badge{position:fixed;right:14px;bottom:14px;z-index:2147483645;display:inline-flex;align-items:stretch;max-width:calc(100vw - 28px);font-family:ui-sans-serif,system-ui,sans-serif;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(10,11,10,.18);background:#0a0b0a;border:1px solid #0a0b0a;text-decoration:none;color:inherit}',
                '#gereq-monitor-badge__mark{flex:0 0 42px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:#0a0b0a;color:#d7ff45}',
                '#gereq-monitor-badge__mark svg{width:20px;height:20px;display:block}',
                '#gereq-monitor-badge__panel{display:flex;align-items:center;flex:0 1 0;min-width:0;max-width:0;opacity:0;overflow:hidden;padding:0;background:#0a0b0a;color:#f5f5ef;min-height:42px;transition:max-width .2s ease,opacity .15s ease,padding .2s ease,flex-basis .2s ease}',
                '#gereq-monitor-badge:hover #gereq-monitor-badge__panel,#gereq-monitor-badge:focus-within #gereq-monitor-badge__panel{flex:0 1 auto;max-width:16rem;opacity:1;padding:0 .85rem 0 .1rem}',
                '#gereq-monitor-badge__copy{display:flex;flex-direction:column;line-height:1.15;white-space:nowrap;min-width:max-content}',
                '#gereq-monitor-badge__copy strong{font-size:11px;font-weight:700;letter-spacing:.01em;color:#f5f5ef}',
                '#gereq-monitor-badge__copy span{font-size:9px;color:rgba(245,245,239,.7)}',
                '@media (max-width:420px){#gereq-monitor-badge{right:10px;bottom:10px}}',
            ].join('');
            document.head.appendChild(style);
        }

        var root = document.createElement('a');
        root.id = 'gereq-monitor-badge';
        root.href = 'https://www.gereq.cz';
        root.target = '_blank';
        root.rel = 'noopener noreferrer';
        root.setAttribute('aria-label', isTest ? 'TEST · GEREQ Security' : 'Chráněno GEREQ Security');
        root.innerHTML = [
            '<span id="gereq-monitor-badge__mark" aria-hidden="true">',
            '  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">',
            '    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5l7 3v5.2c0 4.2-2.8 7.9-7 9.3-4.2-1.4-7-5.1-7-9.3V6.5l7-3z"/>',
            '    <path stroke-linecap="round" stroke-linejoin="round" d="M9.2 12.1l1.8 1.8 3.8-3.9"/>',
            '  </svg>',
            '</span>',
            '<span id="gereq-monitor-badge__panel">',
            '  <span id="gereq-monitor-badge__copy">',
            isTest
                ? '    <strong>TEST · GEREQ Security</strong><span>mock data · funguje odkudkoliv</span>'
                : '    <strong>Chráněno GEREQ Security</strong><span>ochrana proti botům</span>',
            '  </span>',
            '</span>',
        ].join('');

        document.body.appendChild(root);
    }

    function requestChallenge() {
        window.dispatchEvent(new CustomEvent('gereq-security:challenge'));
        if (!window['GereqSecurity']) {
            var existing = document.querySelector('script[src*="gereq-security.js"]');
            if (existing) {
                return;
            }
            var s = document.createElement('script');
            s.src = apiOrigin + '/js/gereq-security.js';
            s.async = true;
            s.setAttribute('data-key', key);
            s.setAttribute('data-auto', 'true');
            document.head.appendChild(s);
        }
    }

    function sendBeacon() {
        if (sent) {
            return;
        }
        sent = true;

        var payload = {
            key: key,
            origin: pageOrigin,
            path: window.location.pathname + window.location.search,
            signals: collectSignals(),
        };

        // key in query so OPTIONS preflight can resolve the site (no body).
        fetch(apiOrigin + '/security/api/beacon?key=' + encodeURIComponent(key), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Origin: pageOrigin,
            },
            credentials: 'omit',
            body: JSON.stringify(payload),
            keepalive: true,
        }).then(function (res) {
            if (!res.ok) {
                return null;
            }
            return res.json();
        }).then(function (data) {
            if (!data) {
                return;
            }
            if (data.visitor) {
                setVisitor(data.visitor);
            }
            if (data.action === 'block') {
                softBlock();
            } else if (data.action === 'challenge') {
                requestChallenge();
            }
        }).catch(function () {});
    }

    function schedule() {
        ensureBadge();
        // Give humans a short window to interact before scoring.
        setTimeout(sendBeacon, 1800);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendBeacon();
            }
        });
        window.addEventListener('pagehide', sendBeacon);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            schedule();
        });
    } else {
        schedule();
    }

    window['GereqMonitor'] = {
        ping: sendBeacon,
    };
})();
