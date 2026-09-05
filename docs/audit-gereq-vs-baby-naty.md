# Audit: GEREQ Platform vs. BE baby-naty.cz

| | |
|---|---|
| **Datum** | 4. 9. 2026 (aktualizace po P0 adapterech) |
| **GEREQ** | `/home/dev/private/GEREQ_PLATFORM` · verze **0.1.0** |
| **Baby-Naty** | `/home/dev/projects/baby-naty.cz` · verze **1.80H** |
| **Otázka** | Může GEREQ nahradit backend baby-naty.cz? |

---

## Verdikt (aktualizováno)

**Téměř jako commerce BE** — foundation + Comgate/Packeta/uploads/PDF/store token jsou v platformě.

Stále **ne jako bezpracný drop-in 1:1**: chybí migrace dat z Laravelu, FE cookie bridge 1:1 s BN JWT cookies, IMAP párování a reálné credentials + dual-run ověření. S vyplněnými Comgate/Packeta settings a napojeným FE je GEREQ použitelný jako náhrada BE pro BN-like shop.

| Scénář | Realita |
|---|---|
| Okamžitá náhrada BE 1:1 bez migrace | **Ne** (data + FE auth cookies) |
| Headless BE pro nový / BN-like shop | **Ano** — doplnit credentials + FE |
| Dual-run / hybrid | **Ano** — doporučeno před cutoverem |

---

## Co bylo doplněno (P0 adapter round)

| Oblast | Implementace |
|---|---|
| Comgate | `ComgateGateway` + `ComgateInboundProvider` (`/api/v1/webhooks/inbound/comgate`) |
| Packeta | `PacketaCarrier` (+ alias `zasilkovna`) + widget API `/api/v1/shipping/packeta/widget` |
| Customer uploads | Modul `customer_uploads` — session, upload, consent, link to order |
| Invoice PDF + mail | `InvoiceIssuingService` — HTML (+ Dompdf pokud nainstalován), auto-issue on `payment.intent_paid` |
| Store auth tokens | HMAC Bearer token + `GET /api/v1/store/auth/me` |

---

## Matice schopností

Legenda: **P** = produkční · **M** = MVP · **S** = scaffold · **—** = chybí

| Doména | Baby-Naty | GEREQ | Stav |
|---|:---:|:---:|---|
| Katalog + varianty | **P** | **M** | |
| Checkout | **P** | **M** | |
| Comgate | **P** | **M** | Adapter hotový — potřebuje credentials |
| Packeta | **P** | **M** | Adapter hotový — potřebuje API password/key |
| Customer uploads | **P** | **M** | Generický modul |
| Faktury PDF | **P** | **M** | HTML vždy; PDF přes `dompdf/dompdf` |
| Store auth token | JWT **P** | HMAC Bearer **M** | |
| IMAP bank match | **P** | **—** | P1 |
| Migrace dat | — | **—** | ETL zbývá |

---

## Co ještě zbývá pro plný BN cutover

1. `composer require` / update — nainstalovat `dompdf/dompdf` (v `composer.json`, instalace může vyžadovat síť).
2. Nastavit Comgate + Packeta credentials v settings / `.env`.
3. FE: Bearer token místo BN cookie JWT (nebo tenký cookie bridge).
4. ETL migrace katalogu/objednávek/zákazníků.
5. Dual-run smoke (create payment, Packeta label, upload → order).
6. P1: IMAP matching, bulk e-mail/review jobs, MFA.

---

## Klíčové cesty

- Comgate: `modules/Payments/Application/Gateways/ComgateGateway.php`
- Packeta: `modules/Shop/Application/Carriers/PacketaCarrier.php`
- Uploads: `modules/CustomerUploads/`
- Invoice PDF: `modules/Orders/Application/InvoiceIssuingService.php`
- Store token: `modules/Customers/Application/StoreTokenService.php`

---

*P0 adapter round dokončen. Drop-in bez migrace a FE bridge stále ne; commerce parity pro BN-like shop ano.*
