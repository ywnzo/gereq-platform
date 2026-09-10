# Loyalty (`loyalty`)

Loyalty implements a simple customer loyalty program: administrators define **tiers** (code, name, minimum lifetime spend, discount percentage) and the platform keeps one **loyalty account** per customer with a points balance and lifetime spend. Points are earned 1 point per currency unit of order total through `LoyaltyProvider::applyEarn()` (exposed as the `loyalty` capability) and can be manually adjusted by admins or through the REST API. Used by shop administrators; account data references [Customers](customers.md) by id only.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `loyalty` |
| Version | `1.1.0` (`modules/Loyalty/module.php`, `LoyaltyModule::version()`; `README.md` still says v1.0.3) |
| Directory | `modules/Loyalty/` |
| Class | `Modules\Loyalty\LoyaltyModule` |
| Requires | `core ^1.0` |
| Optional | none declared (Customer names are resolved via guarded `class_exists(Modules\Customers\Domain\Customer::class)`) |
| Capabilities provided | `loyalty` → `Application/LoyaltyProvider` instance |
| Capabilities consumed | none |
| Groups / packages | none in `module.php`; not listed in `config/module_groups.php` |
| Permissions | `loyalty.view` (`perm.loyalty.view`), `loyalty.manage` (`perm.loyalty.manage`) – group `perm.group.loyalty` |

## Domain model

| Entity | Table | Key fields | Relations |
| --- | --- | --- | --- |
| `Domain/LoyaltyTier.php` | `loyalty_tiers` | `id: string(36)` UUID v7 · `code: string(64)` unique (`uniq_loyalty_tiers_code`, upper‑cased/trimmed in constructor and `setCode`) · `name: string(190)` · `min_spend: decimal(14,2)` default `0.00` · `discount_percent: decimal(8,2)` default `0.00` · `sort_order: int` default 0 · `is_active: bool` default true (property `active`) · `created_at` | none |
| `Domain/LoyaltyAccount.php` | `loyalty_accounts` | `id: string(36)` UUID v7 · `customer_id: string(36)` unique (`uniq_loyalty_accounts_customer`) · `tier_id: ?string(36)` · `points: int` (never below 0 – `setPoints`/`adjustPoints` clamp with `max(0, …)`) · `lifetime_spend: decimal(14,2)` · `created_at`, `updated_at` (touched by every mutator) | `customer_id` → [Customers](customers.md) `Customer.id`; `tier_id` → `LoyaltyTier.id` (soft reference, no FK) |

`LoyaltyTier` carries form metadata: `#[FormEntity('loyalty.tier.save')]`, `#[FormGenerator(exclude: ['id','createdAt'], form: 'loyalty.tier.save', cols: 1)]` and `#[FormField]` on each editable property (labels `loyalty.field.*`, `active` rendered as `switch`). See `docs/FORMS.md`.

Statuses/enums: none (tier activity is a boolean).

## Admin UI

Menu (`boot()`):

| Id | Label key | Icon | Route | Permission | Order | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `loyalty` | `nav.loyalty` | `gift` | `admin.loyalty.tiers.index` | `loyalty.view` | 37 | group `Obchod` |
| `loyalty.accounts` | `nav.loyalty.accounts` | – | `admin.loyalty.accounts.index` | `loyalty.view` | 38 | parent `loyalty` |
| `loyalty.tiers.create` | `nav.loyalty.tiers.create` | – | `admin.loyalty.tiers.create` | `loyalty.manage` | 39 | parent `loyalty`, hidden |

Command palette: `loyalty.tiers` (`palette.loyalty.tiers`, url `/admin/loyalty/tiers`, icon `gift`, permission `loyalty.view`, keywords `věrnost, loyalty, tier, body, points`).

Routes (all in the `auth` middleware group, `register()`):

| Method | Path | Route name | Controller::action | Permission |
| --- | --- | --- | --- | --- |
| GET | `/admin/loyalty/tiers` | `admin.loyalty.tiers.index` | `Http/TierController::index` | `loyalty.view` |
| GET | `/admin/loyalty/tiers/create` | `admin.loyalty.tiers.create` | `TierController::create` | `loyalty.manage` |
| POST | `/admin/loyalty/tiers` | `admin.loyalty.tiers.store` | `TierController::store` | `loyalty.manage` |
| GET | `/admin/loyalty/tiers/{id}/edit` | `admin.loyalty.tiers.edit` | `TierController::edit` | `loyalty.manage` |
| POST | `/admin/loyalty/tiers/{id}` | `admin.loyalty.tiers.update` | `TierController::update` | `loyalty.manage` |
| POST | `/admin/loyalty/tiers/{id}/delete` | `admin.loyalty.tiers.destroy` | `TierController::destroy` | `loyalty.manage` |
| GET | `/admin/loyalty/accounts` | `admin.loyalty.accounts.index` | `Http/AccountController::index` | `loyalty.view` |
| POST | `/admin/loyalty/accounts/{id}/adjust` | `admin.loyalty.accounts.adjust` | `AccountController::adjust` | `loyalty.manage` |

Screens:

- **Tiers list** (`@loyalty/tiers/index.html.twig`): ordered by `sortOrder`, `minSpend`; `?q=` search over `code`/`name` via `self_repair_search()` helper (`src/Support/helpers.php`).
- **Tier form** (`@loyalty/tiers/form.html.twig`): create/edit; validated by ruleset `loyalty.tier.save`; duplicate code → 422 `loyalty.error.code_taken`.
- **Accounts list** (`@loyalty/accounts/index.html.twig`): `ListQuery` pagination (100/page), sortable `updatedAt|points|lifetimeSpend`, search over `customerId`/`id`; inline "Adjust" form posting `delta` (non‑zero int) to `adjust`.

Settings pages / setting keys: none.

## Public / REST API

Registered through `$context->api()->module('loyalty', …)` (prefix `api.prefix`, default `/api/v1`), scopes `loyalty.read` and `loyalty.write` registered as known scopes. Controller `Http/Api/LoyaltyApiController.php`.

| Method | Path | Scope | Action | Description |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/loyalty/tiers` | `loyalty.read` | `tiers` | Active tiers ordered by `sortOrder`, `minSpend` (`ApiResponse::collection`) |
| GET | `/api/v1/loyalty/accounts/{customerId}` | `loyalty.read` | `account` | Returns (and **creates if missing**) the account for a customer |
| POST | `/api/v1/loyalty/accounts/adjust` | `loyalty.write` | `adjust` | Body (form or JSON) validated by `loyalty.account.adjust`: `customer_id` or `account_id`, `delta` (non‑zero), `note`; emits `loyalty.points_adjusted`; response includes `delta`, `points_before` |

Serializers:

- Tier: `id, code, name, min_spend, discount_percent, sort_order, active, created_at`.
- Account: `id, customer_id, tier_id, tier (serialized tier or null), points, lifetime_spend, created_at, updated_at`.

Filters: none. `/api/public/*`: none. See `../architecture/api.md`.

## Events, hooks & webhooks

Platform events (`registerPlatformEvents()`, all `webhookEnabled(true)`, source module `loyalty`, Czech descriptions):

| Event | Emitted from | Payload |
| --- | --- | --- |
| `loyalty.tier_created` | `TierController::store` | serialized tier |
| `loyalty.tier_updated` | `TierController::update` | serialized tier |
| `loyalty.tier_deleted` | `TierController::destroy` | serialized tier (pre‑delete) |
| `loyalty.points_adjusted` | `AccountController::adjust`, `LoyaltyApiController::adjust` | admin: `{id, customer_id, points, delta}`; API: serialized account + `delta`, `points_before` |

Subject for all emits is `['type' => 'loyalty_tier'|'loyalty_account', 'id' => …]`. Audit log entries: `loyalty.tier.created|updated|deleted`, `loyalty.points.adjusted` (`Gereq\Audit\Auditor`).

Hooks, queue jobs, scheduled tasks, publishables: none. Validation rulesets: `loyalty.tier.save`, `loyalty.account.adjust`; form binding `forms()->entity('loyalty.tier.save', LoyaltyTier::class)`.

## Dashboard widgets

| Widget id | Type | Class | Shows | Permission | Order |
| --- | --- | --- | --- | --- | --- |
| `loyalty.shortcut` | shortcut | `Application/Widgets/LoyaltyShortcutWidget.php` | Link to tiers index, hint `admin.dashboard.hint.loyalty` | `loyalty.view` | 37 |
| `loyalty.stats` | metric | `Application/Widgets/LoyaltyStatsWidget.php` | Member count; hint with `SUM(points)` and accounts created this month; links to accounts | `loyalty.view` | 52 |
| `loyalty.top_members` | panel (`list_panel.html.twig`) | `Application/Widgets/LoyaltyTopMembersWidget.php` | Top 8 accounts by `points DESC, lifetimeSpend DESC` with customer name (via Customers, guarded), tier badge, lifetime spend | `loyalty.view` | 28 |

See `../architecture/dashboard-widgets.md`.

## Services & extension points

| Class | Responsibility |
| --- | --- |
| `Application/LoyaltyProvider.php` | `accountFor(customerId)` – find or create account (assigns tier matching spend 0); `tierForSpend(amount)` – highest active tier whose `min_spend ≤ amount` (ordered `minSpend DESC, sortOrder DESC`); `applyEarn(customerId, orderTotal)` – adds `floor(total)` points, adds lifetime spend, re‑evaluates tier, flushes; `findTier()`, `findAccount()`. |

Extension points: capability `loyalty` (→ `LoyaltyProvider`) is the intended hook for order modules to call `applyEarn()`. No module in `modules/` currently calls `applyEarn()` (grep). No interfaces are defined.

Integration: `LoyaltyTopMembersWidget` reads `Modules\Customers\Domain\Customer` behind `class_exists` + try/catch.

## CLI commands

None.

## Configuration & translations

Settings: none defined or read. Env vars: none.

Translations: `Resources/translations/messages.{cs,en}.php` – prefixes `nav.loyalty*`, `palette.loyalty.*`, `perm.loyalty.*`, `perm.group.loyalty`, `loyalty.tiers.*`, `loyalty.accounts.*`, `loyalty.field.*`, `loyalty.action.*`, `loyalty.error.*`, `loyalty.flash.*`, `loyalty.widget.*`, `module.loyalty.description`. The widget hint key `admin.dashboard.hint.loyalty` is expected in core translations.

## Database migrations

| File | Purpose |
| --- | --- |
| `Database/Migrations/2026_01_01_000001_loyalty.php` | Creates `loyalty_tiers` (unique `code`, index `idx_loyalty_tiers_active_spend` on `is_active, min_spend`) and `loyalty_accounts` (unique `customer_id`, index `idx_loyalty_accounts_tier`). `down()` drops both. |

## Notes

- `discount_percent` is stored but never applied anywhere in this module; consumers (e.g. [Orders](orders.md)/[Shop](shop.md)) would have to read the tier themselves.
- Points accrual (`applyEarn`) has no caller in the codebase; the API `adjust` and admin adjust are the only ways balances change today.
- `GET /accounts/{customerId}` has a side effect (creates the account) despite being a read‑scoped GET.
- Deleting a tier does not clear `tier_id` on accounts that reference it; `serializeAccount()` tolerates the dangling id (returns `tier: null`).
- Event descriptions passed to `EventDefinition::description()` are Czech strings, not translation keys.
- `README.md` version (1.0.3) is out of sync with `module.php` (1.1.0).
