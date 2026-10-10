---
name: gereq-admin-ui
description: Úpravy administrace GEREQ v Twig, TypeScriptu a Alpine CSP s formulářovým generátorem, sdíleným CSS kernelem a Bun assets.
---

# Administrace GEREQ

Přečti kořenový `AGENTS.md`, `docs/architecture/admin-ui.md` a `.cursor/rules/admin-css-grq.mdc`. Pro formuláře načti `docs/FORMS.md`; API dialogů ověř v `resources/js/ui/confirm.ts`.

- Vycházej z admin/layout.html.twig a existujících bloků; module templates používají svůj Twig namespace.
- Používej resources/views/ui komponenty, ui_render a icon. Formuláře stav na FormField, FormGenerator, pojmenovaných validačních rulesetech a forms().render podle aktuálního API.
- Zachovej page-specific layout. Detail-stack nepatří automaticky do formulářů s vlastním gridem (Customers, Fleet, Listing hub).
- Mutující formuláře mají CSRF field, session AJAX CSRF header. Backend permissions a validace fungují i při obejití UI.
- Nové texty patří do cs/en katalogů a používají trans; placeholders mají %name%. Je-li relevantní, aktualizuj editor index přes `php tools/gereq-i18n/index-translations.php`.
- Zachovej autoescape; raw je pro důvěryhodné generované nebo sanitizované HTML.
- Alpine používá CSP build. Interakce registruj v TS podle resources/js/ui/index.ts; neobcházej CSP inline skripty.
- Dialogy awaituj přes window.gereq.confirm/prompt, formuláře používají data-confirm, výsledky toast. Nové native dialogs ani jejich fallbacky nepřidávej.
- Sdílené primitives patří do resources/css/kernel, nové mají grq prefix. Využívej grq tokens a layout utilities.
- Module CSS v Resources/assets/css/admin.css obsahuje namespaced domain styly. Nepřepisuj globální btn/input/panel/table. Module CSS neimportuj natvrdo do resources/js/admin.ts; build a RenderEngine ho načítají podle aktivace modulu.
- V Twig assets načítej render_css/render_js, generovaný public/build neupravuj ručně.

Ověř `bunx tsc --noEmit` a `bun run build`. Kořenový tsconfig nezahrnuje všechny module TS: ověř jejich zapojení a vlastní kontroly. Je-li dostupné UI, zkontroluj layout, malé viewporty, submit, validation errors a potvrzení/zrušení dialogů. Uveď chybějící vizuální ověření. Module změny vyžadují version bump.
