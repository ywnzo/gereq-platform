---
name: gereq-modules
description: Tvorba a úpravy business modulů GEREQ včetně contracts, registrace, migrací, permissions a konzistentního verzování submodules.
---

# Změny modulů GEREQ

Přečti kořenový `AGENTS.md`, `docs/development/creating-a-module.md`, `docs/architecture/module-system.md`, `.cursor/rules/module-updates.mdc` a README dotčeného modulu.

- Zkontroluj stav platformy i modulu (`git -C modules/<Name> status --short`). Zachovej uživatelské změny a práci nerozšiřuj na ostatní submodules.
- Prozkoumej manifest `module.php`, module class, služby a testy. Obvyklé vrstvy jsou Domain, Application, Infrastructure, Http, Admin, Database/Migrations, Resources a Tests.
- Business logika patří do modulu. Cizí data získávej přes contracts/providers/capabilities, ne cizí tabulky. Zachovej dependency declarations a možnost vypnutí modulu.
- Pro scaffolding lze použít `php gereq make:module <Name>` nebo `./bin/grq modules create <Name>`; ověř lokální help. Remote repo není automatickou součástí lokálního scaffoldingu.
- V register zachovej služby, routes, backend permissions a validaci; dle účelu také API scopes, event registry a publishables. Boot využívá existující menu/hooks/widgets patterns.
- API používá serializéry a whitelist filtrů; event payloady jsou verzovaná data bez Doctrine entit. Kritické finanční POST zachovávají idempotency a audit.
- Schema/datové upgrady patří do Database/Migrations s up/down. Neopravuj schema při requestu. Popiš dopad nevratného rollbacku.
- Kód/migrace/UI vyžadují novou shodnou verzi v module.php a Module::version(): patch pro opravu, minor pro funkci/migraci, major pro breaking change. Obsah vydané verze je immutable.
- Admin update přes ModuleCurrentMiddleware aplikuje migrace a sync permissions. Samotné `php gereq migrate` jej nenahrazuje.

Spusť `php bin/gereq-test module:<id>` a `php bin/gereq-test modules --filter=ModuleManifestConsistency`, příslušné PHP analýzy a pro UI frontend kontroly.

Zkontroluj diff uvnitř modulu i platformy. Předání uvádí verzi a potřebu admin update. Kód se commituje v module repo, platforma sleduje pin. Release tag odpovídá vX.Y.Z; bump sám neautorizuje commit, tag, push ani release.
