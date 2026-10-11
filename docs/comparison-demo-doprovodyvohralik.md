# Porovnání: demo.doprovodyvohralik.cz vs. PLATFORM-V2 (dev)

Datum kontroly: **2026-10-11** (večer, UTC+2).

## Shrnutí

Na serveru `root@gereq.cz` běží klientský balíček v `/var/www/@clients/demo.doprovodyvohralik.cz` — produkční demo podle smlouvy **DIL-2026-028** (Vue storefront + GEREQ platforma + `cms-lib`). Lokální repozitář **PLATFORM-V2** je plná vývojová platforma (45 modulů); na demu jsou nasazeny jen **4 moduly**.

**Platforma na demu odpovídá většině kódu z commitů do ~12:10 UTC** (balíček v `update/2026-10-11/`). **Storefront na serveru byl znovu sestaven 11. 10. kolem 17:33 UTC** (změny ve `fe/src`, výstup v `platform/public/storefront/`).

**Lokální větev je o několik hodin napřed** — commity `dc1f01d` a `d2b5c65` (cca 19:24–19:25 CEST) **na demo ještě nejsou**. Jde hlavně o refaktoring DB připojení / CLI `.env` a drobné úpravy modulů Gereq, Cms a WebPlus.

---

## Co je na serveru (struktura)

| Cesta | Účel |
|--------|------|
| `platform/` | GEREQ Platform **0.5.0** (bez gitu, nasazená kopie) |
| `fe/` | Vue 3 storefront (`doprovody-vohralik-fe`), build do `../platform/public/storefront` |
| `cms-lib/` | Monorepo `@gereq/cms-*` (git, HEAD `ad30c82`) |
| `docker-compose.yml` | Stack `doprovody-demo` (nginx, php, queue, scheduler, postgres, redis) |
| `doprovodyvohralik-postgres.sql` | DB dump (~535 KB) |
| `doprovodyvohralik.cz.zip` | Archiv nasazení (root + `update/2026-10-11/`) |
| `assets/` | Klientská statika mimo platformu |

**Běžící kontejnery** (stav 11. 10.): `doprovody-demo-nginx`, `php`, `queue`, `scheduler`, `postgres`, `redis` — vše Up.

**URL / prostředí:** `APP_URL` / `FRONTEND_ORIGIN` → `https://demo.doprovodyvohralik.cz`, PostgreSQL, Redis pro session/queue/cache.

---

## Moduly: demo vs. PLATFORM-V2

### Na demu (`platform/modules/`)

| Modul | Verze (`module.php`) | Poznámka |
|--------|----------------------|----------|
| **Cms** | 2.1.1 | Submodule na devu (`c88bdeb`) |
| **Gereq** | 1.99.6 | |
| **WebPlus** | 1.99.6 | |
| **SiteInquiries** | 0.2.0 | Zkopírován jako soubory, **není** v `platform/.gitmodules` |

### Pouze v PLATFORM-V2 (na demu chybí)

Zbývajících **~41 modulů** (Achievements, Ai, CRM, Shop, …) — očekávané pro reprezentační web.

### SiteInquiries

- PHP kód na demu **shodný** s lokálním submodulem (kontrola MD5 `SiteInquiriesModule.php`, `InquiryController.php`).
- Migrace `2026_10_10_*` a `2026_10_11_*` na demu **jsou**.
- Na demu **chybí** `modules/SiteInquiries/assets/logo.svg` (lokálně existuje).
- Lokálně je modul řádný **git submodule** v `.gitmodules`; na demu jen flat copy.

---

## Frontend a CMS knihovna (jen na serveru)

V **PLATFORM-V2** není klon `fe/` ani `cms-lib/` — porovnání storefrontu je jednostranné (server → poznámky pro dev).

| Oblast | Stav na demu |
|--------|----------------|
| `fe/package.json` | Závislosti na `file:../cms-lib/packages/*`, Vite 6, Vue 3.5 |
| `fe/vite.config.ts` | Alias na `cms-lib`, build do `platform/public/storefront`, lazy-load kontaktního formuláře (antd off critical path) |
| `fe/dist/` | **Zastaralé** (10. 10. 22:04) — nepoužívat |
| `platform/public/storefront/` | **Aktuální build** (11. 10. 17:33) |
| Nedávné úpravy FE zdrojů | `HomePage.vue`, `assetCache.ts`, `useHomeContent.ts` (17:29–17:30), dříve `CmsEditable.vue`, cache vrstvy, `api.ts` (12:43) |
| `cms-lib` | `ad30c82 feat: update` — balíčky `core`, `vue`, `antd-vue`, `react` |

---

## Co je v PLATFORM-V2 nověji než na demu

Porovnání: `rsync -n` lokální `PLATFORM-V2` → `root@…/platform/` (bez `vendor`, `node_modules`, `.git`).

### Core (`src/`)

| Soubor | Změna |
|--------|--------|
| `Database/DbalConnectionParams.php` | **Nový** na devu, na demu **chybí** |
| `Database/DatabaseCreator.php` | Upraveno |
| `Database/EntityManagerFactory.php` | Upraveno |
| `Cache/CacheFactory.php` | Upraveno |
| `Console/Command/EnvInitCommand.php` | Upraveno |
| `Env/CliEnvFile.php` | Upraveno |
| `Foundation/Application.php` | Upraveno |
| `View/TwigFactory.php`, `TwigRuntime.php` | Upraveno |

**Commit `d2b5c65` — feat: core:** oddělení CLI DB nastavení (`.env.cli` / „not seeing db“), centralizace parametrů DB připojení, testy `DbalConnectionParamsTest`, `CliEnvFileTest`. SiteInquiries přesunut z gitlinku do submodule (bez změny runtime kódu oproti demu).

### Moduly nasazené na demu

| Modul | Soubory odlišné od dema |
|--------|-------------------------|
| **Cms** | `Http/PageController.php` |
| **Gereq** | `GereqModule.php`, `Application/GereqClientConfig.php`, překlady `messages.cs/en.php`, `Tests/GereqClientConfigTest.php` |
| **WebPlus** | `Application/DynamicMeta/DynamicMetaResolver.php` |
| **SiteInquiries** | `assets/logo.svg` (jen lokálně) |

### Admin UI / assets

- `resources/css/`, `resources/js/`, `resources/views/admin/settings/` — **shodné** s demem (styling / brand / CSS editor z commitů `b4bf314`, `f435944` už na serveru).
- `resources/views/admin/audit/index.html.twig` — **lokálně novější** než demo.

### Docker / dev tooling (lokálně, ne klientský compose)

Commit `dc1f01d` mění kořenový `docker-compose.yml`, nginx, PHP Dockerfile/entrypoint, `config/cache.php`, `config/files.php`, `Storefront.php`, `HtmlSanitizer.php` — **klientský** `demo.doprovodyvohralik.cz/docker-compose.yml` je samostatný soubor; část změn se týká hlavně vývojového stacku platformy.

### Co je jen lokálně (na demo nepatří)

- `.gitmodules` včetně **SiteInquiries** submodule
- 41 dalších modulů, testy celé platformy, `.idea/`, phpstan cache, lokální `.env`

---

## Časová osa (orientačně)

```text
11. 10. ~12:10 UTC  — balíček update/2026-10-11/doprovodyvohralik.cz.zip
11. 10. ~12:12 UTC  — rozbalení platform + moduly na server
11. 10. ~12:39–13:22 CEST — commity styling + pin Cms/Gereq/WebPlus (na demu v core/resources)
11. 10. ~17:33 UTC  — rebuild storefront → platform/public/storefront
11. 10. ~19:24–19:25 CEST — dc1f01d + d2b5c65 (dopravy-cms merge, DB/CLI core) → NA DEMU JEŠTĚ NE
```

Lokální `HEAD`: `d2b5c65 feat: core`.

---

## Doporučené kroky při dalším nasazení

1. **Platform core:** nasadit změny z `d2b5c65` (zejména `DbalConnectionParams.php` a související DB/CLI) — ověřit migrace a `.env` / `.env.cli` na produkci.
2. **Moduly:** sync **Cms**, **Gereq**, **WebPlus** (verze pinů 2.1.1 / 1.99.6 zůstávají; obsah souborů se liší).
3. **SiteInquiries:** volitelně doplnit `assets/logo.svg`.
4. **Frontend:** z `fe/` znovu `vite build` (výstup do `platform/public/storefront`), ne spoléhat na `fe/dist/`.
5. **cms-lib:** při změně FE zkontrolovat, zda je potřeba pull novějšího commitu než `ad30c82`.

---

## Metodika

- SSH read-only průzkum `/var/www/@clients/demo.doprovodyvohralik.cz`
- Porovnání souborů: `rsync --dry-run --itemize-changes` proti lokálnímu `/home/gereq/private/PLATFORM-V2`
- Git log platformy a submodule pinů
- **Nečteny** `.env`, `.deploy-credentials` na serveru (citlivá data)
