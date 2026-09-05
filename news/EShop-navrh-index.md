# EShop — generické moduly pro RPS-like provoz

> Návrh: jak rozložit monolitiní `_rpsCzech` na **generické služby** v EShop
> (capabilities + závislosti), ne Athena mapa.
>
> Inspirace domény: [RPS-katalog-schopnosti.md](RPS-katalog-schopnosti.md).
> Cíl: stejný provoz (nehody → servis → půjčka → fakturace) bez klientského monolitů.

---

## 1. Princip

RPS dnes = jeden plugin se vším. U nás:

1. **Existující platforma** dělá lidi, média, dokumenty, kalendář, účetní konektory.
2. **Nové generické moduly** dělají doménu (fleet, servis, půjčka, claims, partneři).
3. Doménové moduly spolu mluví přes **capabilities** a **platform events**, ne přes hard kopírování RPS tabulek.
4. Klient „RPS“ = zapnutá sada modulů + config (statusy, typy pojištění, komise) — ne nový `_rpsCzech`.

```
Customers  Media  Documents  Calendar  Notifications  iDoklad / MoneyS3
     \       |        |          |            |              /
      \______|________|__________|____________|_____________/
                           ↑ závisí
        Fleet ← CarService ← Claims
          ↑         ↑           ↑
        Rental ─────┴───────────┘
                    ↑
              PartnerNetwork (dealeři / komise)
```

---

## 2. Mapa RPS → EShop

| RPS doména | Generický modul u nás | Opírá se o |
|---|---|---|
| Vozidla / fleet / značky | **Fleet** | Media |
| Autoservis / lakovna / QR tracker | **CarService** | Fleet, Customers, Media, Documents, Calendar |
| Půjčovna náhradních vozů | **Rental** | Fleet, Customers, Documents, Calendar, (Claims volitelně) |
| Pojistné události | **Claims** | Customers, Fleet, CarService, Rental, Media, Documents, iDoklad |
| Dealeři / výkazy / výplaty | **PartnerNetwork** | Customers, Claims, Documents |
| Pobočky / týmy / zaměstnanci | **Org** (tenký) nebo settings + Customers staff | — |
| Pojišťovny | číselník v **Claims** | — |
| Účetnictví | existující **iDoklad** / **MoneyS3** (+ Documents) | Orders/Claims jako zdroj dokladu |

---

## 3. Katalogy návrhů

| Soubor | Modul | Capability |
|---|---|---|
| [EShop-navrh-Fleet.md](EShop-navrh-Fleet.md) | `fleet` | `fleet` |
| [EShop-navrh-CarService.md](EShop-navrh-CarService.md) | `carservice` | `carservice` |
| [EShop-navrh-Rental.md](EShop-navrh-Rental.md) | `rental` | `rental` |
| [EShop-navrh-Claims.md](EShop-navrh-Claims.md) | `claims` | `claims` |
| [EShop-navrh-PartnerNetwork.md](EShop-navrh-PartnerNetwork.md) | `partners` | `partners` |

---

## 4. Pravidla skládání (EShop styl)

Stejně jako `Projects` / `Documents` / `iDoklad`:

- `modules/<Id>/` — `Domain/`, `Application/`, `Http/`, `Database/Migrations/`, `Resources/`
- `capabilities()->register('carservice', …)` — veřejná služba pro ostatní moduly
- Soft dep: pokud capability chybí, UI sekce/hook se neregistruje (jako `customers.show.sections`)
- Hard dep: deklarovat v `module.php` / boot check jen u věcí bez kterých modul dává smysl (`carservice` → `fleet` + `customers`)
- Entity linky: `entityType` + `entityId` (documents, media, calendar) — ne FK do cizích tabulek napříč moduly
- Eventy: `carservice.job_finished`, `rental.closed`, `claims.completed` → webhooks / iDoklad sync / partner komise
- Verze: bump `module.php` + `version()` při každé změně schématu

---

## 5. Minimální sada pro „RPS-like“ tenanta

**Povinné:** Customers, Media, Documents, Fleet, CarService, Claims, iDoklad  
**Typicky:** Rental, Calendar, Notifications, PartnerNetwork, MoneyS3  
**Volitelné:** CRM (obchod), Projects (interní úkoly mimo dílnu), Org

---

## 6. Co záměrně nekopírujeme z RPS

- Jednu DB prefixovou hromadu `rps-*`
- Vlastní user/employee stack (→ Customers + platform auth / staff)
- Vlastní účetní CRUD (→ iDoklad/MoneyS3 + Documents)
- Monolitické admin stránky „vše v detailu nehody“ — místo toho **hub claim** skládá sekce přes hooks z CarService / Rental / Documents
