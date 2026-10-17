# Changelog

Všechny významné změny platformy GEREQ jsou dokumentované v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verze podle [Semantic Versioning](https://semver.org/lang/cs/).

Zdroj pravdy pro běžící verzi je soubor [`VERSION`](./VERSION).
Při releasu: bump `VERSION` (+ `composer.json` / `package.json`), zápis do této changelogu, git tag `vX.Y.Z`.

## [Unreleased]

## [0.6.0] — 2026-10-17

Commerce children žijí pod Shop / WebPlus, setup wizard umí profil provozovatele a výběr modulů, admin umí drift footprintu s volitelným patch bumpem a sjednocený select / UI preferences.

### Added

- Nested submodules: commerce děti (Orders, Products, Payments, Returns, Coupons, Warehouse, Bundles, Loyalty, CustomerUploads) pod `modules/Shop/Submodules/`; WebPlus Ai pod `modules/WebPlus/Submodules/Ai`.
- Setup flow s profilem provozovatele (firma / osoba), katalogem modulů podle kategorií a krokem dashboard widgetů.
- Detekce změn oproti nainstalovanému footprintu (`ModuleChangeDetector`), semver helper a `ModuleVersionBumper` — v adminu update s volitelným patch bumpem při driftu.
- UI preferences uživatele (sidebar / hustota) a rozšířený select (searchable / multi / clearable).
- `StorageLayout` — runtime adresáře pod `storage/` se zakládají při startu, po `cache:clear` a v Docker entrypointu.
- Admin tabulka měn jako sdílená komponenta; vylepšené module table (submodules, children).

### Changed

- Odstraněny top-level git submodules Bundles, Coupons, CustomerUploads, Loyalty, Orders, Payments, Products, Returns, Warehouse a WebPlusAi z platform `.gitmodules`.
- Integrace Comgate / GoPay / Stripe / Packeta a core odkazy míří na `Modules\Shop\Submodules\…`; vyžadují payments/orders/products `^2.0` dle manifestů.
- Kernel CSS (forms, tables, layout, panels, auth) a setup/modules/settings Twig.
- Piny modulů: Shop `2.2.5`, WebPlus `2.1.1`, Cms `2.16.6` a navazující patch bump dependent modulů.

### Upgrade notes

- Platforma **0.5.5 → 0.6.0**. Po pulli aktualizovat submodules (`./bin/grq modules pull`) a v adminu spustit update u Shop, WebPlus a dependent modulů (migrace + permissions).
- Starší top-level cesty `modules/Products` atd. už platforma nepinuje — zdroj je `modules/Shop/Submodules/…`.
- Docker / fresh install: entrypoint zakládá rozšířený strom `storage/cache/*` a související runtime adresáře.

## [0.5.5] — 2026-10-13

Souhrn změn od platformy **0.5.0** do **0.5.5**, včetně aktuálních úprav CMS editoru a navazující Vue knihovny. Dílčí verze 0.5.1–0.5.4 nejsou v tomto changelogu samostatně rozepisované.

### Added

- Pracovní prostor pro nepublikovaný obsah: přehled změn, detail položky a rozšířené API pro publikování. Stav změn se průběžně promítá do horní lišty administrace.
- Fluent API pro registraci publikovatelných kategorií a rozšířené napojení modulů na publikační pipeline.
- Material Symbols katalog a rozšířená podpora ikon v administraci a CMS.
- CMS kontejnery s režimy Flex a Grid: směr, zalamování, samostatné mezery řádků/sloupců, zarovnání, definice mřížky a nastavení jednotlivých vnořených položek.
- Levá Knihovna CMS se záložkami Přidat, Šablony, Proměnné, Média a Dokumenty. Její otevření a poslední záložka se pamatují v prohlížeči.
- Klávesové zkratky pro vybraný CMS blok: Delete pro odstranění, šipky nahoru/dolů pro přesun, Ctrl+Z pro zpět a Ctrl+Y nebo Ctrl+Shift+Z pro znovu. Fungují také uvnitř náhledu; při psaní se zachovává ovládání textového pole.
- Historie až 100 úprav CMS bloků. Nová úprava ruší větev pro opakování; historie se resetuje po uložení.
- Našeptávání CMS proměnných při psaní `{{…}}`, hledání podle tokenu i lokalizovaného názvu a výběr myší, šipkami, Enterem nebo Tabem.
- Zvýrazněné štítky proměnných v textu a nadpisech editoru, s okamžitým zobrazením lokalizovaného názvu při najetí. V uloženém obsahu zůstává původní token.
- Další fonty pro CMS bloky a textovou lištu: Arial, Verdana, Tahoma, Trebuchet MS, Georgia, Times New Roman, Palatino, Courier New, Impact a systémové písmo. Fonty používají lokální náhradní rodiny.
- Nastavitelné rozměry obrázků, miniatur galerie a zvětšeného zobrazení; hodnota 0 znamená automatickou velikost.
- Zvětšené zobrazení obrázků a fotografií galerie na veřejném Vue webu, s omezením podle velikosti obrazovky, ovládáním klávesnicí a zavřením přes Escape.
- Rozšířené testy front, plánování, publikování, storefrontu, Gravataru a normalizace slugů.
- Projektové instrukce a skills pro práci s moduly, PHP, administrací a publikováním.

### Changed

- Modernizovaný hlavní sidebar administrace, horní vyhledávání, avatary přes Gravatar a sdílené UI styly.
- Přepracovaná záložka Značka: kompaktnější sekce identity administrace a veřejného webu, sjednocená paleta barev, širší přichycený živý náhled a rozbalovací vlastní CSS.
- Rozšířené úpravy brandingu a barev přes backend, živé náhledy a práce s vlastními CSS proměnnými.
- Pravý CMS toolbox sjednocuje nastavení a vrstvy. Sekce Obsah, Velikost, Styly, Typografie a Obrázek jsou dostupné přímo ve vodorovné navigaci podle typu bloku.
- Záložka Obsah se skrývá u nadpisu, textu, HTML a ikony, kde se obsah upravuje přímo na stránce. Automaticky se otevře Velikost.
- Sjednocené rozměry polí, selectů a tlačítek CMS, zarovnání ovládacích prvků a typografie popisků, šablon a proměnných na sdílené admin tokeny.
- Proměnné v Knihovně jsou uspořádané do rozbalovacích sekcí s názvem, počtem a šipkou. Položky zobrazují lokalizovaný název; token se ukazuje při hoveru. Odstraněna úvodní nápověda, celkové počítadlo a filtry podle typu.
- Přidávání bloků a šablony jsou přesunuté do levé Knihovny. Tlačítko složky je vlevo nahoře na pracovní ploše a při otevřené Knihovně se skrývá.
- Desktopový, tabletový i mobilní CMS náhled se centrují v dostupné pracovní ploše při otevření nebo zavření sidebarů.
- Aktualizované piny modulů, mimo jiné CMS, WebPlus, Gereq, AI, Engage, Events, Forum, Listings a Products; publikační adaptéry jsou přesouvané k příslušným modulům.
- Refaktoring sdíleného jádra a registrátorů médií, zákazníků, měn, notifikací, front a plánování; zachované legacy aliasy pro kompatibilitu.
- Úpravy Docker prostředí, konfigurace cache/souborů/front, storefront rebuild nástrojů, demo seedů a dokumentace.

### Fixed

- Šířka CMS bloku 100 % se nastavuje explicitně a nechová se jako automatická šířka podle obsahu.
- Text a nadpisy se zarovnávají přes celou šířku bloku; vnitřní omezení šířky textu již neposouvá střed nebo pravý okraj.
- Přepínání sekcí toolboxu používá metody kompatibilní s Alpine CSP místo několika příkazů v jednom výrazu.
- Proměnnou lze vložit přímo do vybraného textu, nadpisu nebo HTML bez nutnosti aktivovat pole v inspectoru. U kontejneru vznikne textový blok uvnitř.
- Výběr proměnné z našeptávače vytváří štítek okamžitě i během editace a ponechává kurzor za vloženou proměnnou.
- Barva štítků proměnných vychází z aktuální značky; odstraněna pevně nastavená růžová barva.
- Ukládání nových rozměrů obrázků a miniatur včetně kontroly rozsahu; opraveno čtení pole `ratio` při zachování kompatibility s původním `ration`.
- Normalizace slugů přes `Str::slug` je nezávislá na operačním systému.
- Opravy a rozšíření testů a statických kontrol v jádře.

### Removed

- Staré brandování KdeAco/Vikendo a vývojová storefront cesta `/dev-ca`.
- Modální překryv Knihovny CMS a plovoucí panel Přidat, nahrazené levým sidebarem.
- Duplicitní přepínač Obsah/Design a technické názvy typů bloků na kartách pro přidání.

### Upgrade notes

- Platforma přechází z 0.5.0 na 0.5.5; aktuální pracovní verze modulu CMS je 2.12.1.
- Nasadit společně backend CMS, aktualizovanou Vue CMS knihovnu a znovu sestavené admin i storefront assets.
- Aktualizace modulů provést přes admin update, aby proběhly jejich migrace a synchronizace oprávnění.

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
