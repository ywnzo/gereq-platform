# Admin UI

The admin is server-rendered Twig inside one shell (`resources/views/admin/layout.html.twig`) with a sidebar menu, a topbar that modules can extend through filters, a command palette that doubles as global search, and a small set of Alpine.js (CSP build) components compiled by Vite from `resources/js/admin.ts`. Reusable markup lives in the `Ui` service and `resources/views/ui/*`; forms are generated from `#[FormField]` attributes (documented separately in `docs/FORMS.md`). This page maps those pieces to their source files.

## Shell and layout

`resources/views/admin/layout.html.twig` — every admin page `{% extends %}` it and fills `block title`, `block breadcrumb` (defaults to `admin_breadcrumbs(pageTitle)`), `block content`. The layout:

- includes `partials/brand-head.html.twig` and `partials/brand-overrides.html.twig` (brand CSS variables, favicon — see [Brand](publishing-i18n-settings.md#brand));
- renders the sidebar from `admin/partials/sidebar.html.twig` + `sidebar_nav_node.html.twig` using `Menu::tree()`;
- exposes three topbar extension points via hooks: `apply_filters('admin.topbar.center', '')`, `admin.topbar.context`, `admin.topbar.actions` — modules append HTML with `$context->hooks()->addFilter(...)`;
- mounts the command palette (Alpine data from `window.__GEREQ_COMMANDS__`), the global drawer (`ui/drawer.html.twig`), the confirm dialog (`ui/confirm_dialog.html.twig`) and the image viewer (`ui/image_viewer.html.twig`);
- loads CSS/JS through `render_css()` / `render_js()` (`Gereq\View\RenderEngine`, `config/render.php`).

Controllers typically call `$this->render('admin/users/index.html.twig', [...])` or the module's namespaced template `@viewings/index.html.twig` (`Gereq\Http\Controller::render()`).

### Topbar publish badge

`CoreRegistrar::topbarPublishBadge()` (`src/Foundation/CoreRegistrar.php`) adds an `admin.topbar.center` filter that renders `admin/partials/publish_badge.html.twig` with the pending staging count (`StagingStore::count()`) and links to `admin.publish.pending` / `admin.publish.run`. The badge is live-updated by the Alpine `registerPublishBadge` component. See [publishing](publishing-i18n-settings.md#publishables-staging--production).

### Menu

`Gereq\Admin\Menu\Menu` (`src/Admin/Menu/Menu.php`): `add(MenuItem)`, `tree(user, router, request)` (permission-filtered, grouped, active-state resolved), `breadcrumbsFor()`, `all()`.

`MenuItem` (`src/Admin/Menu/MenuItem.php`) constructor arguments:

| Argument | Meaning |
| --- | --- |
| `id` | unique key; children reference it via `parent` |
| `label` | translation key (`nav.viewings`) |
| `icon` | key from `Icons` (top-level items) |
| `route` / `url` | target (route name preferred) |
| `permission` | hide when `!$user->can()` |
| `parent` | nest under another item |
| `order` | sort within group (default 50) |
| `badge` | optional badge text |
| `divider` | draw a separator |
| `hidden` | not shown in the sidebar but used for breadcrumbs/active state (e.g. create/edit pages) |
| `group` | sidebar section heading (`Hlavní`, `Reality`, `Obchod`, `Systém`, … — labels come from `resources/translations`) |

### Command palette and admin search

- `Gereq\Admin\CommandPalette\CommandPalette` (`src/Admin/CommandPalette/CommandPalette.php`): `add(PaletteCommand)`, `all(user)` (permission-filtered). `PaletteCommand(id, label, url, icon, permission, keywords[], group = 'Navigace')`.
- `Gereq\Admin\Search\AdminSearchRegistry` (`src/Admin/Search/AdminSearchRegistry.php`): `add(id, fields, callable(string $q, int $limit): AdminSearchHit[], permission, group = 'Data', icon, limit = 8)` or `source(AdminSearchSource)`; `sources(user)`, `search(query, user, maxTotal = 24)`. Hits are `AdminSearchHit(id, label, url, group, subtitle, icon, keywords)`.
- The palette UI (`layout.html.twig`, class `palette`) filters commands client-side and, for longer queries, calls `GET /admin/search?q=` (`src/Admin/Http/SearchController.php`, route `admin.search`) which runs the registry; hits appear with `kind: 'hit'`.
- Core registers a `users` search source in `CoreRegistrar::adminSearch()` using `self_repair_search()` for typo tolerance; modules add their own (customers, listings, …) in `boot()`.

## UI component system

`Gereq\Admin\Ui\Ui` (`src/Admin/Ui/Ui.php`), reachable as `ui()` in PHP and Twig.

### Living catalogues

| URL | Route | Purpose |
| --- | --- | --- |
| `/design/ui.html` | `design.ui` | **API catalogue** — every standardized `ui()` helper with live preview + PHP sample |
| `/design.html` | `design` | Visual styleguide (brand, type, buttons, fields, tables chrome, …) |

Prefer `/design/ui.html` when looking up how to call a helper; use `/design.html` for look-and-feel.

### Methods

| Method | Renders |
| --- | --- |
| `component(name, data)` | `resources/views/ui/components/<name>.html.twig` (name is sanitised) |
| `partial(template, data)` | any Twig under `resources/views` (e.g. `ui/input`, `ui/breadcrumbs`) |
| `container(colsOrClass, body, options)` | int 1–4 → form grid; string → wrapper `class` |
| `grid(cols, body, options)` | `components/grid.html.twig` |
| `cell(body, span, class)` | `components/cell.html.twig` — `span` is `1`–`3` or `'full'` |
| `drawer(...)`, `drawerTrigger(...)` | side drawer + opener |
| `badge(label, tone = 'muted', class)` | `components/badge.html.twig` |
| `emptyState(title, hint, actionHtml)` | `components/empty.html.twig` |
| `pageHeader(title, options)` | `components/page_header.html.twig` (`meta`, `actions`) |
| `icon(name, class)` | `Icons::svg()` |
| `input` / `select` / `textarea` / `checkbox` / `radio` / `switch` / `file` / `editor` | wrappers over `resources/views/ui/*.html.twig` |
| `column(key, label, options)` | declarative `TableColumn` |
| `table(columns, rows, options)` | `components/data_table.html.twig` |
| `sortTh(...)`, `tableFooter(...)` | shared sortable header / pagination footer |

### Forms (`ui()->input`, …)

Pass a data array. Common keys:

| Key | Role |
| --- | --- |
| `name` | HTML `name` (also drives `old()` / validation errors) |
| `id` | optional; default `f-{name}` |
| `label`, `hint`, `value`, `placeholder` | field chrome |
| `type` | for `input`: `text`, `email`, `search`, `password`, `number`, `color`, … |
| `form` | form id string for `FormState`, or `null` when unbound |
| `required`, `optional`, `disabled`, `readonly` | flags |
| `prefix` / `suffix` | addon affixes on `input` |
| `clearable` | clear button on `input` |
| `options` | for `select` / `radio`: `[['value' => …, 'label' => …], …]` |
| `searchable` | `select` only |
| `size` | `textarea`: `'mini'` = plain textarea; default = rich `editor` |
| `compact` | `file` only |

Example:

```php
echo ui()->input([
    'name' => 'email',
    'id' => 'customer-email', // optional
    'type' => 'email',
    'label' => __('common.email'),
    'value' => $user->email,
    'form' => 'customers.save',
]);
```

### Tables (`ui()->column` + `ui()->table`)

Column options:

| Option | Meaning |
| --- | --- |
| `sortable` | render `sort_th` header |
| `cell` | `text` (default) \| `badge` \| `html` \| `user` \| `mute` \| `num` |
| `render` | `callable(mixed $row): string` — full cell HTML |
| `tone` | `callable(mixed $row): string` — badge tone |
| `meta` | e.g. user cell: `meta_key`, `initials_key`, `href_key` |
| `class`, `numeric` | `<th>` / `<td>` classes |

Table options (selection):

| Option | Meaning |
| --- | --- |
| `list_query` | `ListQuery` — sort, search, pagination params |
| `sort` / `dir` / `search` / `query` | when not using `ListQuery` |
| `checkable` + `row_id` | Alpine `dataTable` checkboxes |
| `row_search` | client-side filter blob per row |
| `actions` | `callable($row): list of {href, label, icon, variant?}` |
| `footer`, `total`, `page`, `pages`, `per_page` | footer / pager |
| `empty_title`, `empty_hint` | empty body |
| `hash` | append `#…` on sort/pager links (keep tab) |

Example:

```php
echo ui()->table([
    ui()->column('name', __('common.name'), [
        'sortable' => true,
        'cell' => 'user',
        'meta' => ['meta_key' => 'phone', 'initials_key' => 'initials'],
    ]),
    ui()->column('status', __('common.status'), [
        'cell' => 'badge',
        'tone' => fn (array $row): string => $row['tone'],
    ]),
], $rows, [
    'list_query' => $list,
    'checkable' => true,
    'row_id' => fn (array $row): string => (string) $row['id'],
    'actions' => fn (): array => [
        ['href' => '#', 'label' => __('common.edit'), 'icon' => 'edit'],
    ],
    'footer' => true,
]);
```

Row action icons map to variants in `ui/row_action.html.twig` (`eye` → primary soft, `edit` → success, `trash` → danger). Prefer surface + coloured icon styles (no solid `color-mix` fills — see build color-mix repair).

### Partials via `ui()->partial`

Anything under `resources/views` without a dedicated method, e.g.:

- `ui/breadcrumbs`, `ui/media_box`, `ui/table_thumb`, `ui/table_toggle`
- `ui/row_action`, `ui/error-summary`, `ui/form_panel` (often `{% embed %}` for blocks)
- ARES: `ui/ares_subject_suggest`, `ui/ares_address_suggest`
- Hosts: `ui/confirm_dialog`, `ui/image_viewer`

### Twig

- `ui()` — the service (`{{ ui().badge('OK', 'ok') }}`)
- `ui_render('badge', {label: 'OK', tone: 'ok'})` — component only, HTML-safe
- Macros in `resources/views/ui/macros.html.twig`: `badge`, `drawer_trigger`, `empty`, `grid`, `cell`

### Related JS / CSS patterns (not `ui()` methods)

Documented live on `/design/ui.html` under *Chrome* / *Feedback*:

- Buttons: `.btn`, `.btn--primary|soft|danger|…`, sizes `.btn--xs|sm|lg`
- Alerts: `.alert`, `.alert--success|warn|danger`
- Toasts: `window.gereq.toast(message, 'success'|'error'|'info')` (+ `#toasts` host)
- Confirms: `window.gereq.confirm({…})` / `data-confirm` — **never** native `alert`/`confirm`/`prompt`
- Status chips: `.status-chip.status-chip--tone-*`
- Bulk bar: `.bulk-bar` inside `list-shell` + Alpine `dataTable`
- Entity forms: `forms()->render($entity)` (`Gereq\Admin\Form\Forms`)

Other shared files stay under `resources/views/ui/` as listed above.

## Twig extensions

Registered in `Gereq\View\TwigFactory::create()` (`src/View/TwigFactory.php`), implemented in `Gereq\View\TwigRuntime` (`src/View/TwigRuntime.php`):

| Function | Purpose |
| --- | --- |
| `url(name, params)`, `route_exists(name)`, `route_is(name)` | router helpers |
| `asset(entry)`, `render_css()`, `render_js()`, `render_tags()`, `render_reload()` | Bun manifest URLs (`RenderEngine`, `config/render.php`) |
| `csrf_field()`, `csrf_token()`, `csp_nonce()` | security |
| `icon(name, class)` | inline SVG |
| `current_user()` | `User` or null |
| `admin_breadcrumbs(pageTitle)` | from `Menu::breadcrumbsFor()` |
| `flash(key)` | session flash |
| `old(key, default, form)`, `error(key, form)`, `errors(form)`, `has_error(key, form)`, `form_errors(form)` | form state after validation redirect (`Gereq\Http\FormState`) |
| `field_required(name, form, required, requiredFields)` | required marker derived from rulesets |
| `do_action(hook, ...)`, `apply_filters(hook, value, ...)` | hook bridge |
| `trans(id, params, domain, locale)`, `__()` | translator |
| `audit_action(action)`, `audit_action_tone(action)` | audit log labels/tones |
| `app_locale()`, `app_env()`, `app_version()`, `available_locales()` | app info |
| `brand(key)`, `brand_css()`, `brand_html_style()` | branding |
| `ui()`, `ui_render(name, data)`, `forms()` | component system / form generator |

Filters: `trans`, `audit_action`, `audit_action_tone`.

Globals and loader: `resources/views` root plus a namespace per module/integration folder (lower-cased directory name → `@viewings`), compiled cache in `storage/cache/twig` when `cache.twig` is on and `app.debug` off.

## Alpine components

`resources/js/admin.ts` imports `@alpinejs/csp`, `resources/css/kernel.css`, per-module admin CSS, calls `registerUi(Alpine)` from `resources/js/ui/index.ts`, then `Alpine.start()`. Because the CSP build is used, components are registered with `Alpine.data(...)` and templates never use inline expressions that need `eval`.

`registerUi()` calls, in order (core ones from `resources/js/ui/*.ts`; module ones imported from `modules/*/Resources/assets/js/*.ts`):

| Function | File | Purpose |
| --- | --- | --- |
| `registerPageProgress` | `pageProgress.ts` | top progress bar on navigation |
| `registerToolbarAutosubmit` | `toolbarAutosubmit.ts` | auto-submit list filter toolbars on change |
| `registerSelect` | `select.ts` | enhanced select (search, keyboard) |
| `registerModal`, `registerPopover` | `overlay.ts` | modal and popover overlays |
| `registerConfirm` | `confirm.ts` | confirm/prompt dialog + `window.gereq.confirm/prompt` + `data-confirm` forms |
| `registerImageViewer` | `imageViewer.ts` | lightbox |
| `registerToast` | `toast.ts` | toast notifications (`window.gereq.toast`) |
| `registerDrawer` | `drawer.ts` | side drawer with lazy content |
| `registerPassword`, `registerSwitch`, `registerTableToggle`, `registerTextarea` | `controls.ts` | password reveal, switch input, table row expand, autosize textarea |
| `registerForm` | `form.ts` | dirty tracking, submit state, keyboard save |
| `registerEditor` | `editor.ts` | rich-text editor (toolbar `format-*` icons) |
| `registerTabs` | `tabs.ts` | tab groups with URL hash |
| `registerStickyChrome` | `stickyChrome.ts` | sticky topbar/toolbar shadow state |
| `registerDashboardGrid` | `dashboardGrid.ts` | masonry layout for dashboard widgets |
| `registerWebauthn` | `webauthn.ts` | passkey registration/login |
| `registerSettingsBrandPreview`, `registerColorField`, `registerBrandAsset` | `settingsBrandPreview.ts`, `colorField.ts`, `brandAsset.ts` | brand settings live preview, colour input, logo upload |
| `registerFile` | `file.ts` | file input with preview/drag-drop |
| `registerCssEditor` | `cssEditor.ts` | custom CSS editor field |
| `registerDashboardSettings` | `dashboardSettings.ts` | dashboard layout editor (enable/order/width) |
| `registerAres` | `ares.ts` | ARES subject/address suggest |
| `registerModuleTable` | `moduleTable.ts` | modules page bulk selection |
| `registerChart` | `chart.ts` | chart panel widgets |
| `registerPublishBadge` | `publishBadge.ts` | live pending-changes badge |
| `registerApiTester` | `apiTester.ts` | Developer → API request tester |
| module components | `modules/*/Resources/assets/js/*.ts` | BikeService market, SearchEngines, PartsCatalog, CarService finish wizard, ESign signature pad, Calendar board, CRM board/workflow canvas/e-mail editor, Emailing mailbox, Documents entity link, CMS block/page forms + builder, Fleet brand catalog, Customers edit/notes/contact form, Portals form, AI chat/float |

Module JS is bundled into the single admin entry; a module therefore needs a line in `resources/js/ui/index.ts` (and CSS in `resources/js/admin.ts`) — this is one of the places where core knows about modules.

## No native dialogs

Rule `.cursor/skills/no-native-dialogs/SKILL.md`: never call `window.alert`, `window.confirm`, `window.prompt` in admin UI. Use the dialog from `resources/js/ui/confirm.ts`:

```ts
const ok = await window.gereq.confirm({ title: 'Smazat?', body: 'Tuto akci nelze vrátit.', confirmLabel: 'Smazat', danger: true });
const name = await window.gereq.prompt({ title: 'Uložit šablonu', inputLabel: 'Název', inputRequired: true }); // null = cancelled
```

Or declaratively on forms/buttons: `data-confirm="…"`, `data-confirm-title`, `data-confirm-label` (see `resources/views/ui/row_action_destroy.html.twig`). Default labels come from `#gereq-confirm` data attributes (translated in the layout).

Known exception in the current code: `resources/js/admin.ts` defines `window.gereqConfirmModuleDisable` and `window.gereqConfirmModuleVersion` using native `window.confirm` for the modules page.

## Forms

Admin forms are generated from `#[FormField]` attributes on entities (`src/Admin/Form/FormField.php`, `Gereq\Admin\Form\Forms`, Twig `forms()`, `ui/form_generator.html.twig`) and bound to validation rulesets with `$context->forms()->entity('viewings.save', Viewing::class)`. Full reference: `docs/FORMS.md`. Validation errors are redirected back with `old()` / `error()` (`Gereq\Http\FormState`).

## Icons

`Gereq\Admin\Ui\Icons::svg(name, class)` (`src/Admin/Ui/Icons.php`) returns inline SVG for a fixed key set (Twig `icon('name')`, `MenuItem::icon`, manifest `icon`):

`home users manage_accounts user modules settings shield activity heart search plus logout chevron-right chevron-left chevron-down chevron-up trash eye play pause edit pencil download dots dot bell sun moon building mail inbox outbox send reply forward attach emoji draft spam archive copy pin flag external phone check x close minimize maximize filter filters alert panel database key file upload cloud-upload pdf package cart bag truck car bike pedal_bike build credit-card tag undo redo refresh zoom-in zoom-out rotate-left rotate-right star gift layers rss globe warehouse coins image layout layout-right layout-bottom layout-off calendar briefcase zap fuel fuel-electric fuel-hybrid pc-engine pc-filter pc-belt pc-gearbox pc-clutch pc-drive pc-brake pc-steering pc-suspension pc-axle pc-exhaust pc-cooling pc-hvac pc-electric pc-light pc-body pc-glass pc-wiper pc-fuel pc-parts receipt clipboard folder folder-open move book link palette language storefront format-bold format-italic format-underline format-strike format-align-left format-align-center format-align-right format-align-justify format-list-bulleted format-list-numbered format-quote format-clear horizontal-rule`

Unknown names fall back to the `circle` glyph — check `Icons.php` before inventing a key.

## CSS

`resources/css/admin.css` → `resources/css/kernel.css`, which imports brand tokens (`resources/brand/tokens.css`, `font.css`), Tailwind 4 (`@import "tailwindcss"`, plugin `@tailwindcss/vite`) and the kernel layers in `resources/css/kernel/`:

| File | Scope |
| --- | --- |
| `base.css` | resets, typography, CSS variables consumption |
| `buttons.css`, `feedback.css` | buttons, toasts/alerts/badges |
| `layout.css`, `sidebar.css` | shell grid, topbar, sidebar |
| `forms.css`, `forms-legacy.css` | generated forms and older form markup |
| `tables.css`, `panels.css`, `detail.css`, `settings.css` | lists, cards, detail pages, settings |
| `gallery.css`, `image-viewer.css`, `overlays.css`, `palette.css` | media, lightbox, modal/drawer, command palette |
| `dashboard.css` | dashboard masonry grid and widget cells |
| `auth.css`, `finish.css`, `docs.css` | login pages, finish wizards, in-app docs |
| `ai-chat.css`, `ai-chat-frontend.css`, `ai-float.css` | AI assistant UI |

Modules ship their own `Resources/assets/css/admin.css`, imported explicitly in `resources/js/admin.ts`.

## Frontend assets (Bun)

`scripts/build.ts` bundles `resources/js/admin.ts` (Tailwind via `bun-plugin-tailwind`) into `public/build` and writes `manifest.json`. Origins and prefixes live in `config/render.php` (`RENDER_ASSET_ORIGIN`, `RENDER_URL_PREFIX`). Commands: `bun run dev` (watch + `.dev` marker + `render_reload()`), `bun run build` (hashed files). Twig: `render_css()` / `render_js()` / `render_reload()`.

Related: [dashboard widgets](dashboard-widgets.md), [auth & permissions](auth-permissions.md#csrf), `docs/FORMS.md`.
