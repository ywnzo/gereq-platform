# Audit modulů GEREQ / RealEstates

Datum: 2026-09-07  
Rozsah: 37 modulů v `modules/` + pokrytí unit testy v `tests/Unit/Modules/`.

## Shrnutí

- Business logika je převážně v **Application** (providery, services); Domain entity jsou často anémické.
- Peníze jdou přes `(float)` + `number_format` — riziko zaokrouhlení.
- Před tímto auditem téměř žádné modulové testy (kromě Webhooks SSRF/signer a PDF renderu).
- Nové unit testy cílí na čisté výpočty a stavové přechody (vysoký ROI, bez DB).

## Priorita zlepšení (napříč platformou)

1. **PricingEngine** — sjednotit ProductDiscounts, Coupons, Bundles, Shop cart totals, option/tier ceny.
2. **Money value object / BCMath** — nahradit float aritmetiku.
3. **State machines** — Orders, ESign, Returns, Viewings (enforce přechody).
4. **CarService ↔ BikeService** — extrahovat sdílený Workshop kernel (jobs, finish, docs).
5. **Integrační mappery** — iDoklad, MoneyS3, Portals, Feeds: pure mapování + HTTP mocky.
6. **Prázdné `modules/*/Testing/`** — buď naplnit, nebo zrušit (false signal).

---

## Katalog modulů

| Modul | Stav testů | Co zlepšit |
|-------|------------|------------|
| **Payments** | SpaydBuilder | Abstrahovat ImapBankMatcher I/O; gateway contract testy |
| **Listings** | GeoDistance | Value objects Money/GeoPoint; zmenšit anémický Listing |
| **Orders** | OrderLine, OrderTotals, Order, Invoice | Enforce status machine; shipping do totals |
| **ProductDiscounts** | Domain + applyCatalogDiscount | pickBest/matching jako Domain service; ne float |
| **Coupons** | validateForCart | `Coupon::isValidAt($now)`; sjednotit s discounts |
| **Currency** | Currency + identity convert | Testovat cross/inverse s fake rate repo |
| **ESign** | SigningLink, SignableDocument | Oddělit orchestration od domain přechodů |
| **Warehouse** | StockItem | Extrahovat pure StockLedger z StockService |
| **Products** | OptionValue, QuantityPriceTier | Sjednotit s PricingEngine |
| **Loyalty** | Account + tierForSpend | Konfigurovatelné earn pravidlo (ne 1:1 floor) |
| **Shop** | Cart::isOpen | Extrahovat totalsFromItems jako public pure API |
| **CarService** | ServiceJob | Sdílený Workshop; zmenšit controllery |
| **BikeService** | BikeJob | Stejně jako CarService |
| **Reviews** | ProductReview | OK domain; přidat API integrační testy |
| **CRM** | Activity, Deal | OK; pipeline validace přechodů |
| **Viewings** | setStatus | Explicitní allowed transitions |
| **Feeds** | GoogleMerchantDriver | Contract testy pro všechny driverry |
| **Portals** | SrealityAdvertMapper | Fixture listing → advert_data snapshot |
| **Vitality** | normalizePath, sumQueryMs | Oddělit metrics od HTML badge |
| **Webhooks** | rozšíření matchesEvent | Retry/backoff extrahovat z jobu |
| **Bundles** | — | Doplnit výpočet ceny balíčku + checkout |
| **Customers** | — (prázdný Tests/) | Lifecycle metody + event publishing |
| **Returns** | — | Domain metody pro status/resolution |
| **Cms / Media / Documents / Newsletter / Notifications / Translate / CustomerUploads** | — | Málo pure logiky; integrační/smoke testy |
| **Calendar / Projects / Fleet** | — | Connection/sync stavy; Fleet ORV mocky |
| **PartsCatalog / SearchEngines** | — | Driver interface + DTO; SearchEngines Domain |
| **iDoklad / MoneyS3** | — | Pure mappery + mocked HTTP |
| **Notifications** | — (prázdný Tests/) | Bridge unit testy |

## Doporučené další kroky

1. Doplnit integrační suite (`tests/Integration`) se SQLite in-memory + migrate.
2. Coverage gate v CI pro `modules/*/Application` a Domain business metody.
3. Snapshot testy pro Feeds/Portals XML/RPC payloady.
4. Refaktor PricingEngine + Money VO s testy jako safety net.
