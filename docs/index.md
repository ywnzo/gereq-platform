# GEREQ / RealEstates — Documentation

GEREQ is a modular backend platform for internal systems (admin, CRM, ERP, portals) built on PHP 8.4, Doctrine ORM, Symfony components, Twig and Alpine.js, with Bun for admin assets (storefronts may still use Vite). The core (`src/`) provides authentication, permissions, the module system, hooks and events, publishing, API, audit, settings, files, queue and the admin shell; business logic lives in modules (`modules/`) and third-party adapters in `integrations/`. Product packages (`config/module_groups.php`) include **RealEstates** (listings, viewings, portals, CRM, CMS, Web+/Web+AI), **ECS** / **EBS** (car / bike workshop), **Forum** (Q&A, community, achievements, messenger) and **GEREQ** (ticketing desk), plus commerce modules.

This documentation is generated from the code base and organised by concern. When code and docs disagree, the code wins — every page cites the source files it was derived from.

## Getting started

| Page | Contents |
|---|---|
| [Installation](getting-started/installation.md) | Requirements, cloning with submodules, `bin/grq install`, migrations, asset build, dev servers, Docker-only `Makefile` flow, dockerless link |
| [Configuration](getting-started/configuration.md) | `config/*.php` files and every `.env` variable |
| [CLI reference](getting-started/cli.md) | All `php gereq` commands |

## Architecture

| Page | Contents |
|---|---|
| [Overview](architecture/overview.md) | Bootstrap, `src/` namespaces, request lifecycle, DI conventions |
| [Module system](architecture/module-system.md) | Manifest, `ModuleContext`, enable/disable, versioning, GitLab upgrades, migrations, packages & plug-ins |
| [Auth & permissions](architecture/auth-permissions.md) | Sessions, users/roles/permissions, WebAuthn, API tokens, CSRF, security middleware |
| [Hooks, events & webhooks](architecture/hooks-events-webhooks.md) | `HookManager`, `EventBus`/`EventRegistry`, queue, notifications, outbound webhooks |
| [API](architecture/api.md) | `/api/v1` DSL, scopes, serializers, OpenAPI, public API |
| [Admin UI](architecture/admin-ui.md) | Admin shell, menu, command palette, UI components, Twig extensions, Alpine registry, CSS, icons |
| [Dashboard widgets](architecture/dashboard-widgets.md) | Widget contract, shared templates, chart series, layout persistence, masonry grid |
| [Publishing, i18n, settings & platform services](architecture/publishing-i18n-settings.md) | Staging → production pipeline, settings, translations, audit, files, cache, search, validation, brand, ARES, automation |

## Modules

[Module index](modules/index.md) — all **47** modules grouped by domain with versions, dependencies and purpose. Each module has its own page (`modules/<id>.md`) covering domain model, admin UI, API, events, widgets, services, settings and migrations.

Quick links for the RealEstates package: [Listings](modules/listings.md) · [Viewings](modules/viewings.md) · [Portals](modules/portals.md) · [WebPlus](modules/webplus.md) · [AI (Web+)](modules/webplus_ai.md) · [Cms](modules/cms.md) · [CRM](modules/crm.md) · [Customers](modules/customers.md) · [Calendar](modules/calendar.md) · [Documents](modules/documents.md) · [Media](modules/media.md) · [Reviews](modules/reviews.md) · [Emailing](modules/emailing.md) · [Currency](modules/currency.md)

Forum package: [Forum](modules/forum.md) · [Community](modules/community.md) · [Achievements](modules/achievements.md) · [Messenger](modules/messenger.md)

## Integrations (plug-ins)

| Page | Contents |
|---|---|
| [Overview](integrations/index.md) | Integration vs module, plug-in groups, host capabilities |
| [Payments](integrations/payments.md) | Comgate, Stripe, GoPay gateway adapters |
| [Shipping](integrations/shipping.md) | Packeta carrier adapter |
| [AI providers](integrations/ai-providers.md) | Ollama, OpenAI-compatible, Z.ai, DeepSeek, Gemini, Anthropic, OpenAI |

## Infrastructure

| Page | Contents |
|---|---|
| [Docker](infrastructure/docker.md) | Compose stack, nginx/PHP-FPM/Redis/Postgres, `php gereq serve-docker`, HTTPS, Bun watch (`--hot`), `Makefile` |
| [Database](infrastructure/database.md) | Drivers, Doctrine mapping, migrations, seeders, demo data |
| [Cache, queue, session & files](infrastructure/cache-queue-session-files.md) | Drivers and `storage/` layout |
| [Deployment](infrastructure/deployment.md) | Production checklist, logging, monitoring, security headers |
| [ZIP update guidelines](UPDATE-GUIDELINES.md) | Safe rsync from platform ZIP, excludes, post-update steps |
| [Tooling](infrastructure/tooling.md) | `bin/grq` (incl. `modules pull` / `modules-git`); sources in [GEREQ_TOOLS](https://git.gereq.cz/gereq/GEREQ_TOOLS); Cursor rules & skills, static analysis |

## Frontend

| Page | Contents |
|---|---|
| [Admin UI assets](architecture/admin-ui.md#frontend-assets-bun) | Bun kernel + lazy `module:{id}` assets, `resources/js`, `resources/css` |
| [Public frontends](frontend/public-frontends.md) | React apps `FE2/` and `frontend/` consuming `/api/public/*` |
| [PageSpeed](frontend/pagespeed.md) | Media srcset, lazy icons, Web+ SPA head inject, health checks |

## Development

[Malware scanning](development/malware-scanning.md) — shared scanner, runtime setup and form attachment policy.

| Page | Contents |
|---|---|
| [Creating a module](development/creating-a-module.md) | Scaffolding, register/boot, routes, API, events, widgets, migrations, GitLab submodule, release checklist |
| [Coding standards](development/coding-standards.md) | PHP/TS/Twig conventions, PHPStan, phpcs, comments, Bun-only, no native dialogs |
| [Testing](development/testing.md) | PHPUnit layout, static analysis, running inside Docker |
| [Dockerless (home-only)](development/dockerless.md) | No Docker: SQLite + `php -S` or user-space nginx/php-fpm; scripts in [gereq/dockerless](https://git.gereq.cz/gereq/dockerless) (submodule `dev/dockerless`) |

## Reference documents

Older, topic-specific documents kept at the root of `docs/`:

- [AI platform](AI_PLATFORM.md) — agents, RAG, provider routing (Czech)
- [Forms & FormGenerator](FORMS.md) — `#[FormField]` attributes and `forms()->render()` (Czech)
- [Module audit](MODULE_AUDIT.md) — test coverage and refactoring priorities per module (Czech)

Project-level files: [`README.md`](../README.md) (Czech quick start), [`CHANGELOG.md`](../CHANGELOG.md), [`VERSION`](../VERSION).

## Conventions used in these docs

- File paths are relative to the repository root and written in backticks: `src/Foundation/Application.php`.
- Commands assume the repository root; inside Docker prefix with `docker exec gereq-php-1`.
- JavaScript tooling is always invoked with Bun (`bun run …`, `bun x …`), never npm/npx.
- Module ids are lowercase (`crm`, `customer_uploads`); directory names are PascalCase (`CRM`, `CustomerUploads`).
