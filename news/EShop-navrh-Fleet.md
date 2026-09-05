# Návrh: modul Fleet

> Generický **vozový park / evidence vozidel**.  
> Oddělený od servisu, půjčovny i claims — ty na něj jen odkazují.

---

## 1. Účel

Číselník a karty vozidel (vlastní fleet i cizí vozy klientů), značky/modely, technická data, stav (dostupný / v servisu / půjčený / mimo provoz).

Není e‑shop produkt — je to **provozní asset registry**.

| Capability | `fleet` |
|---|---|
| Module id | `fleet` |
| Soft deps | `media` |
| Hard deps | — |

---

## 2. Závislosti

| Modul | Jak se použije |
|---|---|
| **Media** | fotky vozu, ORV, STK scan (`entityType=fleet_vehicle`) |
| **Customers** | volitelný odkaz „vlastník“ (cizí vůz klienta) |
| *(externí)* | dataovozidlech.cz / ORV lookup — adapter v Application, ne jádro |

**Kdo závisí na Fleet:** CarService, Rental, Claims.

---

## 3. Schopnosti

- CRUD vozidla: SPZ, VIN, značka/model, rok, barva, palivo, km
- Číselník značek / modelů
- Stav provozní: `available` / `rented` / `in_service` / `repair_wait` / `out_of_service`
- Flag `owned` (náš fleet) vs `external` (klientův vůz)
- STK / dálniční známka / pojištění — metadata + připomínky (event do Calendar volitelně)
- Historie změn stavu (pro Rental/CarService)

---

## 4. Doménový model (návrh)

| Entita | Účel |
|---|---|
| `Vehicle` | karta vozu |
| `VehicleBrand` / `VehicleModel` | číselník |
| `VehicleStatusLog` | historie stavů |

Vazby ven: jen `customerId?`, žádné FK na service/rental/claim.

---

## 5. Capability API (návrh)

```
FleetProvider / FleetService
  find(id): Vehicle
  findByPlate(spz): ?Vehicle
  list(filters): Vehicle[]
  setStatus(id, status, reason?): void
  ensureForCustomer(customerId, plateData): Vehicle  // cizí vůz
```

Eventy: `fleet.vehicle_created`, `fleet.vehicle_status_changed`.

---

## 6. Admin / API

| Plocha | Obsah |
|---|---|
| Menu | Vozidla (skupina Provoz) |
| Detail | info, média, stav, odkazy „otevřené zakázky/půjčky/claims“ přes hooks |
| API | `fleet.read` / `fleet.write` — CRUD + status |

---

## 7. Co zůstane mimo Fleet

- Zakázky dílny → **CarService**
- Rezervace / smlouvy půjčky → **Rental**
- Nehoda / pojišťovna → **Claims**
