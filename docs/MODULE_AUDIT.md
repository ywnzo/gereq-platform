# Audit modulů GEREQ / RealEstates

Datum: 2026-09-28  
Rozsah: **47** modulů v `modules/` (git submodules). Unit testy business logiky žijí v `modules/*/Tests/` (ne v `tests/Unit/Modules/` — tam zůstávají jen platformní guardraily ModuleStructure).

## Shrnutí

- Od auditu 2026-09-07 je základní pokrytí **doplněné**: 46/47 modulů má aspoň jeden `*Test.php` v `Tests/` (**57** testovacích tříd). Chybí jen **WebPlusAi**.
- Platforma drží strukturální guardraily: `CodingConventionsTest`, `ModuleManifestConsistencyTest`, `QueryHygieneTest`.
- Business logika je stále převážně v **Application**; Domain entity mají už víc metod (status, totals, normalize), ale často zůstávají anémické mimo otestované čisté kusy.
- Peníze: Currency má `MoneyValue` (formátování/konverze), ale Orders / Shop / Coupons / Bundles / Warehouse dál často `(float)` + `number_format` — riziko zaokrouhlení trvá.
- Nové balíčky oproti minulému auditu: **Gereq**, **Forum** (+ Achievements, Community, Messenger), **Ai**, **Emailing**, **Engage**, **WebPlus** / **WebPlusAi**.

## Priorita zlepšení (napříč platformou)

1. **PricingEngine** — sjednotit ProductDiscounts, Coupons, Bundles, Shop cart totals, option/tier ceny (testy existují po částech, ne jako společný engine).
2. **Money VO / BCMath end-to-end** — rozšířit `MoneyValue` (nebo ekvivalent) i mimo Currency; nahradit float v Orders/Warehouse/discounts.
3. **State machines** — Orders, ESign, Returns, Viewings, CRM Deal stages (částečně otestované přechody, chybí enforce / invalid transition).
4. **CarService ↔ BikeService** — sdílený Workshop kernel (jobs, finish, docs).
5. **Integrační mappery** — iDoklad (zatím jen Link), MoneyS3 (query builder), Portals, Feeds: plné mapování + HTTP mocky.
6. **WebPlusAi** — založit `Tests/` (aspoň smoke / domain helpers, ať neporušuje konvenci „modul = Tests/“).
7. **Integrační suite** — `tests/Integration` je stále prázdný (SQLite in-memory + migrate).

---

## Katalog modulů

| Modul | Stav testů | Co zlepšit |
|-------|------------|------------|
| **Payments** | SpaydBuilder | Abstrahovat ImapBankMatcher I/O; gateway contract testy |
| **Listings** | GeoDistance | Money/GeoPoint VO; zmenšit anémický Listing |
| **Orders** | OrderLine, totals, draft edit, Invoice | Enforce status machine; shipping do totals |
| **ProductDiscounts** | active window, applyCatalogDiscount | pickBest/matching jako Domain service; ne float |
| **Coupons** | validateForCart (provider) | `Coupon::isValidAt($now)`; sjednotit s discounts |
| **Currency** | Currency + identity convert | Cross/inverse s fake rate repo; MoneyValue round-trip |
| **ESign** | SigningLink, SignableDocument | Oddělit orchestration od domain přechodů |
| **Warehouse** | StockItem | Pure StockLedger z StockService; BCMath qty |
| **Products** | OptionValue, QuantityPriceTier | Sjednotit s PricingEngine |
| **Loyalty** | Account + tierForSpend | Konfigurovatelné earn pravidlo (ne 1:1 floor) |
| **Shop** | Cart open/converted/abandoned | Extrahovat totalsFromItems jako public pure API |
| **CarService** | ServiceJob complete/duration | Sdílený Workshop; zmenšit controllery |
| **BikeService** | BikeJob duration | Stejně jako CarService |
| **Reviews** | approve/reject/helpful | API integrační testy |
| **CRM** | Activity, Deal stages | Pipeline: validace / forbidden přechody |
| **Viewings** | setStatus | Explicitní allowed transitions matrix |
| **Feeds** | GoogleMerchantDriver | Contract testy pro všechny driverry |
| **Portals** | SrealityAdvertMapper | Fixture listing → advert_data snapshot |
| **Vitality** | normalizePath, sumQueryMs | Oddělit metrics od HTML badge |
| **Webhooks** | matchesEvent | Retry/backoff extrahovat z jobu |
| **Bundles** | code/discountType/min-max | Výpočet ceny balíčku + checkout |
| **Customers** | archive/status, initials, tag slug | Lifecycle event publishing |
| **Returns** | status/resolution | Enforce resolution pravidla |
| **Cms** | Page slug/publish/status | Builder / block smoke |
| **Media** | Domain + API contract + events | OK baseline; upload I/O mocky |
| **Documents** | mime/icon helpers | Entity-link smoke |
| **Newsletter** | Subscriber + Campaign | Delivery job mock |
| **Notifications** | preference round-trip | Bridge / channel unit testy |
| **Translate** | TranslationCache hash | Provider HTTP mock |
| **CustomerUploads** | link/consent/meta | Retention policy pure test |
| **Calendar** | Connection sync/error | External provider mock |
| **Projects** | status validation | Task transitions |
| **Fleet** | VehicleGeneration + plate | ORV / deadline mocky |
| **PartsCatalog** | OrderPart line total, leaf slug | Driver interface + DTO |
| **SearchEngines** | OEM normalize, CZ price parse | Driver contract suite |
| **iDoklad** | iDokladLink meta | Pure invoice mapper + mocked HTTP |
| **MoneyS3** | GraphQL query/mutation builder | Mutation payload snapshots + HTTP mock |
| **Ai** | access, chunker, embeddings, tools, history (7 tříd) | Provider HTTP fakes; RAG end-to-end |
| **Emailing** | ImapKeyword, MailThreadResolver | Rule engine; sync job mock |
| **Engage** | Campaign normalize/touchSeen | Spot targeting pure rules |
| **WebPlus** | PathPattern, MetaHtml, UrlTemplateDiscoverer | Sitemap / health smoke |
| **WebPlusAi** | — (bez `Tests/`) | Založit Tests/; AI SEO helpers |
| **Gereq** | credentials, origin, security site, ticket | API auth flow |
| **Forum** | VoteTally | Thread/post domain |
| **Achievements** | reputation tiers, badge threshold | Award rules |
| **Community** | Friendship accept/block | Privacy / blocklist |
| **Messenger** | participant folder/read | Thread membership |

## Doporučené další kroky

1. Doplnit `modules/WebPlusAi/Tests/` a smazat zbytečné prázdné `.gitkeep` tam, kde už jsou testy (např. Customers).
2. Integrační suite (`tests/Integration`) se SQLite in-memory + migrate.
3. Coverage gate v CI pro `modules/*/Application` a Domain business metody.
4. Snapshot testy pro Feeds/Portals XML/RPC payloady.
5. Refaktor PricingEngine + Money VO napříč commerce s existujícími module testy jako safety net.
