# Changelog

Všechny významné změny platformy GEREQ jsou dokumentované v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verze podle [Semantic Versioning](https://semver.org/lang/cs/).

Zdroj pravdy pro běžící verzi je soubor [`VERSION`](./VERSION).
Při releasu: bump `VERSION` (+ `composer.json` / `package.json`), zápis do této changelogu, git tag `vX.Y.Z`.

## [Unreleased]

## [0.2.6] — 2026-09-11

### Added

- realityV3 installer/packager (`scripts/realityv3/install`, `scripts/realityv3/pack`) a seed profile
- URL path prefix support (`UrlPrefix`) for admin/FE behind reverse-proxy paths
- Broker scoping across CRM / Listings / Documents / Calendar / Viewings / Customers

### Changed

- Platforma `VERSION` 0.2.5 → 0.2.6
- Stripe inbound: require signature verification
- Pin dirty modules na nové semver tagy
- VS Code rozšíření: i18n / twig-goto / monitor / permissions (patch bump)

### Security

- Stripe webhook unsigned payloads rejected
- API auth / idempotency / rate-limit hardening

## [0.2.5] — 2026-09-10

### Added

- Dashboard widgety napříč moduly (metric / list / chart panely) a sdílené šablony
- Dokumentace platformy (`docs/`)
- Public API auth middleware, N+1 log (`nn`), image viewer, dashboard grid UI
- Makefile Docker-only workflow

### Changed

- Platforma `VERSION` 0.1.0 → 0.2.5
- Pin všech dirty modulů na nové semver tagy
- VS Code rozšíření: i18n 0.2.1, twig-goto 0.3.5, monitor 0.3.1, permissions 0.1.1

## [0.1.0] — 2026-09-08

### Added

- Semver platformy (`VERSION`, `AppVersion`, zobrazení v adminu a na přihlášení)
- Modulární jádro GEREQ Platform (admin, API, migrace, fronty, branding)
