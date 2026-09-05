# RPS Czech — katalog schopností

> Generický přehled toho, co modul `_rpsCzech` pokrývá.
> Zdroj: kód modulu (v1.22.7). Technické detaily tříd viz ostatní soubory v `docs/`.

---

## 1. Co RPS je

RPS Czech je provozní systém pro firmu, která řeší **pojistné události (nehody)**, **náhradní vozidla**, **autoservis / lakovnu**, **obchodní zástupce (dealery)** a **účetnictví** napojené na české systémy.

Není to e‑shop modul — jde o **ERP/CRM pro likvidaci nehod a související provoz**.

| Doména | Co systém umí |
|---|---|
| Pojistné události | Příjem, evidence, statusy, dokumenty, média, komunikace s pojišťovnou, dokončení |
| Půjčovna | Náhradní vozy k nehodě i samostatný pronájem klientovi |
| Servis | Zakázky, QR tracker, karosárna ↔ lakovna, náklady, zakázkové listy |
| Dealeři | Výjezdy / výkazy, odměny, měsíční výplaty, komise zdrojů |
| Účetnictví | Faktury, dobropisy, pokladna; iDoklad + MoneyS3 |
| Organizace | Pobočky, týmy, zaměstnanci, klienti FO/PO, pojišťovny, fleet |

**Závislosti:** povinný modul `iDoklad`.  
**Média:** `rpsBranch`, `rpsCar`, `rpsAccident`, `rpsService`.

---

## 2. Struktura produktu (admin)

### 2.1 Hlavní menu

| Oblast | Stránky | Účel |
|---|---|---|
| Nehody | `rps-accidents` | Seznam pojistných událostí (filtry: status, pojišťovna, typ pojištění, pobočka, servis) |
| Vozidla | `rps-cars`, `rps-carRental` | Fleet + půjčovna |
| Dealeři | `rps-dealers`, `rps-dealersRewards`, `rps-dealerPayments` | Obchodní síť, odměny, výplaty |
| Pobočky | `rps-branches` | Organizační jednotky (head page) |
| Pojišťovny | `rps-insurances` | Číselník pojišťoven |
| Účetnictví | `rps-accounting` | Účetní doklady (iDoklad / MoneyS3) |
| Lidé | `rps-users`, `rps-employees` | Klienti a zaměstnanci |
| Servis | `rps-services`, `rps-serviceTracker` | Zakázky + mobilní tracker |
| Finance | `rps-ftData` | Finanční přehled (FV06 — daně, pojištění, úhrady) |
| Nastavení | `rps-systemConfig` | Firma, config, napojení (pod Settings, restricted) |

### 2.2 Detaily a podstránky

| Stránka | Účel |
|---|---|
| `rps-accidentDetail` | Detail nehody — info, majitel, vůz, soubory, fotky, komunikace, interní, servis, půjčka, účetnictví, dokončení |
| `rps-accidentSources` | Číselník zdrojů nehod (TIP, servis, pojišťovna…) |
| `rps-carDetail` / `rps-carLists` | Detail vozu / značky a modely |
| `rps-dealerDetail` | Detail dealera |
| `rps-dealerStatements` | Výkazy dealera (čeká / schváleno / zamítnuto) |
| `rps-monthlyReports` | Měsíční reporty dealera |
| `rps-paymentDetail` | Detail výplaty (uložení → publikace → úhrada) |
| `rps-branch` / `rps-branchTeams` | Detail pobočky / týmy + limity |
| `rps-userDetail` / `rps-employee` | Klient (+ ARES, iDoklad) / zaměstnanec (+ podpis) |
| `rps-insuranceDetail` | Detail pojišťovny |
| `rps-accountingDocument` | Detail účetního dokladu |
| `rps-serviceDetail` / `rps-serviceCosts` | Detail servisu / měsíční náklady |
| `rps-groups` | Skupiny (stránka existuje mimo menu config) |

---

## 3. Doménové schopnosti

### 3.1 Pojistné události (nehody)

**Životní cyklus statusů**

1. Čeká na data (`waitingOnData`)
2. Před servisem → v servisu → v lakovně
3. Před předáním → připraveno k předání
4. Dokončeno (`isFinished`)  
   Alternativa: totální škoda (`totalCrash`)

**Zdroje nehody:** osobní, servis, SMS, tip, odtah, klient, pojišťovna.  
**Důvody odmítnutí:** operativní leasing, firemní vůz, cizinec, no-go, nenalezeno, jiné.  
**Typy pojištění:** POV / HAV / HOT.  
**Způsob výplaty:** účet / adresa / faktura.  
**Stav platby:** neuhrazeno / uhrazeno / zkráceno.

**Evidence u nehody**

- Majitel (FO/PO), řidič, vůz, pojištění
- Checklist médií (OP, TP, protokol, fotky nehody/vozu, plná moc, devinculace, leasing…)
- Interní / servisní / komunikační soubory
- Napojení na servis, půjčku a účetní doklady
- Komise zdroje (online / hotovost)
- Dokončení → report pojišťovně (+ cron doručení)

**Číslování smluv:** `{YYYY}{NNNN}` přes konfiguraci `accidentNumbering`.

---

### 3.2 Půjčovna náhradních vozidel

| Schopnost | Popis |
|---|---|
| Typy půjčky | K nehodě (`accident`) / samostatně klientovi (`user`) |
| Rezervace | Dostupnost vozů, výchozí perioda 7 dní, časy 08:00–16:30 |
| Schvalování | Create → approve / cancel / close |
| Dokumenty | Smlouva → protokol předání → (dodatek) → protokol převzetí + podpisy |
| Stav vozu po půjčce | Povolen / čeká na opravu / v opravě / technický výpadek |
| Automatika | Cron expiruje neplatné rezervace |

---

### 3.3 Autoservis / lakovna

| Schopnost | Popis |
|---|---|
| Zakázky | Interní vůz / externí vůz / vázané na nehodu |
| Tracker | QR kód nebo SPZ → mobilní workflow zaměstnance |
| Jobs | Start/konec práce, poznámka, typ; limit ~8 h |
| Týmy | `bodyShop` ↔ `paintShop` + historie přepnutí |
| Média fází | před / průběh / lakovna / po |
| Dokončení | Finish + zakázkový list + napojení na účetnictví |
| Náklady | Měsíční servisní náklady |

---

### 3.4 Dealeři a odměny

| Schopnost | Popis |
|---|---|
| Evidence | Dealer, tandem, napojení na pobočku/tým |
| Výkazy | Typy: nehoda / dovolená / vlastní; schválení / zamítnutí |
| Statistiky | Dashboard, měsíční reporty, nehody dealera |
| Komise | Osobní + kvartální % dle limitů (A/B/C) |
| Výplaty | Sestavení měsíce → uložení → publikace → označení uhrazeno |
| Dokument | Výplatní lístek dealera (PDF) |

---

### 3.5 Účetnictví

| Schopnost | Popis |
|---|---|
| Doklady | Faktury (`FV*`), dobropisy (`OD*`), pokladna (`PP*`, `SP*`) |
| Služby na dokladu | Půjčovné, km, parkování, servis, spoluúčast |
| DPH | 21 / 12 / 0 |
| Platba | Banka / hotovost |
| Řady a střediska | Lokální + sync z MoneyS3 |
| Export | Sync do iDoklad a/nebo MoneyS3 |
| FT data | Roční finanční indexy (FV06) |

---

### 3.6 Organizace a číselníky

- **Pobočky** — zaměstnanci, dealeři, vozy, týmy (morava / budejice / brno) + limity
- **Zaměstnanci** — pozice (`owner` / `accountant` / `employee`), skupiny (`service` / `paintShop` / `carRental` / `onBranch`), elektronický podpis
- **Klienti** — FO / PO, rodné číslo / IČO, ARES, iDoklad kontakt
- **Účty** — přihlášení pro user / dealer / employee
- **Vozidla** — fleet, značky/modely, STK, dálniční známka, technická data (dataovozidlech.cz)
- **Pojišťovny** — číselník + reporty

---

## 4. Dokumenty (PDF)

Generované přes mPDF ze šablon `includes/documents/*.blade.php`:

| Dokument | Kontext |
|---|---|
| Smlouva o půjčení | Půjčovna |
| Protokol předání / převzetí | Handover / takeover + stav vozu |
| Dodatek smlouvy | Změna půjčky |
| Plná moc | Nehoda |
| Doplňující informace k nehodě | Nehoda |
| Leták k nehodě | Nehoda |
| Zakázkový list servisu | Servis |
| Výplatní lístek dealera | Výplata |

Podpora digitálního podpisu (signature pad) u relevantních dokumentů.

---

## 5. Integrace

| Systém | Účel |
|---|---|
| **iDoklad** | Kontakty, FV, dobropisy, pokladní doklady, PDF reporty, číselné řady |
| **MoneyS3** | GraphQL — agendy, střediska, řady, export FV a pokladny |
| **ARES** | Načtení subjektu podle IČO |
| **dataovozidlech.cz** | Technická data vozidla podle ORV |
| **QR kódy** | Identifikace servisní zakázky ve trackeru |
| **mPDF** | Render PDF |
| **Brevo** | Plánované doručení reportů pojišťovně (v cronu jako TODO) |

---

## 6. API (mobilní / externí)

Autorizované endpointy pod `/_rpsCzech/…`:

| Skupina | Akce |
|---|---|
| Účet | `user` |
| Nehody | CRUD `accidents`, `accidentsWaiting`, `accidentDetail`, media upload/delete, dokumenty |
| Registry | Statusy, zdroje, rejecty, pojišťovny, pobočky, media checklist, značky… |
| Externí data | `subjectAres`, `carTechnicalData` |
| Půjčovna | Dostupné vozy, CRUD `carRentals`, dokumenty, info o voze |
| Dealeři | Výkazy, zamítnutí, statistiky, odměny, výplaty, podpis |
| Dashboard | `dashboardStatistics`, `dealerStatistics`, `dealerAccidentsData` |

Chybové kódy API: `400`–`430` (not found, create/update, media, rental, statement, insurance…).

---

## 7. Automatizace (cron)

| Job | Účel |
|---|---|
| `dailyRoutine` | Denní spouštěč |
| `_expireCarRentals` | Expirace neplatných rezervací půjčovny (self-reschedule ~30 min) |
| `_accidentCompletionReportDelivery` | Doručení reportu pojišťovně (retry, max 3×) |

---

## 8. Datový model (tabulky)

| Oblast | Tabulky |
|---|---|
| Organizace | `rps-branches`, `rps-branchEmployees`, `rps-branchDealers`, `rps-branchTeams` |
| Lidé | `rps-employees`, `rps-employeeGroups`, `rps-users`, `rps-accounts`, `rps-dealers` |
| Vozidla | `rps-cars`, `rps-carBrands`, `rps-carModels` |
| Nehody | `rps-accidents`, `rps-accidentsData`, `rps-accidentCars`, `rps-accidentStatuses`, `rps-accidentsSources`, `rps-accidentReports` |
| Pojištění | `rps-insuranceCompanies` |
| Půjčovna | `rps-carRental`, `rps-carRentalDocuments` |
| Účetnictví | `rps-accounting`, `rps-accountingSeries`, `rps-accountingDocuments` |
| Dealeři finance | `rps-dealerStatements`, `rps-dealerPayments` |
| Servis | `rps-service`, `rps-serviceJobs`, `rps-serviceTypes`, `rps-serviceTeamHistory`, `rps-serviceCosts` |

**FE entity (API schema):** `EntityRpsFeAccident`, `EntityRpsFeCarRental`, `EntityRpsFeUser`, `EntityRpsEmployee`.

---

## 9. Architektura kódu (mapa)

```
_rpsCzech/
├── config.php              # menu, tabulky, media, verze
├── rps-*.php               # admin stránky (~32)
├── class/
│   ├── Controller.php      # základ: org, lidé, účty
│   ├── Processor.php       # UI helpery, ARES, účty
│   ├── DataFactory.php     # build/compile formulářů → DB
│   ├── Accidents/          # nehody
│   ├── CarRental/          # půjčovna
│   ├── Cars/               # fleet
│   ├── Service/            # servis + QR
│   ├── Accounting/         # doklady
│   ├── Insurances/         # pojišťovny
│   ├── Statistics/         # statistiky, komise, výplaty
│   ├── Documents/          # PDF generování
│   ├── iDoklad.php         # wrapper iDoklad
│   └── MoneyS3.php         # wrapper MoneyS3
├── api/                    # REST endpointy
├── requests/               # ~45 AJAX endpointů adminu
├── cron/                   # denní routine + expirace + reporty
├── models/                 # FE entity
├── includes/               # dialogy, dokumenty, admin partials, module boxes
├── js/backend/             # rental, documents, payments, service, statistics, accounting, MoneyS3
├── migrations/             # ~70 SQL migrací
└── docs/                   # technická dokumentace tříd
```

### AJAX oblasti (`requests/`)

- Nehody: completion check/push, dokumenty, změna typu souboru, komise zdroje  
- Půjčovna: create / approve / cancel / close / update / note / sign / data  
- Servis: tracker, jobs CRUD, team switch, QR/ID decode, finish, assign to accident  
- Dealeři / platby: statements, reject, refresh stats, save/publish/pay payment data  
- Účetnictví: iDoklad kontakt/doklad, MoneyS3 sync, credited invoice  
- Obecné: ARES, media delete, generate/open document, signatures  

---

## 10. Klíčové workflowy (end-to-end)

### A) Pojistná událost
Vytvoření → doplnění dat (majitel, vůz, pojištění) → media checklist → statusy přes servis/lakovnu → napojení půjčky a dokladů → completion → report pojišťovně → komise zdroje.

### B) Servis tracker
Zakázka (vůz / nehoda) → QR → jobs + média → switch bodyShop/paintShop → finish → zakázkový list → účetnictví.

### C) Půjčovna
Dostupné vozy → create → approve → smlouva + handover → (dodatek) → takeover → close; cron expiruje rezervace.

### D) Výplata dealera
Výkazy → statistiky/komise → sestavení měsíce → save → publish → mark paid → výplatní lístek.

### E) Účetní doklad
Vazba na nehodu/servis/půjčku → iDoklad sync → MoneyS3 export → evidence ve FT datech.

---

## 11. Shrnutí rozsahu

| Metrika | Počet (orientačně) |
|---|---|
| Admin stránky | ~32 |
| DB tabulky | ~30 |
| AJAX requesty | ~45 |
| REST API akce | ~20 skupin |
| Typy PDF dokumentů | 8 |
| Doménové třídy | 15+ |
| SQL migrace | ~70 |
| Integrace | iDoklad, MoneyS3, ARES, dataovozidlech, QR, mPDF |

**Jádro hodnoty RPS:** jeden systém od nahlášení nehody přes servis a náhradní vůz až po fakturaci, výplaty dealerů a report pojišťovně.

---

## Související dokumentace

- Integrace (technické API): [iDoklad.md](iDoklad.md), [MoneyS3.md](MoneyS3.md)
- **Návrh generické stavby u nás (EShop):** [EShop-navrh-index.md](EShop-navrh-index.md)
  - [Fleet](EShop-navrh-Fleet.md) · [CarService](EShop-navrh-CarService.md) · [Rental](EShop-navrh-Rental.md) · [Claims](EShop-navrh-Claims.md) · [PartnerNetwork](EShop-navrh-PartnerNetwork.md)
