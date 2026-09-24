(function () {
    var script = document.currentScript;
    if (!script) {
        return;
    }

    var key = script.getAttribute('data-key');
    if (!key) {
        return;
    }

    var apiOrigin = '';
    try {
        apiOrigin = new URL(script.src).origin;
    } catch (e) {
        return;
    }

    var pageOrigin = window.location.origin;
    var autoAll = script.getAttribute('data-auto') === 'true';
    var alwaysCaptcha = script.getAttribute('data-captcha') === 'always';
    var visitorStorageKey = 'gereq_sec_visitor_' + key;
    var startedAt = Date.now();
    var hadPointer = false;
    var hadScroll = false;
    var hadKeydown = false;
    var firstInteractAt = null;
    var forms = [];

    function markInteract() {
        if (firstInteractAt === null) {
            firstInteractAt = Date.now();
        }
    }

    /** Safe badge render — never innerHTML with server/admin text. */
    function setBadgeLabel(el, text) {
        if (!el || !text) {
            return;
        }
        var label = String(text);
        var needle = 'GEREQ Security';
        var idx = label.indexOf(needle);
        el.textContent = '';
        if (idx === -1) {
            el.textContent = label;
            return;
        }
        el.appendChild(document.createTextNode(label.slice(0, idx)));
        var strong = document.createElement('strong');
        strong.textContent = needle;
        el.appendChild(strong);
        el.appendChild(document.createTextNode(label.slice(idx + needle.length)));
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

    function visitorId() {
        try {
            return sessionStorage.getItem(visitorStorageKey) || '';
        } catch (e) {
            return '';
        }
    }

    function setVisitor(id) {
        if (!id) {
            return;
        }
        try {
            sessionStorage.setItem(visitorStorageKey, id);
        } catch (e) {}
    }

    window.addEventListener('gereq-security:visitor', function (ev) {
        if (ev && ev.detail && ev.detail.visitor) {
            setVisitor(ev.detail.visitor);
        }
    });

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function pluginsCount() {
        try {
            return navigator.plugins ? navigator.plugins.length : -1;
        } catch (e) {
            return -1;
        }
    }

    function collectSignals(honeypotTouched) {
        var tti = firstInteractAt === null ? Date.now() - startedAt : firstInteractAt - startedAt;
        return {
            webdriver: !!(navigator.webdriver),
            plugins: pluginsCount(),
            tti_ms: tti,
            had_pointer: hadPointer,
            had_scroll: hadScroll,
            had_keydown: hadKeydown,
            honeypot_touched: !!honeypotTouched,
        };
    }

    function ensureStyle() {
        if (document.getElementById('gereq-security-style')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'gereq-security-style';
        style.textContent = [
            '.gereq-sec{font-family:ui-sans-serif,system-ui,sans-serif;margin:0.85rem 0 0.25rem;color:#374151}',
            '.gereq-sec__label{display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.5rem}',
            '.gereq-sec__row{display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap}',
            '.gereq-sec__challenge{position:relative;display:flex;align-items:center;overflow:hidden;user-select:none;border:1px solid #e5e7eb;border-radius:0.9rem;background:radial-gradient(ellipse at 30% 40%,rgba(224,122,47,.12),transparent 55%),radial-gradient(ellipse at 70% 70%,rgba(43,108,176,.1),transparent 50%),#eef0f2;filter:blur(.85px) contrast(1.35) saturate(1.15)}',
            '.gereq-sec__challenge::before{content:"";position:absolute;inset:0;background:repeating-linear-gradient(-14deg,transparent 0 2px,rgba(224,122,47,.07) 2px 3px),repeating-linear-gradient(72deg,transparent 0 3px,rgba(10,31,56,.05) 3px 4px);pointer-events:none;z-index:2;mix-blend-mode:multiply}',
            '.gereq-sec__challenge::after{content:"";position:absolute;left:-6%;right:-6%;top:42%;height:2px;background:linear-gradient(90deg,transparent,rgba(224,122,47,.55) 20%,rgba(10,31,56,.28) 50%,rgba(224,122,47,.55) 80%,transparent);transform:rotate(-6deg);pointer-events:none;z-index:2;opacity:.8}',
            '.gereq-sec__image{display:block;width:168px;height:52px;pointer-events:none;position:relative;z-index:1}',
            '.gereq-sec__dots{display:flex;align-items:center;justify-content:center;gap:0.35rem;width:168px;height:52px;color:#8892a0;font-size:1.4rem;letter-spacing:0.2em}',
            '.gereq-sec__input{width:7.5rem;height:2.75rem;padding:0 0.75rem;border:1px solid #e5e7eb;background:#f8fafc;border-radius:0.9rem;text-align:center;letter-spacing:0.14em;text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:0.9rem;outline:none}',
            '.gereq-sec__input:focus{border-color:#1E4D7B;box-shadow:0 0 0 3px rgba(30,77,123,.15)}',
            '.gereq-sec__refresh{width:2.75rem;height:2.75rem;border:1px solid #e5e7eb;background:#fff;border-radius:0.9rem;color:#6b7280;font-size:1.15rem;line-height:1;cursor:pointer}',
            '.gereq-sec__refresh:disabled{opacity:.5;cursor:default}',
            '.gereq-sec__badge{display:inline-flex;align-items:center;gap:0.35rem;margin-top:0.15rem;font-size:0.65rem;line-height:1.3;letter-spacing:0.02em;color:#8892a0;text-decoration:none}',
            '.gereq-sec__badge strong{color:#6b7280;font-weight:600}',
            '.gereq-sec__badge svg{width:12px;height:12px;flex-shrink:0}',
            '.gereq-sec__hp{position:absolute!important;left:-9999px!important;height:0!important;width:0!important;overflow:hidden!important}',
            '.gereq-sec__error{margin-top:0.35rem;font-size:0.75rem;color:#dc2626}',
            '.gereq-sec__challenge-wrap[hidden]{display:none!important}',
        ].join('');
        document.head.appendChild(style);
    }

    function targetForms() {
        var marked = qsa('form[data-gereq-security]');
        if (marked.length) {
            return marked;
        }
        if (autoAll) {
            return qsa('form').filter(function (form) {
                return !form.hasAttribute('data-gereq-security-skip');
            });
        }
        return [];
    }

    function mountForm(form) {
        if (form.getAttribute('data-gereq-security-mounted') === '1') {
            return form.__gereqSecurity || null;
        }
        form.setAttribute('data-gereq-security-mounted', '1');
        ensureStyle();

        var hpWrap = document.createElement('div');
        hpWrap.className = 'gereq-sec__hp';
        hpWrap.setAttribute('aria-hidden', 'true');
        var hpId = 'gereq-hp-' + Math.random().toString(36).slice(2, 8);
        hpWrap.innerHTML = '<label for="' + hpId + '">Web</label>' +
            '<input id="' + hpId + '" type="text" name="website_url" tabindex="-1" autocomplete="off" value="">';
        form.appendChild(hpWrap);

        var root = document.createElement('div');
        root.className = 'gereq-sec';
        root.setAttribute('aria-label', 'Ochrana formuláře GEREQ Security');
        root.innerHTML = [
            '<div class="gereq-sec__challenge-wrap" hidden>',
            '  <div class="gereq-sec__label">Kontrola</div>',
            '  <div class="gereq-sec__row">',
            '    <div class="gereq-sec__challenge"><div class="gereq-sec__dots"><span>·</span><span>·</span><span>·</span><span>·</span><span>·</span></div></div>',
            '    <input class="gereq-sec__input" type="text" name="captcha_answer" inputmode="text" autocapitalize="characters" spellcheck="false" placeholder="KÓD" aria-label="Přepište kód kontroly" autocomplete="off" maxlength="8" disabled>',
            '    <button type="button" class="gereq-sec__refresh" aria-label="Nová výzva" title="Nová výzva" disabled>↻</button>',
            '  </div>',
            '</div>',
            '<input type="hidden" name="captcha_token" value="">',
            '<input type="hidden" name="gereq_visitor" value="">',
            '<input type="hidden" name="gereq_pass_token" value="">',
            '<a class="gereq-sec__badge" href="https://www.gereq.cz" target="_blank" rel="noopener noreferrer">',
            '  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5l7 3v5.2c0 4.2-2.8 7.9-7 9.3-4.2-1.4-7-5.1-7-9.3V6.5l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.2 12.1l1.8 1.8 3.8-3.9"/></svg>',
            '  <span>Formulář chráněn <strong>GEREQ Security</strong></span>',
            '</a>',
            '<p class="gereq-sec__error" hidden></p>',
        ].join('');

        var submit = qs('button[type="submit"], input[type="submit"]', form);
        if (submit && submit.parentNode) {
            submit.parentNode.insertBefore(root, submit);
        } else {
            form.appendChild(root);
        }

        var challengeWrap = qs('.gereq-sec__challenge-wrap', root);
        var challengeEl = qs('.gereq-sec__challenge', root);
        var answerInput = qs('input[name="captcha_answer"]', root);
        var tokenInput = qs('input[name="captcha_token"]', root);
        var visitorInput = qs('input[name="gereq_visitor"]', root);
        var passInput = qs('input[name="gereq_pass_token"]', root);
        var refreshBtn = qs('.gereq-sec__refresh', root);
        var badge = qs('.gereq-sec__badge span', root);
        var errorEl = qs('.gereq-sec__error', root);
        var hpInput = qs('input[name="website_url"]', hpWrap);
        var loading = false;
        var captchaVisible = false;
        var assessing = false;
        var assessed = false;
        var blocked = false;

        function syncVisitor() {
            visitorInput.value = visitorId();
        }

        function showError(msg) {
            if (!msg) {
                errorEl.hidden = true;
                errorEl.textContent = '';
                return;
            }
            errorEl.hidden = false;
            errorEl.textContent = msg;
        }

        function setLoading(on) {
            loading = on;
            refreshBtn.disabled = on;
            answerInput.disabled = on || !tokenInput.value;
        }

        function renderChallenge(image) {
            challengeEl.innerHTML = '';
            if (!image) {
                var dots = document.createElement('div');
                dots.className = 'gereq-sec__dots';
                dots.innerHTML = '<span>·</span><span>·</span><span>·</span><span>·</span><span>·</span>';
                challengeEl.appendChild(dots);
                return;
            }
            var img = document.createElement('img');
            img.className = 'gereq-sec__image';
            img.src = image;
            img.alt = 'Kontrolní kód';
            img.width = 168;
            img.height = 52;
            img.draggable = false;
            challengeEl.appendChild(img);
        }

        function loadCaptcha() {
            setLoading(true);
            answerInput.value = '';
            passInput.value = '';
            showError('');
            syncVisitor();
            var url = apiOrigin + '/security/api/challenge?key=' + encodeURIComponent(key) +
                '&origin=' + encodeURIComponent(pageOrigin);

            return fetch(url, {
                cache: 'no-store',
                headers: { Accept: 'application/json', Origin: pageOrigin },
                credentials: 'omit',
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('challenge failed');
                }
                return res.json();
            }).then(function (data) {
                if (!data.token || !data.image) {
                    throw new Error('incomplete');
                }
                tokenInput.value = data.token;
                renderChallenge(data.image);
                setBadgeLabel(badge, data.badge);
                setLoading(false);
                answerInput.disabled = false;
                answerInput.required = true;
            }).catch(function () {
                tokenInput.value = '';
                renderChallenge('');
                setLoading(false);
                showError('Nepodařilo se načíst kontrolu. Obnovte stránku.');
            });
        }

        function showCaptcha() {
            if (captchaVisible || blocked) {
                return Promise.resolve();
            }
            captchaVisible = true;
            challengeWrap.hidden = false;
            return loadCaptcha();
        }

        function softBlock() {
            blocked = true;
            Array.prototype.forEach.call(form.elements, function (el) {
                try {
                    el.disabled = true;
                } catch (e) {}
            });
            showError('Odeslání je dočasně zablokováno.');
        }

        function applyAssess(data) {
            assessed = true;
            if (data.visitor) {
                setVisitor(data.visitor);
                syncVisitor();
            }
            setBadgeLabel(badge, data.badge);
            if (data.action === 'block') {
                softBlock();
                return Promise.resolve();
            }
            if (data.action === 'challenge') {
                return showCaptcha();
            }
            if (data.pass_token) {
                passInput.value = data.pass_token;
            }
            answerInput.required = false;
            return Promise.resolve();
        }

        function assess(force) {
            if (assessing || blocked) {
                return Promise.resolve();
            }
            if (assessed && !force && !captchaVisible) {
                return Promise.resolve();
            }
            assessing = true;
            syncVisitor();
            var honeypotTouched = !!(hpInput && hpInput.value);

            // key in query so OPTIONS preflight can resolve the site (no body).
            return fetch(apiOrigin + '/security/api/assess?key=' + encodeURIComponent(key), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Origin: pageOrigin,
                },
                credentials: 'omit',
                body: JSON.stringify({
                    key: key,
                    origin: pageOrigin,
                    path: window.location.pathname + window.location.search,
                    signals: collectSignals(honeypotTouched),
                }),
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('assess failed');
                }
                return res.json();
            }).then(function (data) {
                assessing = false;
                return applyAssess(data || {});
            }).catch(function () {
                assessing = false;
                // Fail closed for UX: show captcha rather than silent bypass.
                return showCaptcha();
            });
        }

        refreshBtn.addEventListener('click', function () {
            if (!loading) {
                loadCaptcha();
            }
        });

        if (hpInput) {
            hpInput.addEventListener('input', function () {
                if (hpInput.value) {
                    showCaptcha();
                }
            });
        }

        if (!form.hasAttribute('data-gereq-managed')) {
            form.addEventListener('submit', function (event) {
                syncVisitor();
                if (blocked) {
                    event.preventDefault();
                    return;
                }
                if (captchaVisible) {
                    return;
                }
                if (passInput.value && visitorInput.value) {
                    return;
                }
                event.preventDefault();
                assess(true).then(function () {
                    if (blocked) {
                        return;
                    }
                    if (captchaVisible) {
                        showError('Doplňte kontrolní kód a odešlete znovu.');
                        return;
                    }
                    if (passInput.value) {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            HTMLFormElement.prototype.submit.call(form);
                        }
                    }
                });
            });
        }

        var api = {
            showCaptcha: showCaptcha,
            assess: assess,
            form: form,
        };
        form.__gereqSecurity = api;
        forms.push(api);

        setTimeout(function () {
            assess(false);
        }, alwaysCaptcha ? 0 : 1600);

        if (alwaysCaptcha) {
            showCaptcha();
        }

        return api;
    }

    function mountAll() {
        targetForms().forEach(mountForm);
    }

    function forceChallenge() {
        if (!targetForms().length) {
            qsa('form').forEach(function (form) {
                if (!form.hasAttribute('data-gereq-security-skip')) {
                    form.setAttribute('data-gereq-security', '');
                }
            });
        }
        mountAll();
        forms.forEach(function (api) {
            if (api && typeof api.showCaptcha === 'function') {
                api.showCaptcha();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountAll);
    } else {
        mountAll();
    }

    window.addEventListener('gereq-security:challenge', forceChallenge);

    window['GereqSecurity'] = {
        remount: mountAll,
        setVisitor: setVisitor,
        challenge: forceChallenge,
    };

    window.dispatchEvent(new Event('gereq-security:ready'));
})();
