# Changelog

Všechny významné změny platformy GEREQ jsou dokumentované v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verze podle [Semantic Versioning](https://semver.org/lang/cs/).

Zdroj pravdy pro běžící verzi je soubor [`VERSION`](./VERSION).
Při releasu: bump `VERSION` (+ `composer.json` / `package.json`), zápis do této changelogu, git tag `vX.Y.Z`.

## [Unreleased]

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
