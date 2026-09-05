# Audit: GEREQ Platform vs. BE baby-naty.cz

| | |
|---|---|
| **Datum** | 5. 9. 2026 (P0→P1→P2 round) |
| **GEREQ** | `/home/dev/private/GEREQ_PLATFORM` · verze **0.1.0** |
| **Baby-Naty** | `/home/dev/projects/baby-naty.cz` · verze **1.80H** |
| **Otázka** | Může GEREQ nahradit backend baby-naty.cz? |

---

## Verdikt

**Commerce BE je připravený k dual-run** — P0–P2 foundation + adapters + admin/CLI jsou v kódu.

Stále **ne drop-in 1:1 bez práce**: potřebujete credentials (Comgate/Packeta/IMAP), FE napojení na cookie/Bearer, a ETL dat. Dompdf je v `vendor/`; fallback `SimpleInvoicePdf` zůstává.

| Scénář | Realita |
|---|---|
| Okamžitá náhrada BE 1:1 bez migrace | **Ne** (data + dual-run) |
| Headless BE pro BN-like shop | **Ano** — credentials + FE |
| Dual-run / hybrid | **Ano** — doporučeno |

---

## P0 (hotovo)

| Oblast | Implementace |
|---|---|
| PDF faktury | Dompdf pokud dostupný + `SimpleInvoicePdf` fallback |
| Store cookie bridge | `StoreCookieBridge` + logout; HttpOnly `gereq_store_token` |
| Variant admin UI | Tab Varianty na product show + CRUD routes |
| Laravel ETL scaffold | `php gereq migrate:import-laravel --file=dump.json` |

## P1 (hotovo)

| Oblast | Implementace |
|---|---|
| IMAP matching | settings + `payments:imap-match` |
| Order e-maily | `OrderMailer` na `order.confirmed` / `order.fulfilled` |
| Packeta admin | create/sync zásilky na order detail |
| Store rate limit | `api.rate_limit.store` pro `/store/*` a `/uploads/*` |
| Upload retention | `uploads:purge` |
| Smoke tests | `SimpleInvoicePdfTest`, `StoreCookieBridgeTest` |

## P2 (scaffolds hotovo)

| Oblast | Implementace |
|---|---|
| GoPay / Stripe | gateway classes + settings |
| PPL | `PplCarrier` + setting |
| OpenAPI | stávající `/api/openapi.json` (generátor) |
| CSV import | `products:import-csv` |

---

## Ops checklist cutover

1. Nastavit Comgate / Packeta / (volitelně) IMAP / GoPay / Stripe credentials.
2. FE: cookie `gereq_store_token` nebo Bearer z login response.
3. `migrate:import-laravel` / `products:import-csv` + ruční ověření objednávek.
4. Dual-run: platba → invoice PDF → Packeta label → upload link.

---

*P0–P2 kódová vlna dokončena. Drop-in bez migrace stále ne; parity pro BN-like commerce ano.*
