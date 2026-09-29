# Integrations

Integrations are third-party adapters that live in `integrations/` and plug into a *host capability* provided by a core module — payment gateways into `modules/Payments`, shipping carriers into `modules/Shop`, AI providers into `modules/Ai`. They use the same manifest, lifecycle, settings and translation mechanics as modules, but are declared `kind: integration`, use the PSR-4 root `Integrations\`, must not own core domain tables, and are shown in Admin → Modules on the **Plug-ins** surface grouped by `config/module_groups.php`. This page describes the contract; per-area details are in [payments.md](payments.md), [shipping.md](shipping.md) and [ai-providers.md](ai-providers.md).

## Integration vs module

| Aspect | Module (`modules/`) | Integration (`integrations/`) |
| --- | --- | --- |
| Manifest | `module.php`, `kind` omitted or `module` | `module.php` with `'kind' => 'integration'`; `src/Module/ModuleManifest.php` also forces `integration` for any manifest whose path contains `/integrations/` |
| Namespace | `Modules\{Name}\` | `Integrations\{Name}\` (`composer.json` autoload `"Integrations\\": "integrations/"`; `ModuleManifest::namespace()` picks the prefix from `kind`) |
| Discovery | `src/Module/ModuleManager::discover()` scans both `modules/` and `integrations/`; duplicate ids are rejected | same |
| Admin surface | Admin → Modules → packages (`surface: packages` groups) | Admin → Modules → **Plug-iny** (`ModuleController` filters `isIntegration()` and uses `surface: plugins` groups) |
| Data ownership | may own entities/tables | must not own core domain tables (orders, intents, listings); talks to the host via registries (`integrations/README.md`) |
| Typical contents | Domain, Application, Http, Admin, Resources, Migrations | `{Name}Module.php`, `Application/` (driver), `Http/` (notify/API controllers), `Resources/translations/` |

Reference documentation shipped with the code: `integrations/README.md`.

## Manifest example

`integrations/Comgate/module.php`:

```php
return [
    'id' => 'comgate',
    'name' => 'Comgate',
    'icon' => 'credit-card',
    'version' => '1.0.0',
    'description' => 'module.comgate.description',
    'class' => Integrations\Comgate\ComgateModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => ['core' => '^1.0', 'payments' => '^1.1'],
    'optional' => ['webhooks' => '^1.0'],
    'groups' => ['plugin.payments'],
    'provides' => ['comgate'],
    'capabilities' => ['payment.gateway.comgate'],
    'permissions' => [],
];
```

`requires` names the host module (`payments`, `shop`, `ai`); `optional` declares soft dependencies (e.g. `webhooks`); `capabilities` lists the capability keys the module registers in `src/Module/CapabilityRegistry.php`.

## Plugin groups (`config/module_groups.php`)

| Group id | Name | Surface | Order | Members |
| --- | --- | --- | --- | --- |
| `plugin.payments` | Platby | `plugins` | 10 | `comgate`, `stripe`, `gopay` |
| `plugin.shipping` | Doprava | `plugins` | 20 | `packeta` |
| `plugin.ai` | AI | `plugins` | 30 | `ollama`, `openai_compat`, `zai`, `deepseek`, `gemini`, `anthropic`, `openai` |

Labels/descriptions are translation keys (`admin.plugins.group.<x>`, `admin.plugins.group.<x>.desc`). The `gereq`, `ecs`, `ebs`, `realestates` and `forum` groups in the same file are package groups for regular modules (`surface: packages`). Admin → Modules offers "enable group" (`POST /admin/modules/groups/{group}/enable`, `ModuleController::enableGroup`).

## Lifecycle

```mermaid
sequenceDiagram
    participant A as Admin UI / CLI
    participant MM as ModuleManager
    participant M as Migrator
    participant I as Integrations\X\XModule
    participant H as Host registry
    A->>MM: enable(id) (POST /admin/modules/{id}/enable or php gereq module:enable id)
    MM->>MM: check requires (host module enabled), write `modules` row
    A->>M: migrateModule(id) (integrations rarely ship migrations)
    Note over MM,I: next request boot
    MM->>I: register(ModuleContext)
    I->>H: registry->register(driver); capabilities()->register('…', id, driver)
    I->>I: settings()->define('host.key', TYPE_*, label, default, id)
    I->>I: router()->add('/api/v1/…/notify/x', …)
    MM->>I: boot(ModuleContext)
```

- **Enable** — Admin → Modules → Plug-ins → enable, or `php gereq module:enable <id>`. The host module must be enabled first (`requires`). Enabling runs `Migrator::migrateModule($id)`; for the current integrations there are no migration files, so this is a no-op plus schema sync.
- **Register/boot** — every request, `ModuleManager` instantiates the `class` from the manifest and calls `register()` then `boot()` for enabled packages. All current integrations do their work in `register()`.
- **Settings** — `ModuleContext::settings()->define($key, $type, $label, $default, $moduleId)` (`src/Setting/Settings.php`). Keys keep the *host* prefix (`payments.comgate_*`, `shop.packeta_*`) for backwards compatibility; `TYPE_ENCRYPTED` values are encrypted with `APP_KEY`. They are edited in Admin → Settings (`src/Admin/Http/SettingsController.php`). Because the grouping there is by `module` id and the switch only knows core modules, integration-owned keys land in the **"other"** tab (`settings.group.other`).
- **Translations** — `integrations/{Name}/Resources/translations/messages.{cs,en}.php` are loaded like module catalogs (see `tools/gereq-i18n/index-translations.php` which scans `integrations/*/Resources/translations`).
- **Disable / update** — same as modules (`ModuleController::disable`, version switching via `ModuleUpdater`). The rule `.cursor/rules/module-updates.mdc` (version bump on every change) applies to `integrations/*` as well because they are discovered as modules.

## Host capabilities

| Host | Registry / contract | Driver interface | Integration registers via | Consumer |
| --- | --- | --- | --- | --- |
| Payments (`modules/Payments`) | `Modules\Payments\Application\PaymentGatewayRegistry` (`register`, `get`, `getOrManual`, `all`) | `PaymentGatewayInterface` — `key()`, `createPayment(PaymentIntent, PaymentMethod, array $context): array{status, redirect_url, external_id, payment_reference, spayd_payload, meta}`, `handleNotify(array $payload): array{ok, status, external_id, message}` | `$context->get(PaymentGatewayRegistry::class)->register($gateway)` + `capabilities()->register('payment.gateway.<key>', id, $gateway)` | `PaymentService::createForOrder()` selects the gateway by `PaymentMethod::provider()`; built-in `ManualBankGateway` is the fallback |
| Shipping (`modules/Shop`) | `Modules\Shop\Application\ShippingCarrierRegistry` (`register`, `registerAlias`, `get`, `getOrManual`, `all`) | `ShippingCarrierInterface` — `key()`, `createShipment(Shipment, array $context): array{status, tracking_number, external_id, label_url, meta}`, `syncStatus(Shipment): array{status, tracking_number, meta}` | `register($carrier)`, optional `registerAlias('zasilkovna', $carrier)` + `capabilities()->register('shipping.carrier.<key>', …)` | `CheckoutService::createShipmentDraft()` and `modules/Orders/Http/OrderShipmentController.php`; built-ins `ManualCarrier`, `PplCarrier` (scaffold) |
| AI (`modules/Ai`) | `Modules\Ai\Application\LlmProviderRegistry` (`registerLlm`, `registerEmbedding`, `llm`, `embedding`) | `LlmProvider` (`driver`, `isConfigured`, `chat`, `ping`), `EmbeddingProvider` (`embed`, `dimensions`), optional `StreamableLlm` (`chatStream`) | **not by the integration** — drivers are registered by `AiModule::registerBuiltinProviders()`; the `plugin.ai` packages are enable/disable gates checked by `AiProviderAccess` | `ModelRouter`, `AiFacade`, chat/agents UI |
| Inbound webhooks (`src/Webhooks`, optional) | `Gereq\Webhooks\Application\InboundWebhookRegistry` | `Gereq\Api\InboundWebhookProviderInterface` — `name()`, `verify(Request)`, `handle(Request)` | payment integrations register an `*InboundProvider` when the class exists (`class_exists` guard) | `POST /api/v1/webhooks/inbound/{provider}` plus the integration's own `/api/v1/payments/notify/<key>` route |

`src/Module/CapabilityRegistry.php` stores `capability key → (module id, implementation)` so the admin can show which package provides `payment.gateway.stripe`, `shipping.carrier.packeta`, etc.

## Enabling the current set

```bash
php gereq module:enable payments
php gereq module:enable comgate
php gereq module:enable stripe
php gereq module:enable gopay
php gereq module:enable shop
php gereq module:enable packeta
php gereq module:enable ai
php gereq module:enable openai        # unlocks the driver in Admin → AI → Settings
```

`ModuleEnableCommand` accepts exactly one module id per call (`addArgument('module', REQUIRED)`).

## Adding a new integration (checklist derived from existing packages)

1. `integrations/<Name>/module.php` with `kind: integration`, `requires` the host, `groups` with the right `plugin.*`, `capabilities`.
2. `integrations/<Name>/<Name>Module.php` implementing `Gereq\Module\ModuleInterface` (`id()`, `version()`, `register()`, `boot()`).
3. A driver in `Application/` implementing the host interface; register it in `register()` and expose it in `capabilities()`.
4. Settings via `settings()->define(...)` with the host prefix; secrets as `SettingRecord::TYPE_ENCRYPTED`.
5. Public routes (notify/webhook/widget) via `$context->router()`; optional `InboundWebhookProviderInterface`.
6. `Resources/translations/messages.cs.php` + `messages.en.php` including `module.<id>.description`.
7. Add the id to the matching group in `config/module_groups.php` and to `integrations/README.md`.
8. No autoload change is needed — the PSR-4 root `Integrations\` already maps to `integrations/` in `composer.json`.

## Related

- [../modules/index.md](../modules/index.md) — module documentation
- [../AI_PLATFORM.md](../AI_PLATFORM.md) — AI platform (host for `plugin.ai`)
- [../infrastructure/database.md](../infrastructure/database.md) — how `migrateModule` works
