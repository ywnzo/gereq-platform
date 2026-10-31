/* GEREQ Cookie 0.2.0 — standalone, same-origin consent UI. */
(function () {
  'use strict';
  if (window.gereqCookie) return;
  var script = document.currentScript;
  var endpoint = new URL(script && script.dataset.config || '/api/public/cookie/config', location.href);
  if (endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol)) return;
  fetch(endpoint.href, { credentials: 'same-origin', cache: 'no-store' })
    .then(function (response) { if (!response.ok) throw new Error('Cookie config unavailable'); return response.json(); })
    .then(function (config) {
      if (!config || !config.enabled) {
        window.gereqCookie = { enabled: false, allows: function () { return false; }, open: function () {} };
        document.dispatchEvent(new CustomEvent('gereq:cookie-ready'));
        return;
      }
      var params = new URLSearchParams(location.search);
      if (!config.preview && (params.get('edit') === '1' || params.get('preview') === '1')) return;
      var ids = ['necessary', 'preferences', 'statistics', 'replay', 'marketing'];
      var categories = (config.categories || []).filter(function (category) { return ids.indexOf(category.id) !== -1; });
      if (!categories.some(function (category) { return category.id === 'necessary'; })) return;
      var key = config.storage_key || 'cookie-consent', saved = null, controls = {};
      function read() {
        if (config.preview) return saved;
        try {
          var value = JSON.parse(localStorage.getItem(key));
          var age = Date.now() / 1000 - Number(value && value.ts || 0);
          return value && typeof value === 'object' && age >= -60 && age < config.lifetime_days * 86400 ? value : null;
        } catch (error) { return null; }
      }
      saved = read();
      function privacySignal() {
        return navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true;
      }
      function allows(id) {
        if (id === 'necessary') return true;
        if (!categories.some(function (category) { return category.id === id; })) return false;
        if (privacySignal() && ['statistics', 'replay', 'marketing'].indexOf(id) !== -1) return false;
        if (id === 'replay' && !allows('statistics')) return false;
        return !!saved && saved[id] === true;
      }
      function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (typeof text === 'string') node.textContent = text;
        return node;
      }
      var host = element('div');
      host.setAttribute('data-gereq-cookie-root', '');
      var shadow = host.attachShadow({ mode: 'open' });
      var style = element('style');
      style.textContent = ':host{position:fixed;z-index:2147483000;bottom:20px;right:20px;font:14px/1.6 system-ui,sans-serif;color:var(--cookie-color,#252c27);color-scheme:light}*{box-sizing:border-box}[hidden]{display:none!important}button,input{font:inherit}button{cursor:pointer}button:focus-visible,a:focus-visible,input:focus-visible{outline:3px solid currentColor;outline-offset:3px}.panel{position:relative;width:min(430px,calc(100vw - 40px));max-height:calc(100dvh - 40px);overflow:auto;padding:24px;border:1px solid #8885;border-radius:18px;background:var(--cookie-bg,#fff);box-shadow:0 10px 50px #0003}.heading{font-size:21px;line-height:1.3;margin:0 30px 14px 0}.intro{margin:0 0 14px}.policy{color:inherit;text-underline-offset:3px}.close{position:absolute;right:12px;top:12px;width:32px;height:32px;border:0;background:transparent;color:inherit;font-size:24px;border-radius:6px}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.action{flex:1;min-width:130px;padding:11px 12px;border:1px solid #8886;border-radius:9px;background:transparent;color:inherit}.accept{background:var(--cookie-accent,#cdea63);color:#172018;border-color:var(--cookie-accent,#cdea63)}.settings{width:100%;margin-top:8px;border:0;background:none;color:inherit;text-decoration:underline;text-underline-offset:3px}.category{display:grid;grid-template-columns:1fr auto;gap:6px 12px;padding:14px 0;border-bottom:1px solid #8884}.category strong{font-weight:650}.category p{grid-column:1/-1;font-size:13px;margin:0;opacity:.8}.category input{width:20px;height:20px;accent-color:var(--cookie-accent,#cdea63)}.launcher{border:1px solid #8885;border-radius:50px;background:var(--cookie-bg,#fff);color:inherit;padding:12px 18px;box-shadow:0 4px 20px #0002}.error{color:#b42318;margin:12px 0 0}';
      style.textContent += '.visual{display:flex;align-items:center;justify-content:center;margin:0 0 18px}.visual img{display:block;max-width:100%;width:auto;height:88px;object-fit:contain}.symbol{font-size:38px;line-height:1.2}.launcher{display:flex;align-items:center;gap:10px}.launcher img{width:28px;height:28px;object-fit:contain}.launcher .symbol{font-size:24px}:host(.compact) .panel{width:min(340px,calc(100vw - 40px));padding:18px;border-radius:12px}:host(.compact) .heading{font-size:18px}:host(.compact) .visual img{height:54px}:host(.square) .panel,:host(.square) .action,:host(.square) .launcher{border-radius:2px}:host(.square) .panel{border-top:5px solid var(--cookie-accent,#cdea63)}:host(.banner){left:20px;right:20px}:host(.banner) .panel{width:100%;max-width:960px;margin:auto}:host(.banner) .launcher{margin-left:auto}:host(.banner) .visual{float:left;margin:0 22px 12px 0}:host(.banner) .actions{clear:both}@media(max-width:767px){:host{bottom:12px!important;right:12px!important;left:12px!important}.panel,:host(.compact) .panel{width:100%;max-height:calc(100dvh - 24px)}.launcher{margin-left:auto}:host(.banner) .visual{float:none;margin:0 0 14px}}';
      shadow.appendChild(style);
      var variant = ['card', 'compact', 'square', 'banner'].indexOf(config.style) !== -1 ? config.style : 'card';
      host.className = variant;
      var mobile = matchMedia('(max-width: 767px)');
      var manuallyOpened = false;
      function mobileHidden() { return config.show_on_mobile === false && mobile.matches; }
      function visual(launcherMode) {
        var node;
        if (typeof config.image_url === 'string' && /^\/api\/public\/cookie\/image\/[a-f0-9]{32}\.(png|jpg|webp|gif)$/.test(config.image_url)) {
          node = element('img'); node.src = config.image_url; node.alt = ''; node.decoding = 'async';
        } else if (config.icon) {
          node = element('span', 'symbol', String(config.icon).slice(0, 32));
        } else { return null; }
        node.setAttribute('aria-hidden', 'true');
        if (launcherMode) return node;
        var wrapper = element('div', 'visual'); wrapper.appendChild(node); return wrapper;
      }
      if (config.position === 'left' && variant !== 'banner') { host.style.left = '20px'; host.style.right = 'auto'; }
      [['accent', '--cookie-accent'], ['background', '--cookie-bg'], ['color', '--cookie-color']].forEach(function (pair) {
        var value = config.appearance && config.appearance[pair[0]];
        if (typeof value === 'string' && /^#[a-f0-9]{6}$/i.test(value)) host.style.setProperty(pair[1], value);
      });
      var panel = element('section', 'panel');
      panel.setAttribute('role', 'region');
      panel.setAttribute('aria-labelledby', 'gereq-cookie-heading');
      var heading = element('h2', 'heading', config.title); heading.id = 'gereq-cookie-heading';
      var close = element('button', 'close', '×'); close.type = 'button'; close.setAttribute('aria-label', config.close_label);
      var artwork = visual(false);
      if (artwork) panel.appendChild(artwork);
      panel.append(close, heading, element('p', 'intro', config.text));
      var policy = element('a', 'policy', config.policy_label);
      if (typeof config.policy_path === 'string' && /^\/(?!\/)/.test(config.policy_path) && !/[\s\\]/.test(config.policy_path)) policy.href = config.policy_path;
      panel.appendChild(policy);
      var details = element('div'); details.hidden = true;
      categories.forEach(function (category) {
        var label = element('label', 'category');
        var control = element('input'); control.type = 'checkbox'; control.checked = category.id === 'necessary' || allows(category.id); control.disabled = category.id === 'necessary';
        control.setAttribute('aria-label', category.label);
        controls[category.id] = control;
        label.append(element('strong', '', category.label), control, element('p', '', category.description));
        details.appendChild(label);
      });
      if (controls.statistics && controls.replay) controls.statistics.addEventListener('change', function () {
        if (!controls.statistics.checked) controls.replay.checked = false;
      });
      panel.appendChild(details);
      var actions = element('div', 'actions');
      function button(text, className) { var node = element('button', className, text); node.type = 'button'; return node; }
      var deny = button(config.buttons.deny, 'action');
      var accept = button(config.buttons.allow_all, 'action accept');
      var save = button(config.buttons.save, 'action accept'); save.hidden = true;
      var settings = button(config.buttons.settings, 'settings'); settings.setAttribute('aria-expanded', 'false');
      var error = element('p', 'error'); error.hidden = true; error.setAttribute('role', 'alert');
      actions.append(deny, accept, save); panel.append(actions, settings, error);
      var launcher = button('', 'launcher');
      var launcherVisual = visual(true);
      if (launcherVisual) launcher.appendChild(launcherVisual);
      launcher.appendChild(element('span', '', config.settings_label));
      function hide() { manuallyOpened = false; panel.hidden = true; launcher.hidden = !config.floating_button || mobileHidden(); }
      function open(manual) {
        manuallyOpened = manual === true;
        if (mobileHidden() && !manuallyOpened) { hide(); return; }
        categories.forEach(function (category) { controls[category.id].checked = category.id === 'necessary' || allows(category.id); });
        panel.hidden = false; launcher.hidden = true;
      }
      function choose(mode) {
        var choice = { v: 1, version: config.version, ts: Math.floor(Date.now() / 1000), necessary: true };
        ids.forEach(function (id) {
          if (id !== 'necessary') choice[id] = !!controls[id] && (mode === 'all' || mode === 'selection' && controls[id].checked);
        });
        if (!choice.statistics) choice.replay = false;
        try {
          if (!config.preview) localStorage.setItem(key, JSON.stringify(choice));
          saved = choice; error.hidden = true; hide();
          if (!config.preview) document.dispatchEvent(new CustomEvent('gereq:consent-changed'));
        } catch (exception) { error.textContent = config.error_text; error.hidden = false; }
      }
      deny.addEventListener('click', function () { choose('none'); });
      accept.addEventListener('click', function () { choose('all'); });
      save.addEventListener('click', function () { choose('selection'); });
      settings.addEventListener('click', function () {
        details.hidden = !details.hidden; save.hidden = details.hidden; accept.hidden = !details.hidden;
        settings.setAttribute('aria-expanded', String(!details.hidden));
      });
      close.addEventListener('click', hide); launcher.addEventListener('click', function () { open(true); });
      panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') hide(); });
      shadow.append(panel, launcher); document.body.appendChild(host);
      window.gereqCookie = { enabled: true, open: function () { open(true); }, close: hide, allows: allows };
      document.addEventListener('gereq:cookie-open', function () { open(true); });
      mobile.addEventListener('change', function () {
        if (mobileHidden() && !manuallyOpened) hide();
        else if (!panel.hidden || !saved || saved.version !== config.version) open(manuallyOpened);
        else hide();
      });
      addEventListener('storage', function (event) {
        if (event.key !== key || config.preview) return;
        saved = read(); hide(); document.dispatchEvent(new CustomEvent('gereq:consent-changed'));
      });
      if (config.preview || !saved || saved.version !== config.version) open(); else hide();
      document.dispatchEvent(new CustomEvent('gereq:cookie-ready'));
    }).catch(function () {
      document.dispatchEvent(new CustomEvent('gereq:cookie-error'));
    });
})();
