---
name: gereq-php
description: Úpravy PHP backendu a PHPUnit testů GEREQ Platform s projektovým typováním, testing enginem a statickou analýzou.
---

# PHP backend GEREQ

Přečti kořenový `AGENTS.md`, `docs/development/testing.md` a `docs/development/coding-standards.md`. Rozsah kontrol ověř v `composer.json`, `phpstan.neon`, `phpstan-tests.neon` a `phpcs.xml`.

- Zachovej PSR-4, strict types, typované signatury a array shapes. Preferuj constructor promotion a readonly dependencies, kde odpovídají životnímu cyklu služby. Doctrine entity musí zůstat vhodné pro proxy.
- Registrace služeb a backend patterns odvozuj ze sousedního kódu; platforma není Laravel ani Symfony full-stack.
- Komentáře a CLI help piš anglicky; UI a validační texty lokalizuj v cs/en.
- U queries prověř N+1 a query budget pomocí existujících query assertions.
- Nové chyby PHPStan oprav ve zdroji; baseline nerozšiřuj bez záměrného přijetí technického dluhu.

Testuj změněné chování a chybové stavy. Pro čistou doménovou logiku preferuj unit test bez DB; pro DB/HTTP chování použij odpovídající existující fixtures.

- Core testy: `tests/`, namespace `Gereq\Tests\…`.
- Module testy: `modules/<Name>/Tests/`, namespace `Modules\<Name>\Tests`.
- Test classes jsou final, mají strict types a PHPUnit 11 attributes. Preferuj `Gereq\Testing\TestCase`, zejména pro query assertions.
- Cílený běh: `php bin/gereq-test core --filter=ExampleTest` nebo `php bin/gereq-test module:<id>`. Dostupné cíle: `php bin/gereq-test --list`.
- Gate před commitem: `composer test`, `composer phpstan`, `composer phpstan:tests`, `composer cs`.

Reportuj skutečně provedené kontroly a odliš existující selhání od regresí.
