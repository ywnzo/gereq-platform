# REST API

GEREQ exposes two HTTP API surfaces: the **authenticated API** under `/api/v1` (Bearer tokens or admin session, scope-checked) and the **public API** under `/api/public/*` (single site key, CORS-enabled, stricter rate limits, meant for storefronts and the RealEstates React frontend). Modules declare authenticated endpoints with a small DSL (`$context->api()->module(...)`) that the core binds to real routes and describes in OpenAPI at `/api/openapi.json`. Everything is JSON; errors share one envelope. Source: `src/Api/*`, `src/Http/PublicCors.php`, `src/Http/Kernel.php`.

## Endpoint families

| Family | Prefix | Auth | Middleware (from `Kernel::dispatch`) |
| --- | --- | --- | --- |
| authenticated | `/api/v1/<module>/…` (`config/api.php` → `prefix`) | Bearer `grq_live_*` / `grq_test_*` **or** admin session | `ApiRateLimitMiddleware`, `VitalityTimingMiddleware`, `CorrelationIdMiddleware`, route alias `api` → `ApiAuthMiddleware` |
| public | `/api/public/…` | Bearer / `X-Api-Key: grq_pub_*` **or** first-party browser (`Sec-Fetch-Site` same-origin/same-site, or `Origin` / `Referer` in `FRONTEND_ORIGIN` / `APP_URL`) | `ApiRateLimitMiddleware`, `PublicApiAuthMiddleware` (auto-added by `PublicCors::isPublicApiPath()`), CORS via `PublicCors::apply()` in controllers |
| meta | `/api/v1/health`, `/api/openapi.json` | none | `HealthController`, `OpenApiController` |

CSRF is skipped for every `/api/*` path (`CsrfMiddleware`).

## Declaring endpoints: `ApiRegistry` / `ApiModule`

In `register()`:

```php
$context->api()->registerKnownScope('viewings.read');
$context->api()->registerKnownScope('viewings.write');

$context->api()->module('viewings', function (ApiModule $api): void {
    $api->get('/', [ViewingApiController::class, 'index'])->scope('viewings.read')->summary('List viewings');
    $api->post('/', [ViewingApiController::class, 'store'])->scope('viewings.write')->ruleset('viewings.save');
    $api->get('/{id}', [ViewingApiController::class, 'show'])->scope('viewings.read');
    $api->patch('/{id}', [ViewingApiController::class, 'update'])->scope('viewings.write');
    $api->delete('/{id}', [ViewingApiController::class, 'destroy'])->scope('viewings.write');
});
```

| Class | API |
| --- | --- |
| `Gereq\Api\ApiRegistry` (`src/Api/ApiRegistry.php`) | `module(name, fn(ApiModule))` (callable may be invoked several times for the same name — routes accumulate), `modules()`, `allRoutes()`, `scopes()` (sorted union of route scopes + known scopes), `registerKnownScope()`, `bind(Router)` |
| `Gereq\Api\ApiModule` (`src/Api/ApiModule.php`) | `get/post/put/patch/delete(path, handler): ApiRouteDefinition`, `resource(name, controller, scopePrefix)` — shorthand for `index/store/show/update/destroy` with `<prefix>.read` / `<prefix>.write`, `routes()` |
| `Gereq\Api\ApiRouteDefinition` (`src/Api/ApiRouteDefinition.php`) | fluent `scope(...)`, `permission()`, `summary()` (OpenAPI), `ruleset()` (OpenAPI request schema from a validation ruleset), `fullPath(prefix)` → `/api/v1/<module><path>` |

`ApiRegistry::bind()` (called at the end of `Application::create()`) turns every definition into a `Route` named `api.<module>.<index>`, adds the `api` middleware alias, copies scopes and permission. Route names are positional, so use paths (not names) when linking to API endpoints.

Public endpoints are **not** declared through `ApiModule`; modules add them directly on the router (`$context->router()->get('/api/public/viewings', …)`) plus an `OPTIONS` route for CORS pre-flight, as in `modules/Viewings/ViewingsModule.php` and `CoreRegistrar::routes()` (`/api/public/ares/addresses`, `/api/public/branding`).

## Authentication

### Tokens

`Authorization: Bearer grq_live_<48 hex>` (or `grq_test_…`, same behaviour, different prefix for bookkeeping). Managed in Admin → Developer → API tokens (`developer.tokens.manage`), issued by `Gereq\Api\Auth\ApiTokenService::create()`; only the SHA-256 hash is stored. `ApiAuthMiddleware` (`src/Api/Http/ApiAuthMiddleware.php`):

1. `findByPlain()` → active token → owning active `User`, else `401 unauthorized`.
2. Sets `_api_token`, `_api_actor = {type: api_token, id}`, `_api_user`; soft-binds the user into `Authenticator` for the request (no session write); `touch()` updates `last_used_at`.
3. If the route has scopes: `ApiToken::hasAnyScope()` must pass, else `403 "Missing required API scope."`.

Scopes are free strings registered by modules (`customers.read`, `viewings.write`, `crm.deals.read`). Token scope matching (`src/Api/Entity/ApiToken.php`): `*` or `full` = everything; exact match; `<module>.*` wildcard for two-segment scopes. Tokens may carry an expiry and can be revoked.

### Session

A logged-in admin can call `/api/v1` from the browser (the Developer → API tester does this). `_api_actor = {type: user, id}`; scopes are mapped to permissions by `sessionAllows()`: the route's `->permission()` if any, the scope itself as a permission name, then `x.read` → `x.view` / `x.manage` and `x.write` → `x.edit` / `x.create` / `x.manage`, also tried on the first segment (`crm.deals.read` → `crm.view`). Routes with `->permission()` but no token are checked with `User::can()`.

### Public key

`PublicApiAuthMiddleware` (`src/Api/Http/PublicApiAuthMiddleware.php`) requires `API_PUBLIC_KEY` to be configured (`503` otherwise). A request is accepted when either (1) `Authorization: Bearer grq_pub_…` / `X-Api-Key` matches, or (2) it is a **first-party** storefront call (`Sec-Fetch-Site: same-origin|same-site`, or `Origin` / `Referer` origin allowlisted via `FRONTEND_ORIGIN` / `APP_URL` / local Vite in debug) — so FE2 can call same-origin `/api/public/*` without putting the key in the browser bundle. Same-origin GETs often omit `Origin`; LAN IP hosts may also omit `Sec-Fetch-Site`, so `Referer` is checked as a fallback. Bare scripts without those signals still need the key (`401` otherwise). Sets `_api_public_key = true` (and `_api_public_first_party` when no key was used). There is no per-user identity; endpoints must be safe for anonymous callers. `FRONTEND_ORIGIN` also restricts `Access-Control-Allow-Origin`; empty means `*`. In Vite DEV the `/api` proxy can also attach `VITE_API_PUBLIC_KEY` server-side.

## Request conventions

`Gereq\Api\ApiQuery::fromRequest($request, sortable, filterable, allowedIncludes, allowedFields, defaultPerPage = 25, maxPerPage = 100)` (`src/Api/ApiQuery.php`) parses list parameters:

| Query parameter | Meaning |
| --- | --- |
| `page`, `per_page` | 1-based paging, capped at `maxPerPage` |
| `sort`, `direction` | field from the controller's `sortable` allow-list, `asc`/`desc` |
| `search` (alias `q`) | free-text |
| `filter[field]=value` | only keys in `filterable`; multiple values comma-separated depending on controller |
| `include=a,b` | relations from `allowedIncludes` |
| `fields=a,b` | sparse field sets from `allowedFields` |

`ApiQuery::meta($total)` returns `{page, per_page, total, pages}` for collections; `offset()` for the query builder.

Write requests send JSON bodies (`Content-Type: application/json`). Controllers validate with the same rulesets as the admin forms (`ValidationRegistry`, e.g. `customers.save`); see `modules/Customers/Http/Api/CustomerApiController.php::validateBody()` for the pattern.

Idempotency: `Gereq\Api\IdempotencyService` (`src/Api/IdempotencyService.php`) stores `IdempotencyRecord`s keyed by the `Idempotency-Key` header + hash of method/path/body and replays the stored `JsonResponse`; used by `modules/Shop/Http/Api/CheckoutApiController.php` for `POST /api/v1/shop/checkout`.

Correlation: `CorrelationIdMiddleware` reads or generates `X-Request-ID`, stores it as `correlation_id` and echoes it in the response; `EventBus::emit()` copies it into event envelopes.

## Response format

Helpers in `Gereq\Api\ApiResponse` (`src/Api/ApiResponse.php`):

```jsonc
// ApiResponse::data($item, 200|201)
{ "data": { ... } }

// ApiResponse::collection($items, $query->meta($total))
{ "data": [ ... ], "meta": { "page": 1, "per_page": 25, "total": 137, "pages": 6 } }

// ApiResponse::error($code, $message, $fields = [], $status = 400)
{ "error": { "code": "validation_failed", "message": "…", "fields": { "email": "…" } } }
```

Shortcuts: `notFound()` (404 `not_found`), `unauthorized()` (401 `unauthorized`), `forbidden()` (403 `forbidden`). Uncaught exceptions on API paths (or when `Accept: application/json`) are converted by `Gereq\Http\ExceptionHandler::toJson()` into the same envelope with codes `unauthorized`, `forbidden`, `not_found`, `method_not_allowed`, `csrf_mismatch`, `validation_failed` (422 with `fields`), `too_many_requests`, `unavailable`, `server_error`; in debug mode 5xx payloads add `exception`, `file`, `trace`.

Serializers are plain private methods on the API controllers (`serializeCustomer()`, …) returning arrays; there is no shared serializer layer. `modules/Media/Http/Api` and `tests/Unit/Modules/Media/MediaApiContractTest.php` show how a module pins its payload contract with a test.

## Rate limits

`ApiRateLimitMiddleware` (`src/Api/Http/ApiRateLimitMiddleware.php`) uses `Gereq\Security\RateLimiter` (file-based, `storage/cache/rate`) with a 60 s window (`api.rate_limit.window`):

| Bucket | Limit (per window) | Env |
| --- | --- | --- |
| `/api/v1/*` read | 600 | `API_RATE_LIMIT` |
| `/api/v1/*` write (POST/PUT/PATCH/DELETE) | 120 | `API_RATE_LIMIT_WRITE` |
| `/api/v1/store/*` (storefront) | 60 | `API_RATE_LIMIT_STORE` |
| `/api/public/*` read | 120 | `API_RATE_LIMIT_PUBLIC` |
| `/api/public/*` write | 30 | `API_RATE_LIMIT_PUBLIC_WRITE` |

The key is the token id when already authenticated, otherwise a hash of the `Authorization` / `X-Api-Key` header, falling back to the client IP. Exceeding returns `429 {"error":{"code":"rate_limited","message":"Too many requests."}}`. A non-empty `API_PERF_SECRET` matching the `X-Perf-Secret` header bypasses limiting (`X-Perf-Bypass: 1`) for load tests.

## OpenAPI

`GET /api/openapi.json` (`src/Api/OpenApiController.php`, `src/Api/OpenApi/OpenApiGenerator.php`) builds an OpenAPI 3 document from `ApiRegistry::allRoutes()`: one tag per module, `summary` from `->summary()` or inferred from method + path, security scheme for Bearer tokens, `x-scopes` per operation, and request-body schemas generated from validation rulesets (`->ruleset('customers.save')` → `ValidationRulesToSchema`, tested in `tests/Unit/Api/ValidationRulesToSchemaTest.php`). Admin → Developer → API (`developer.api.view`) renders the same data with an interactive tester (ephemeral full-access token from `ApiTokenService::issueTesterToken()`).

## Public endpoints in this deployment

Registered by core and the RealEstates modules (grep `api/public` in `src/Foundation/CoreRegistrar.php` and `modules/*/*Module.php`):

| Path | Module | Purpose |
| --- | --- | --- |
| `GET /api/public/branding` | core | brand name/colours/logo for external frontends |
| `GET /api/public/ares/addresses` | core | address autocomplete via ARES |
| `GET /api/public/listings`, `/listings/{slug}`, `/brokers`, `/brokers/{id}`, `/cities`; `POST /api/public/inquiries` | Listings | property catalogue, brokers, cities, contact inquiries |
| `GET /api/public/pages`, `/pages/{slug}`, `/menus`, `/menus/{location}` | Cms | CMS pages and navigation menus |
| `POST /api/public/viewings` | Viewings | public viewing request (ruleset `viewings.public_request`, emits `viewing.requested`) |
| `GET /api/public/reviews` | Reviews | published reviews |
| `GET /api/public/store/products`, `/products/{id}`, `/categories` | Products | storefront catalogue |
| `/api/public/webplus/*` (meta, html, site, sitemap.xml, robots.txt, llms.txt, google-products.xml) | WebPlus | SEO/marketing artefacts |

Each public `GET`/`POST` has a sibling `OPTIONS` route for CORS pre-flight. Public reads go through the production snapshot when content publishing is enabled (see [publishing](publishing-i18n-settings.md#publishables-staging--production)).

## Health

`GET /api/v1/health` (`src/Api/HealthController.php`) — unauthenticated liveness probe returning `{"data": {"name", "version", "status": "ok", …}}`.

Related: [auth & permissions](auth-permissions.md), [hooks, events, webhooks](hooks-events-webhooks.md), [configuration](../getting-started/configuration.md#configapiphp).
