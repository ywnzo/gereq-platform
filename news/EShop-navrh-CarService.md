# Návrh: modul CarService

> Generický **autoservis / dílna** jako služba závislá na Fleet, Customers, Media, Documents (+ Calendar).
> Pokrývá to, co RPS dělá v `Service/` + QR tracker — bez vazby na pojišťovny.

---

## 1. Účel

Provozní modul pro **servisní zakázky** na vozidlech: příjem, práce (jobs), týmy (karosárna ↔ lakovna), média fází, dokončení, zakázkový list, náklady.

Použitelný:

- standalone autoservis (bez claims),
- nebo jako backend dílny pro **Claims** (RPS-like).

| Capability | `carservice` |
|---|---|
| Module id | `carservice` |
| Hard deps | `fleet`, `customers` (contacts) |
| Soft deps | `media`, `documents`, `calendar`, `idoklad` / `moneys3` |

---

## 2. Závislosti (jak skládat)

```
Customers ──► majitel / kontakt na zakázce
Fleet     ──► vehicleId (vlastní i cizí vůz)
Media     ──► fáze: before / progress / paint / after
Documents ──► zakázkový list PDF (šablona + DocumentLink)
Calendar  ──► termín příjmu / předání (volitelně)
iDoklad   ──► fakturace dokončené zakázky (volitelně)
Claims    ──► volitelný parent link claimId (Claims volá CarService, ne naopak hard)
```

**Pravidlo směru závislostí:** CarService **nezná** Claims. Claims (nebo admin) vytvoří zakázku přes capability a uloží si `serviceOrderId`.

Stejný pattern jako Projects ↔ Customers: CarService přidá sekci na detail vozidla / zákazníka přes hook.

---

## 3. Doménové schopnosti

### 3.1 Zakázka (`ServiceOrder`)

| Pole / koncept | Popis |
|---|---|
| Typ | `internal_fleet` / `external_customer` / `linked` (link drží Claims) |
| Status | `intake` → `in_progress` → `waiting` → `ready` → `done` / `cancelled` |
| Tým | `body_shop` / `paint_shop` (+ historie přepnutí) |
| Vazby | `vehicleId`, `customerId?`, `branchId?` |
| Tracker | QR token / kód ze SPZ |

Konfigurovatelné statusy a týmy přes settings (ne hardcoded RPS enumy v kódu).

### 3.2 Jobs

- Start / stop práce, typ, poznámka, zaměstnanec
- Soft limit délky směny (settings, default ~8 h)
- Aggregate času na zakázku

### 3.3 Tracker (mobil)

- Scan QR nebo SPZ → otevře aktivní zakázku
- Workflow: jobs + upload médií fáze
- API scopes: `carservice.tracker`

### 3.4 Dokončení

- Finish → generuj zakázkový list (Documents)
- Event `carservice.order_finished` → Claims / iDoklad listener
- Měsíční náklady dílny (`ServiceCost`) — provozní evidence, ne účetní doklad

### 3.5 Média checklist

- Typy fází konfigurovatelné
- Ukládá se přes Media capability (`entityType=carservice_order`)

---

## 4. Doménový model (návrh)

| Entita | Účel |
|---|---|
| `ServiceOrder` | zakázka |
| `ServiceJob` | úsek práce |
| `ServiceTeamSwitch` | historie body/paint |
| `ServiceCost` | měsíční náklady |
| `ServiceOrderLink` | volitelné vazby ven (`claim`, `rental`, …) bez hard FK |

---

## 5. Capability API (návrh)

```
CarServiceProvider
  createOrder(vehicleId, customerId?, meta): ServiceOrder
  getOrder(id): ServiceOrder
  listForVehicle(vehicleId): ServiceOrder[]
  listOpen(filters): ServiceOrder[]
  switchTeam(orderId, team): void
  addJob / completeJob
  finishOrder(orderId): void          // + document hook
  resolveByTrackerToken(token): ServiceOrder
```

**Platform events**

| Event | Kdy | Typičtí posluchači |
|---|---|---|
| `carservice.order_created` | nová zakázka | Notifications, Claims hub |
| `carservice.team_switched` | body↔paint | UI / stats |
| `carservice.order_finished` | dokončení | Claims, iDoklad, Documents |

---

## 6. Admin UI

| Stránka | Účel |
|---|---|
| Seznam zakázek | filtry: status, tým, pobočka, vozidlo |
| Detail zakázky | info, jobs, média, dokumenty, náklady |
| Tracker | mobilní UI (PWA / jednoduchý admin) |
| Náklady | měsíční přehled |
| Settings | týmy, statusy, QR, limity jobs |

**Hooks do cizích detailů**

- `fleet.vehicle.show.sections` → otevřené / historie zakázek
- `customers.show.sections` → servisní historie zákazníka
- `claims.show.sections` (registruje **Claims**, čte CarService capability)

---

## 7. API

| Scope | Akce |
|---|---|
| `carservice.read` | list/detail zakázek |
| `carservice.write` | CRUD, jobs, team switch, finish |
| `carservice.tracker` | resolve token, job start/stop, media upload |

---

## 8. Workflowy

### A) Standalone servis
Příjem vozu (Fleet ensure) → zakázka → jobs + média → finish → Document → (iDoklad).

### B) Servis z claimu (RPS-like)
Claims vytvoří order přes capability → tracker v dílně → finish event → Claims posune status „po servisu“.

### C) Interní fleet
Vůz `owned` → zakázka bez zákazníka → náklady interně.

---

## 9. Co CarService záměrně nedělá

| Věc | Kde patří |
|---|---|
| Pojistná událost, pojišťovna, report | **Claims** |
| Smlouva o půjčení náhradního vozu | **Rental** |
| Výplaty dealerů | **PartnerNetwork** |
| Generování PDF engine | **Documents** |
| Účetní řady / sync FV | **iDoklad** / **MoneyS3** |

---

## 10. Struktura kódu (EShop konvence)

```
modules/CarService/
├── module.php
├── CarServiceModule.php
├── Domain/           # ServiceOrder, ServiceJob, …
├── Application/      # CarServiceProvider, TrackerService, FinishOrder
├── Http/             # Admin + Api + Tracker controllers
├── Database/Migrations/
└── Resources/        # twig, translations, views tracker
```

Verze a migrace dle `module-updates` rule.

---

## 11. Shrnutí

**CarService = generická dílna.**  
Hard: Fleet + Customers. Soft: Media, Documents, Calendar, účetní konektory.  
Claims/Rental jsou **klienti** capability, ne součást modulu — díky tomu stejný CarService obslouží běžný autoservis i RPS-like likvidaci.
