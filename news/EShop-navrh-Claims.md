# Návrh: modul Claims

> Generické **pojistné události / likvidace**.  
> Orchestrátor RPS-like procesu: skládá Fleet + CarService + Rental + Documents + účetnictví.

---

## 1. Účel

Evidence claimu (nehody): účastníci, vůz, pojišťovna, statusy, media checklist, dokončení a report.  
**Nepřepisuje** dílnu ani půjčovnu — volá jejich capabilities a zobrazuje sekce na hub stránce.

| Capability | `claims` |
|---|---|
| Module id | `claims` |
| Hard deps | `customers`, `fleet` |
| Soft deps | `carservice`, `rental`, `media`, `documents`, `idoklad`, `moneys3`, `partners`, `notifications` |

---

## 2. Závislosti a orchestrace

```
                ┌── Media (checklist OP/TP/fotky…)
                ├── Documents (plná moc, report…)
Customers ──► Claims ──┬── Fleet (vůz / majitel)
                ├── CarService.createOrder  (když je opravárenství)
                ├── Rental.create           (náhradní vůz)
                ├── iDoklad / MoneyS3       (FV po dokončení)
                └── PartnerNetwork          (komise zdroje)
```

Claims je **jediné místo**, které zná pojišťovny, typy pojištění (POV/HAV/…), reject reasons a completion report — to je doména likvidace, ne servisu.

---

## 3. Schopnosti

### 3.1 Životní cyklus (konfigurovatelný)

Výchozí šablona (RPS-like, ale ve settings):

1. `waiting_on_data`
2. `before_service` → `in_service` → `in_paint`
3. `before_handover` → `ready`
4. `finished` | `total_loss` | `rejected`

Posuny statusů mohou reagovat na eventy:
- `carservice.order_finished` → návrh přechodu `in_service` → další
- `rental.closed` → UI badge / checklist položka

### 3.2 Evidence

- Majitel / řidič (Customers FO/PO, ARES přes Customers/adapter)
- Vůz (Fleet)
- Pojišťovna (číselník v Claims)
- Zdroj claimu (tip, servis, pojišťovna…) — pro PartnerNetwork komisi
- Media checklist (typy ve settings)
- Platba / výplata pojišťovny (stav: unpaid / paid / reduced)

### 3.3 Dokončení

- Completion check (povinná média / dokumenty)
- Report pojišťovně (Document + Notifications / cron retry)
- Event `claims.completed` → PartnerNetwork spočte komisi

---

## 4. Model

| Entita | Účel |
|---|---|
| `Claim` | událost |
| `ClaimParty` | role: owner / driver / … → customerId |
| `InsuranceCompany` | číselník |
| `ClaimSource` | číselník zdrojů |
| `ClaimStatusHistory` | audit |
| `ClaimChecklistItem` | media/doc požadavek + splnění |

Vazby na service/rental: `serviceOrderId?`, `rentalId?` (ID cizích modulů, bez ORM FK).

---

## 5. Capability API

```
ClaimsProvider
  create / update / get / list
  setStatus
  attachServiceOrder / attachRental
  completionCheck / complete
```

Eventy: `claims.created`, `claims.status_changed`, `claims.completed`, `claims.rejected`.

---

## 6. Admin = hub

Detail claimu skládá:

| Sekce | Zdroj |
|---|---|
| Info / status / pojišťovna | Claims |
| Majitel / řidič | Customers |
| Vůz | Fleet |
| Média checklist | Media + Claims checklist |
| Servis | CarService capability (hook) |
| Půjčka | Rental capability (hook) |
| Dokumenty | Documents |
| Účetnictví | iDoklad/MoneyS3 |
| Komise | PartnerNetwork |

Bez soft-dep sekce se prostě neukáže — claim zůstane použitelný i bez Rental.

---

## 7. Workflow (E2E RPS-like)

Vytvoření claimu → doplnění dat + checklist → (CarService order) → (Rental) → doklady → completion → report → komise partnera → iDoklad FV.

---

## 8. Co Claims nedělá

- Jobs / QR tracker → CarService  
- Smlouvy půjčky → Rental  
- Karty vozidel → Fleet  
- Výplatní lístky dealerů → PartnerNetwork  
