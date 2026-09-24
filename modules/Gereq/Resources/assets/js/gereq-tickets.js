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

    var widgetOrigin = '';
    try {
        widgetOrigin = new URL(script.src).origin;
    } catch (e) {
        return;
    }

    var parentOrigin = window.location.origin;
    var open = false;
    var knownUser = normalizeUser(
        parseUserAttr(script.getAttribute('data-user') || script.getAttribute('data-x-user'))
        || {
            email: script.getAttribute('data-email'),
            name: script.getAttribute('data-name'),
        }
    );

    function trimStr(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function parseUserAttr(raw) {
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function normalizeUser(input) {
        if (!input || typeof input !== 'object') {
            return null;
        }
        var email = trimStr(input.email);
        var name = trimStr(input.name);
        if (!email && !name) {
            return null;
        }
        return {
            email: email.slice(0, 180),
            name: name.slice(0, 120),
        };
    }

    function attrOn(name) {
        var raw = (script.getAttribute(name) || '').trim().toLowerCase();
        return raw === '1' || raw === 'true' || raw === 'on' || raw === 'yes';
    }

    var showTeaser = attrOn('data-teaser');

    var host = document.createElement('div');
    host.id = 'gereq-tickets-root';

    function closedHostStyle() {
        return [
            'all:initial',
            'display:block',
            'position:fixed',
            'z-index:2147483646',
            'right:20px',
            'bottom:20px',
            'width:' + (showTeaser ? 'auto' : '60px'),
            'height:' + (showTeaser ? '80px' : '60px'),
            'overflow:hidden',
            'pointer-events:none',
            'background:transparent',
            'background-color:transparent',
            'border:0',
            'box-shadow:none',
            'filter:none',
            'backdrop-filter:none',
            '-webkit-backdrop-filter:none',
            'outline:none',
        ].join(';');
    }

    host.style.cssText = closedHostStyle();

    var shadow = host.attachShadow({ mode: 'closed' });
    var style = document.createElement('style');
    style.textContent = [
        ':host { all: initial; background: transparent; box-shadow: none; filter: none; }',
        '* { box-sizing: border-box; font-family: ui-sans-serif, system-ui, sans-serif; }',
        '.wrap { position: relative; display: flex; align-items: flex-end; justify-content: flex-end; gap: 8px; padding: 0; pointer-events: none; background: transparent; box-shadow: none; filter: none; }',
        '.wrap.has-teaser { padding: 10px 8px 0 0; }',
        '.teaser {',
        '  position: relative; z-index: 2;',
        '  pointer-events: auto; max-width: 200px; padding: 9px 12px;',
        '  border-radius: 16px; background: #0A0B0A; color: #F5F5EF;',
        '  font-size: 12.5px; font-weight: 550; line-height: 1.35; letter-spacing: -0.01em;',
        '  box-shadow: none;',
        '  border: 1px solid rgb(245 245 239 / 0.08);',
        '}',
        '.bubble {',
        '  position: relative; z-index: 2; width: 60px; height: 60px; padding: 0; border: 0;',
        '  border-radius: 9999px; background: #D7FF45; color: #0A0B0A; cursor: pointer;',
        '  pointer-events: auto;',
        '  box-shadow: none;',
        '  filter: none;',
        '  transition: transform 0.18s ease;',
        '}',
        '.bubble:hover { transform: translateY(-1px); box-shadow: none; }',
        '.bubble::after {',
        '  content: ""; position: absolute; inset: -5px; border-radius: 9999px;',
        '  border: 1.5px solid rgb(215 255 69 / 0.4); animation: pulse 2.6s ease-out infinite;',
        '}',
        '.bubble.has-unread::after {',
        '  border-color: rgb(255 107 94 / 0.5); animation-duration: 1.6s;',
        '}',
        '@keyframes pulse { 0% { transform: scale(0.94); opacity: 0.75; } 100% { transform: scale(1.14); opacity: 0; } }',
        '@keyframes badge-pop { 0% { transform: scale(0.6); } 70% { transform: scale(1.08); } 100% { transform: scale(1); } }',
        '.bubble svg { display: block; margin: 0 auto; }',
        '.badge {',
        '  position: absolute; top: -1px; right: -1px; z-index: 3;',
        '  min-width: 18px; height: 18px; padding: 0 5px;',
        '  border-radius: 999px; background: #FF6B5E; color: #fff;',
        '  font-size: 10px; font-weight: 750; line-height: 18px; text-align: center;',
        '  letter-spacing: -0.02em;',
        '  box-shadow: 0 0 0 2px #0A0B0A; pointer-events: none;',
        '  animation: badge-pop 0.24s ease-out;',
        '}',
        '.badge[hidden], .bubble[hidden], .teaser[hidden] { display: none !important; }',
        'iframe {',
        '  position: absolute; right: 0; bottom: 0;',
        '  border: 0; background: transparent !important; pointer-events: auto;',
        '  color-scheme: light; border-radius: 18px; z-index: 1;',
        '}',
        'iframe.is-parked {',
        '  width: 60px !important; height: 60px !important;',
        '  opacity: 0; pointer-events: none; z-index: 0;',
        '}',
        '.grip {',
        '  position: absolute; top: 8px; left: 8px; z-index: 2;',
        '  width: 28px; height: 28px; padding: 0; border: 0;',
        '  border-radius: 8px;',
        '  background: rgb(10 11 10 / 0.55);',
        '  box-shadow: 0 1px 0 rgb(245 245 239 / 0.12) inset, 0 4px 12px rgb(10 11 10 / 0.25);',
        '  cursor: nwse-resize; pointer-events: auto;',
        '}',
        '.grip:hover { background: rgb(10 11 10 / 0.75); }',
        '.grip::before {',
        '  content: ""; position: absolute; top: 8px; left: 8px;',
        '  width: 10px; height: 10px;',
        '  border-top: 2px solid #D7FF45;',
        '  border-left: 2px solid #D7FF45;',
        '  border-radius: 2px 0 0 0;',
        '}',
        '.grip::after {',
        '  content: ""; position: absolute; top: 12px; left: 12px;',
        '  width: 6px; height: 6px;',
        '  border-top: 2px solid rgb(215 255 69 / 0.55);',
        '  border-left: 2px solid rgb(215 255 69 / 0.55);',
        '  border-radius: 1px 0 0 0;',
        '}',
        '.grip[hidden] { display: none !important; }',
    ].join('\n');

    var wrap = document.createElement('div');
    wrap.className = 'wrap';

    var defaultTeaser = isTest
        ? 'TEST · Napište nám (mock data).'
        : 'Potřebujete pomoc? Napište nám.';
    var defaultAria = isTest ? 'Otevřít testovací chat' : 'Otevřít chat';

    var teaser = document.createElement('div');
    teaser.className = 'teaser';
    teaser.textContent = defaultTeaser;
    teaser.hidden = !showTeaser;

    var bubble = document.createElement('button');
    bubble.className = 'bubble';
    bubble.type = 'button';
    bubble.setAttribute('aria-label', defaultAria);
    bubble.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6.4 5h11.2A2.4 2.4 0 0 1 20 7.4v6.2a2.4 2.4 0 0 1-2.4 2.4h-4.15L8.8 19.2V16H6.4A2.4 2.4 0 0 1 4 13.6V7.4A2.4 2.4 0 0 1 6.4 5Z" fill="currentColor"/></svg>';

    var badge = document.createElement('span');
    badge.className = 'badge';
    badge.hidden = true;
    badge.setAttribute('aria-hidden', 'true');
    bubble.appendChild(badge);

    var iframe = document.createElement('iframe');
    iframe.className = 'is-parked';
    iframe.setAttribute('title', 'Live chat');
    iframe.setAttribute('allow', 'clipboard-write');
    iframe.setAttribute('loading', 'eager');

    var iframeReady = false;
    var widgetWindow = null;
    var pendingToIframe = [];

    var grip = document.createElement('button');
    grip.className = 'grip';
    grip.type = 'button';
    grip.hidden = true;
    grip.setAttribute('aria-label', 'Změnit velikost chatu');

    var SIZE_KEY = 'gereq-tickets-size:' + key;
    var TOKEN_KEY = 'gereq-tickets-visitor:' + key;
    var UNREAD_KEY = 'gereq-tickets-unread:' + key;
    var unreadCount = 0;
    var customSize = null;
    var dragging = false;
    var dragStart = null;
    var lightboxMode = false;

    try {
        var stored = JSON.parse(window.localStorage.getItem(SIZE_KEY) || 'null');
        if (stored && stored.width && stored.height) {
            customSize = { width: Number(stored.width), height: Number(stored.height) };
        }
    } catch (e) {}

    function clampSize(width, height) {
        var maxW = Math.max(window.innerWidth - 16, 280);
        var maxH = Math.max(window.innerHeight - 16, 360);

        return {
            width: Math.min(Math.max(Math.round(width), 320), maxW),
            height: Math.min(Math.max(Math.round(height), 420), maxH),
        };
    }

    function panelSize() {
        var mobile = window.innerWidth < 480;

        if (mobile) {
            return {
                width: Math.max(window.innerWidth - 16, 280),
                height: Math.max(window.innerHeight - 24, 460),
                right: '8px',
                bottom: '8px',
                resizable: false,
            };
        }

        var size = clampSize(
            customSize && customSize.width ? customSize.width : 400,
            customSize && customSize.height ? customSize.height : 640,
        );

        return {
            width: size.width,
            height: size.height,
            right: '20px',
            bottom: '20px',
            resizable: true,
        };
    }

    function applyOpenSize(persist) {
        if (lightboxMode) {
            return null;
        }

        var size = panelSize();
        host.style.top = '';
        host.style.left = '';
        host.style.width = size.width + 'px';
        host.style.height = size.height + 'px';
        host.style.right = size.right;
        host.style.bottom = size.bottom;
        iframe.style.width = size.width + 'px';
        iframe.style.height = size.height + 'px';
        iframe.style.borderRadius = '';
        grip.hidden = !size.resizable;

        if (persist) {
            customSize = { width: size.width, height: size.height };
            try {
                window.localStorage.setItem(SIZE_KEY, JSON.stringify(customSize));
            } catch (e) {}
        }

        return size;
    }

    function parkIframe() {
        host.style.overflow = 'hidden';
        host.style.background = 'transparent';
        iframe.style.width = '60px';
        iframe.style.height = '60px';
        iframe.style.background = 'transparent';
        iframe.classList.add('is-parked');
    }

    function applyTeaser() {
        var visible = showTeaser && !open;
        teaser.hidden = !visible;
        wrap.classList.toggle('has-teaser', visible);
        if (!open) {
            host.style.cssText = closedHostStyle();
            parkIframe();
        }
    }

    function setTeaser(on) {
        showTeaser = !!on;
        applyTeaser();
        if (showTeaser && !open) {
            applyUnread(unreadCount);
        }
        return showTeaser;
    }

    function setLightboxMode(on) {
        lightboxMode = !!on;
        host.setAttribute('data-lightbox', lightboxMode ? '1' : '0');

        if (lightboxMode) {
            host.style.top = '0';
            host.style.left = '0';
            host.style.right = '0';
            host.style.bottom = '0';
            host.style.width = '100%';
            host.style.height = '100%';
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.borderRadius = '0';
            grip.hidden = true;
            return;
        }

        iframe.style.borderRadius = '';
        if (open) {
            applyOpenSize(false);
        }
    }

    function setOpen(next) {
        open = next;

        if (open) {
            iframe.classList.remove('is-parked');
            applyOpenSize(false);
            bubble.hidden = true;
            teaser.hidden = true;
            wrap.classList.remove('has-teaser');
            applyUnread(0);
            notifyUser();
            postToIframe('open');
            notifyPage();
        } else {
            setLightboxMode(false);
            grip.hidden = true;
            bubble.hidden = false;
            applyTeaser();
            postToIframe('close');
            applyUnread(unreadCount);
        }
    }

    function unreadTeaser(count) {
        if (count === 1) {
            return 'Máte novou zprávu';
        }
        if (count >= 2 && count <= 4) {
            return 'Máte ' + count + ' nové zprávy';
        }
        return 'Máte ' + count + ' nových zpráv';
    }

    function applyUnread(count) {
        unreadCount = Math.max(0, parseInt(count, 10) || 0);
        try {
            window.localStorage.setItem(UNREAD_KEY, String(unreadCount));
        } catch (e) {}

        var show = unreadCount > 0 && !open;
        badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
        badge.hidden = !show;
        bubble.classList.toggle('has-unread', show);
        host.setAttribute('data-unread', String(unreadCount));

        if (!show) {
            teaser.textContent = defaultTeaser;
            bubble.setAttribute('aria-label', defaultAria);
            return;
        }

        var label = unreadTeaser(unreadCount);
        teaser.textContent = label;
        bubble.setAttribute('aria-label', defaultAria + ', ' + label);
    }

    try {
        applyUnread(window.localStorage.getItem(UNREAD_KEY));
    } catch (e) {}

    function readStoredToken() {
        try {
            var token = window.localStorage.getItem(TOKEN_KEY) || '';
            return /^[a-f0-9]{64}$/.test(token) ? token : '';
        } catch (e) {
            return '';
        }
    }

    function storeToken(token) {
        if (!token || !/^[a-f0-9]{64}$/.test(token)) {
            return;
        }
        try {
            window.localStorage.setItem(TOKEN_KEY, token);
        } catch (e) {}
    }

    function widgetUrl() {
        var url = widgetOrigin + '/tickets/widget?key=' + encodeURIComponent(key)
            + '&origin=' + encodeURIComponent(parentOrigin)
            + '&page=' + encodeURIComponent(window.location.href)
            + '&embed=13';
        return url;
    }

    function iframeAcceptsWidgetMessages() {
        return iframeReady && widgetWindow;
    }

    function postToIframe(eventName, extra) {
        var payload = { source: 'gereq-tickets-host', event: eventName };
        if (extra) {
            Object.keys(extra).forEach(function (prop) {
                payload[prop] = extra[prop];
            });
        }
        if (!iframeAcceptsWidgetMessages()) {
            pendingToIframe.push(payload);
            return;
        }
        try {
            widgetWindow.postMessage(payload, widgetOrigin);
        } catch (e) {}
    }

    function flushPendingToIframe() {
        if (!iframeAcceptsWidgetMessages()) {
            return;
        }
        var queued = pendingToIframe.slice();
        pendingToIframe = [];
        queued.forEach(function (payload) {
            try {
                widgetWindow.postMessage(payload, widgetOrigin);
            } catch (e) {}
        });
    }

    function notifyVisitor() {
        postToIframe('init', { visitor_token: readStoredToken() });
    }

    function markIframeReady() {
        if (iframeReady) {
            notifyVisitor();
            return;
        }
        iframeReady = true;
        flushPendingToIframe();
        notifyVisitor();
    }

    function currentPage() {
        return window.location.href;
    }

    function notifyPage() {
        postToIframe('page', { page: currentPage() });
    }

    function notifyUser() {
        if (!knownUser) {
            return;
        }
        postToIframe('user', { user: knownUser });
    }

    function setUser(input) {
        knownUser = normalizeUser(input);
        notifyUser();
        return knownUser;
    }

    function hookHistory() {
        var push = history.pushState;
        var replace = history.replaceState;
        history.pushState = function () {
            var result = push.apply(this, arguments);
            notifyPage();
            return result;
        };
        history.replaceState = function () {
            var result = replace.apply(this, arguments);
            notifyPage();
            return result;
        };
        window.addEventListener('popstate', notifyPage);
        window.addEventListener('hashchange', notifyPage);
    }

    iframe.addEventListener('load', function () {
        try {
            var frameOrigin = iframe.contentWindow && iframe.contentWindow.location.origin;
            if (!frameOrigin || frameOrigin !== widgetOrigin) {
                iframeReady = false;
                widgetWindow = null;
            }
        } catch (e) {}
    });

    bubble.addEventListener('click', function () {
        setOpen(true);
    });
    teaser.addEventListener('click', function () {
        setOpen(true);
    });

    window.addEventListener('message', function (event) {
        if (event.origin !== widgetOrigin) {
            return;
        }
        var data = event.data;
        if (!data || data.source !== 'gereq-tickets') {
            return;
        }
        if (data.event === 'ready') {
            widgetWindow = event.source;
            markIframeReady();
            return;
        }
        if (data.event === 'token') {
            storeToken(data.visitor_token);
            return;
        }
        if (data.event === 'close') {
            setOpen(false);
            return;
        }
        if (data.event === 'unread') {
            applyUnread(data.count);
            return;
        }
        if (data.event === 'lightbox') {
            if (open) {
                setLightboxMode(!!data.open);
            }
            return;
        }
    });

    window.setInterval(function () {
        if (!open) {
            postToIframe('tick');
        }
    }, 8000);

    grip.addEventListener('pointerdown', function (event) {
        if (!open || window.innerWidth < 480) {
            return;
        }
        event.preventDefault();
        dragging = true;
        dragStart = {
            x: event.clientX,
            y: event.clientY,
            width: host.offsetWidth,
            height: host.offsetHeight,
        };
        iframe.style.pointerEvents = 'none';
        grip.setPointerCapture(event.pointerId);
    });

    grip.addEventListener('pointermove', function (event) {
        if (!dragging || !dragStart) {
            return;
        }
        customSize = {
            width: dragStart.width + (dragStart.x - event.clientX),
            height: dragStart.height + (dragStart.y - event.clientY),
        };
        applyOpenSize(false);
    });

    function stopDrag() {
        if (!dragging) {
            return;
        }
        dragging = false;
        dragStart = null;
        iframe.style.pointerEvents = 'auto';
        applyOpenSize(true);
    }

    grip.addEventListener('pointerup', stopDrag);
    grip.addEventListener('pointercancel', stopDrag);

    window.addEventListener('resize', function () {
        if (open) {
            applyOpenSize(false);
        }
    });

    hookHistory();

    wrap.appendChild(teaser);
    wrap.appendChild(bubble);
    applyTeaser();
    shadow.appendChild(style);
    shadow.appendChild(iframe);
    shadow.appendChild(grip);
    shadow.appendChild(wrap);

    function mount() {
        if (!host.parentNode) {
            document.body.appendChild(host);
        }
        if (!iframe.getAttribute('src')) {
            parkIframe();
            iframe.src = widgetUrl();
        }
    }

    if (document.body) {
        mount();
    } else {
        document.addEventListener('DOMContentLoaded', mount);
    }

    var api = {
        setUser: setUser,
        setTeaser: setTeaser,
        getUser: function () {
            return knownUser ? { email: knownUser.email, name: knownUser.name } : null;
        },
    };

    var pending = window['GereqTickets'];
    window['GereqTickets'] = api;

    if (Array.isArray(pending)) {
        pending.forEach(function (call) {
            if (!call || !call.length) {
                return;
            }
            var method = call[0];
            if (method === 'setUser' || method === 'x-user' || method === 'user') {
                setUser(call[1]);
            }
            if (method === 'setTeaser' || method === 'teaser') {
                setTeaser(call[1]);
            }
        });
    } else if (pending && typeof pending === 'object') {
        if (pending.user) {
            setUser(pending.user);
        } else if (pending['x-user']) {
            setUser(pending['x-user']);
        }
        if (typeof pending.teaser !== 'undefined') {
            setTeaser(pending.teaser);
        } else if (typeof pending.setTeaser !== 'undefined') {
            setTeaser(pending.setTeaser);
        }
    }
})();
