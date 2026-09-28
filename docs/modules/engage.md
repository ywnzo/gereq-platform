# Engage (`engage`)

Engage is the marketing layer for tracked campaigns and dynamic content spots. It lives in the admin **Marketing** sidebar group next to [Newsletter](newsletter.md). It does **not** replace CMS pages, Web+ SEO/OG, Newsletter email campaigns, or Emailing delivery — it links to them.

## At a glance

| Item | Value |
| --- | --- |
| Module id | `engage` |
| Version | `1.99.0` |
| Directory | `modules/Engage/` |
| Requires | `core ^1.0` |
| Optional | `cms`, `webplus`, `newsletter`, `emailing`, `listings`, `products`, `customers`, `media` |
| Capabilities | `engage` → `Application\EngageProvider` |
| Permissions | `engage.view`, `engage.manage` |
| API scopes | `engage.read`, `engage.write` |

Czech product framing: module name **Engage**, nav labels Kampaně / Obsah; section **Marketing** (shared with Newsletter).

## Domain

| Entity | Table | Role |
| --- | --- | --- |
| `Campaign` | `engage_campaigns` | Tracking campaign (`slug` / `?mkind=`), landing path, CTA, UTM, optional CMS page + OG image |
| `Spot` | `engage_spots` | Dynamic content by `placement_key` (hero, banner, …); source `custom` / `cms_page` / `listings_path` / `product` / `external` |

## Admin

- Hub `/admin/engage` — stats + bridges to connected modules + public API cheat sheet
- Campaigns CRUD — tracking URL preview uses `webplus.frontend_url` / `base_url` when set
- Spots CRUD — media_box images, CMS page + campaign selectors

Menu group: **Marketing** (order 50, before Newsletter 55).

## Public API

| Endpoint | Purpose |
| --- | --- |
| `GET /api/public/engage/campaign?mkind={slug}` | Active campaign package (+ optional `touch=0`) |
| `GET /api/public/engage/spots?placement=` | Active spots (optional filter) |
| `GET /api/public/engage/spot?placement=` | Single active spot by key |

Authenticated `/api/v1/engage` mirrors campaigns/spots with scopes `engage.read`.

## Inspiration

Pattern inspired by GEREQ.cz `MarketingCampaign` (`mkind` attribution) + content spots instead of duplicating share-card generation (Web+ covers OG) or email broadcast (Newsletter / Emailing).
