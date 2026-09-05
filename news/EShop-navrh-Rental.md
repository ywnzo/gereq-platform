# Návrh: modul Rental

> Generická **půjčovna** (primárně vozidel z Fleet).  
> RPS „náhradní vůz k nehodě“ = use-case nad stejným modulem, ne samostatný monolit.

---

## 1. Účel

Rezervace a výpůjčky assetů z Fleet: dostupnost, schválení, smlouva / handover / takeover, uzavření, expirace rezervací.

| Capability | `rental` |
|---|---|
| Module id | `rental` |
| Hard deps | `fleet`, `customers` |
| Soft deps | `documents`, `calendar`, `media`, `idoklad`, `claims` (jen jako volitelný link) |

---

## 2. Závislosti

| Modul | Role |
|---|---|
| **Fleet** | vozidlo, status `rented` / `available` / `repair_wait` |
| **Customers** | nájemce |
| **Documents** | smlouva, protokol předání/převzetí, dodatek + podpisy |
| **Calendar** | blok termínu výpůjčky |
| **Media** | fotky stavu při handover/takeover |
| **Claims** | volitelný `link` typu claim — Rental Claims **neimportuje** |

Směr: Claims/Rental UI volá `RentalProvider::create(…, links: [claim => id])`. Rental emitne eventy; Claims poslouchá.

---

## 3. Schopnosti

| Schopnost | Popis |
|---|---|
| Typy | `standalone` (klient) / `replacement` (náhrada — meta link na claim) |
| Rezervace | dostupnost Fleet, default perioda/časy ve settings |
| Workflow | `draft` → `approved` → `active` → `closed` / `cancelled` |
| Dokumenty | smlouva → handover → (amendment) → takeover |
| Stav vozu po uzavření | zpět `available` / `repair_wait` / `in_service` (přes Fleet.setStatus) |
| Cron | expirace neschválených rezervací |

---

## 4. Model

| Entita | Účel |
|---|---|
| `Rental` | výpůjčka |
| `RentalDocumentRef` | odkaz na Document id + typ (contract/handover/…) |
| `RentalLink` | polymorfní vazba (`claim`, …) |

---

## 5. Capability API

```
RentalProvider
  availableVehicles(from, to, filters): Vehicle[]
  create(vehicleId, customerId, from, to, meta): Rental
  approve / cancel / close
  attachDocument(rentalId, documentId, type)
```

Eventy: `rental.created`, `rental.approved`, `rental.closed`, `rental.cancelled`.

---

## 6. Admin

- Seznam výpůjček, kalendář obsazenosti, detail (dokumenty, stav vozu)
- Settings: defaultní časy, expirace, povinné dokumenty
- Hook na Fleet detail: aktuální / historie půjček
- Hook na Claims detail: náhradní vůz (registruje Claims)

---

## 7. Workflowy

### A) Standalone
Dostupné vozy → create → approve → Documents smlouva+handover → close → Fleet status.

### B) Náhrada k claimu
Claims → create rental + link → stejný dokumentový tok → close → event zpět do Claims.

---

## 8. Co Rental nedělá

Pojišťovna, servisní jobs, dealer komise, účetní řady — jen event + Document/iDoklad napojení.
