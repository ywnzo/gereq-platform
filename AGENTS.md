# GEREQ Platform — pravidla pro agenty

## Orientace v projektu

- Jde o vlastní modulární platformu nad PHP 8.4+, Doctrine ORM, Symfony komponentami a Twig. Nepředpokládej Laravel ani Symfony full-stack aplikaci.
- `src/` je sdílené Core (`Gereq\`), `modules/` jsou business moduly (`Modules\`), `integrations/` integrační pluginy (`Integrations\`). Business logiku drž v odpovídajícím modulu.
- Nejdřív přečti relevantní dokumentaci a nejbližší existující implementaci. Výchozí mapa je `docs/index.md`; pravidla jsou v `docs/development/coding-standards.md` a `.cursor/rules/`.
- Aktuální konfigurace a zdrojový kód mají při technickém rozporu se starší dokumentací přednost. Například frontend používá nativní Bun build v `scripts/build.ts`, ne Vite.
- Před úpravami zkontroluj `git status --short`. Zachovej rozpracované změny uživatele v platformě i submodulech.

## Nástroje

- Pro JavaScript/TypeScript preferuj Bun: `bun install`, `bun add`, `bun remove`, `bun run <script>`, `bunx <tool>`. Kořenové skripty Bun přímo vyžadují.
- V každém samostatném balíčku v `tools/` nejdřív ověř `package.json`, `packageManager`, `engines`, lockfile a CI. Respektuj explicitně požadovaný runtime. Nemigruj správce balíčků ani nemaž existující lockfiles jako vedlejší změnu.
- PHP závislosti a kontroly spouštěj přes Composer; Go nástroje přes Go. Pokud potřebný runtime chybí, uveď to v reportu ověření.
- Produkční frontend: `bun run build`; watch: `bun run dev`. Generované soubory v `public/build/` neupravuj ručně.

## Architektonické invarianty

- Moduly nesahají do tabulek jiných modulů. Používej contracts, providers a capabilities; zachovej deklarované závislosti a možnost vypnutí modulu.
- Kontrola permissions musí být na backendu. API používá odpovídající scopes; skrytí tlačítka není autorizace.
- Mutující formuláře a session AJAX zachovávají CSRF ochranu. Validaci zapojuj přes pojmenované rulesety `ValidationRegistry`.
- Schema a datové upgrady patří do `Database/Migrations/`, ne do běžných requestů. Migrace mají `up()` i `down()`; případnou nevratnost popiš výslovně.
- Kritické mutace zachovávají audit. API používá serializéry a povolené filtry; event payloady jsou verzovaná data, nikoli Doctrine entity.
- Veřejný obsah a registrované CRM entity procházejí publish pipeline. Rozlišuj obsah od okamžitých runtime operací podle skillu `gereq-publishing`.

## Styl a UI

- PHP: `declare(strict_types=1);`, PSR-12 podle `phpcs.xml`, typované parametry, properties a návraty; PHPDoc pro kolekce a array shapes. Preferuj `final`, ale Doctrine entity ponechávej vhodné pro proxy.
- Komentáře, docblocky, TODO/FIXME a CLI help v kódu piš anglicky. Uživatelské texty lokalizuj; nové klíče doplň v `messages.cs.php` i `messages.en.php`.
- Administrace používá sdílené Twig UI komponenty, formulářový generátor a CSS kernel. Zachovej CSP variantu Alpine a výchozí escapování Twig.
- Pro potvrzení používej `window.gereq.confirm`, `window.gereq.prompt` nebo `data-confirm`; pro výsledky toast. Nové nativní browser dialogy nepřidávej.
- Neopravuj okolní legacy kód nebo formátování mimo rozsah úkolu.

## Moduly a Git

- `modules/*` jsou samostatné Git submodules. Úpravy kódu patří do jejich repozitářů; platforma sleduje gitlink. Nespoléhej jen na kořenový diff pro kontrolu změn modulu.
- Změny kódu, migrací nebo UI modulu vyžadují bump `module.php` a shodné `Module::version()`: patch pro opravu, minor pro funkci/migraci, major pro breaking change.
- Obsah vydané verze je immutable. Admin update spouští migrace a sync permissions; samotné `php gereq migrate` ho nenahrazuje.
- Release tag `vX.Y.Z` musí odpovídat verzi. Bump není sám o sobě pokyn vytvořit tag, commit, push či publikovat release.
- Bez výslovného zadání nespouštěj fresh migrace, produkční seed, deploy ani operace přepisující klientská data. Zachovej `.env`, `storage/`, uploady a klientské úpravy.

## Ověření

- Spusť relevantní testy přes `php bin/gereq-test <target>` nebo `composer test -- <target>`. Module suite spouštěj testing enginem; raw PHPUnit suite `Modules` neobsahuje všechny module testy.
- Před předáním změn PHP ověř příslušné testy a analýzu. Před commitem platí projektový gate: `composer test`, `composer phpstan`, `composer phpstan:tests`, `composer cs`.
- Nezvětšuj PHPStan baseline kvůli novým chybám. Odděl existující selhání od regresí změny.
- U TS změn použij `bunx tsc --noEmit` a `bun run build`; kořenový tsconfig neobsahuje všechny module assets, jejich pokrytí ověř zvlášť. Pro UI zkontroluj i chování a layout, je-li dostupné prostředí.
- U čistě instrukčních změn ověř odkazy a skill frontmatter; není potřeba spouštět aplikační build.
- V závěru uveď co se změnilo, co bylo ověřeno a které kontroly nebylo možné provést.

## Projektové skills

Skills jsou uložené v projektovém `skills/`; nejde o automaticky objevovaný adresář `.agents/skills/`. Agent je načítá přes odkazy níže. Načti relevantní `SKILL.md` podle úkolu:

- [gereq-php](skills/gereq-php/SKILL.md): backend, typování, PHPUnit a statická analýza.
- [gereq-modules](skills/gereq-modules/SKILL.md): tvorba a změny modulů, migrace, manifesty a verzování.
- [gereq-admin-ui](skills/gereq-admin-ui/SKILL.md): Twig, Alpine CSP, formuláře, design systém a assets.
- [gereq-publishing](skills/gereq-publishing/SKILL.md): staging veřejného obsahu a oddělení pracovních a produkčních dat.
