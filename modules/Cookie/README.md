# Cookie

Standalone consent management extracted from Web+. Module id/capability: `cookie`, version `0.2.0`. No dependency on Web+, CMS or Analytics; disabling Cookie does not disable those modules.

## Administration

`/admin/cookie` requires `cookie.manage`. Configure window text/buttons, active categories, consent lifetime/storage key, privacy link, accent/background/text colours, floating reopen button and bottom-left/right position. The necessary category is always required. Replay is offered only when statistics are enabled.

Settings are registered with the `cookie.settings` publishing adapter. Save writes a draft; public configuration reads production until publication. The embedded `/admin/cookie/preview` uses the draft. Its choices are held in memory and never change real consent or dispatch analytics consent events.

## Frontend

Proxy `/api/public/*` to the platform on the frontend's own origin, then include:

```html
<script defer data-gereq-cookie src="/api/public/cookie/widget.js?v=0.2.0"></script>
```

The `storefront.spa_html` hook injects this script in platform storefront shells. Separate Vue/static frontends can include it themselves. `front-vue/plugins/gereqScripts.ts` injects it through Vite in both the generic template and GEREQ frontend. The shared `CookieSettings.vue` adds a footer reopen link and is imported directly by both storefronts. The standalone widget uses a Shadow DOM so it can float over any frontend without depending on its Vue/CSS bundle.

- `GET /api/public/cookie/config`: production configuration, `no-store`.
- `GET /api/public/cookie/widget.js`: standalone runtime.
- `GET /api/public/webplus/cookies`: legacy public alias owned by Cookie, with the previous API data envelope. Web+ no longer registers cookie routes/settings/UI.
- `window.gereqCookie.open()` / `.close()` / `.allows(category)` and the `gereq:cookie-open`, `gereq:cookie-ready`, `gereq:consent-changed` document events integrate external controls/trackers.

Consent uses the existing `cookie-consent` localStorage key by default, flat boolean categories, a `v`, configuration `version` and a timestamp. Old choices cause the new panel to reopen; replay requires an explicit `replay: true` choice. DNT/GPC prevent statistics, replay and marketing permission. Choices synchronize across tabs. Storage failure leaves consent unchanged and shows an error. Configured text is rendered using `textContent`, not HTML.

The module manages consent; enabling a category does not install third-party advertising or preference scripts. Integrations must consult `.allows()` and respond to consent changes. Analytics adopts the storage key through the capability, checks statistics and replay separately, and keeps its own server-side cookie/payload assertion checks. The recorder stops when replay is withdrawn. Analytic data deletion still follows Analytics' configured revocation policy.

## Upgrade from Web+

The data migration copies stored `webplus.cookies_*` values to `cookie.*` through the core Settings service only when a target key is absent. It leaves original values intact; repeated imports cannot overwrite Cookie settings. No schema changes are introduced. Rollback retains both old and copied settings to avoid losing administrator configuration.

Web+ is now `2.0.0`: cookie administration/provider methods/private cookie API were removed; public legacy compatibility belongs to Cookie. Its legal CMS variable can consume the Cookie capability for the policy path.

## Verification

Run `php bin/gereq-test module:cookie`, `module:analytics`, `module:webplus` and the module manifest consistency checks. The frontend requires its normal Bun build/check. No admin JS/TS assets are needed by Cookie; its public widget is served directly from `Resources/public/widget.js`.

## Appearance (0.2.0)

Choose rounded card, compact, square or bottom banner. A custom text symbol/emoji appears in both the panel and reopen button; leaving it empty hides the symbol. An uploaded PNG/JPG/WebP/GIF takes priority. Uploads are limited to 2 MB and 4096 × 4096 pixels; SVG and disguised non-images are rejected. Files use immutable random names under the core uploads filesystem's `cookie/` directory; the public image endpoint only serves this directory and allowed raster extensions. Replacing/removing an image stages its reference without deleting files still used by production or snapshots.

`show_on_mobile=false` suppresses the automatic panel and floating reopen button at viewport widths up to 767 px, including after resizing. An explicit footer/API open still works so mobile visitors can manage consent. This setting does not grant any optional consent. Draft settings and uploaded artwork can be reviewed in the existing isolated preview before publication.
