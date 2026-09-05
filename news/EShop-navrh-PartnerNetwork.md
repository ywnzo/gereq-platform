# Návrh: modul PartnerNetwork

> Generická **partnerská / dealerská síť** s výkazy, komisemi a výplatami.  
> RPS dealeři = konfigurace tohoto modulu nad Claims (ne hardcode tipů nehod).

---

## 1. Účel

Evidence partnerů (obchodní zástupci), výkazy aktivit, pravidla odměn, měsíční výplaty, PDF výplatní doklad.

| Capability | `partners` |
|---|---|
| Module id | `partners` |
| Hard deps | `customers` |
| Soft deps | `claims`, `documents`, `idoklad` |

Partner = Customer (nebo vazba na Customer) + partnerský profil (sazby, tým, pobočka).

---

## 2. Závislosti

| Modul | Role |
|---|---|
| **Customers** | identita partnera / kontakt |
| **Claims** | zdroj událostí pro komisi (`claims.completed`) |
| **Documents** | výplatní lístek PDF |
| **iDoklad** | volitelně nákladový doklad / faktura partnera |

PartnerNetwork **nepočítá** servis ani půjčky — jen naslouchá business eventům (claims, případně jiné `commissionable` eventy).

---

## 3. Schopnosti

| Schopnost | Popis |
|---|---|
| Profil partnera | sazby, limity A/B/C, tandem, pobočka |
| Výkazy | typy konfigurovatelné (claim / leave / own…); approve / reject |
| Komise | pravidla: % / fix, kvartál, zdroj claimu |
| Výplaty | sestavení měsíce → save → publish → mark paid |
| Dokument | výplatní lístek přes Documents |

---

## 4. Model

| Entita | Účel |
|---|---|
| `Partner` | profil + customerId |
| `PartnerStatement` | výkaz |
| `PartnerPayout` | měsíční výplata |
| `CommissionRule` | pravidla |
| `CommissionEntry` | připsaná položka (claimId, amount) |

---

## 5. Capability API

```
PartnersProvider
  ensurePartner(customerId): Partner
  recordCommission(sourceType, sourceId, partnerId, amount)
  statements approve/reject
  buildMonthlyPayout(period)
  publishPayout / markPaid
```

Listener: `claims.completed` → spočti komisi dle ClaimSource + rules.

---

## 6. Admin

- Partneři, výkazy, výplaty, pravidla komisí
- Hook na Customer detail: partnerský profil
- Hook na Claim: info o komisi zdroje

---

## 7. Workflow

Výkazy → schválení → (auto) komise z completed claims → sestavení měsíce → publish → paid → Document.

---

## 8. Co modul nedělá

Claims CRUD, servis, fleet, účetní sync řad — jen finance partnerské sítě.
