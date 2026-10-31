# Web+ (WebPlus)

[![GEREQ](https://img.shields.io/badge/GEREQ-module-1a3020?style=flat-square)](https://git.gereq.cz/gereq) [![Version](https://img.shields.io/badge/version-v2.1.0-3dd6c6?style=flat-square)](https://git.gereq.cz/gereq/module-webplus/-/tags) [![Module](https://img.shields.io/badge/module-webplus-6eb6ff?style=flat-square)](https://git.gereq.cz/gereq/module-webplus) [![Visibility](https://img.shields.io/badge/visibility-private-f08a6a?style=flat-square)](https://git.gereq.cz/gereq/module-webplus)

SEO/GEO, Open Graph a Google Products feed pro custom frontend. Volitelně propojitelné s CMS.

## Co umí

- **SEO** — title, description, canonical, robots, keywords
- **GEO** — geo.region / placename / position + AI shrnutí + JSON-LD + `llms.txt`
- **Open Graph / Twitter** — náhledy pro sociální sítě
- **Sitemap / robots.txt** — public discovery soubory
- **Google Products feed** — RSS XML (`g:` namespace) z více zdrojů (Products, Listings, vlastní moduly)

## Public API (CORS)

| Endpoint | Popis |
|----------|--------|
| `GET /api/public/webplus/meta?path=/o-nas` | Resolve SEO/OG balíček pro path (i dynamické `/nabidky/{slug}`) |
| `GET /api/public/webplus/html?path=/nabidky/{slug}` | Stejný balíček jako standalone HTML (OG pro crawlery) |
| `GET /api/public/webplus/meta/by-page/{pageId}` | Resolve podle CMS page id |
| `GET /api/public/webplus/site` | Site defaults + mapa endpointů |
| `GET /api/public/webplus/sitemap.xml` | Sitemap |
| `GET /api/public/webplus/robots.txt` | robots.txt |
| `GET /api/public/webplus/llms.txt` | llms.txt (GEO) |
| `GET /api/public/webplus/google-products.xml` | Google Merchant feed |

Odpověď `meta` obsahuje `seo`, `geo`, `og`, `twitter`, `json_ld` a hotové `tags` pro render `<head>`.

## Mapování FE cest

Vlastní záložka **Web+ → Mapování FE cest** (`/admin/webplus/metas/mapping`):

| FE routa | Šablona | Zdroj |
|----------|---------|--------|
| `/nabidky/:slug` | `/nabidky/{slug}` | listings |
| `/stranka/:slug` | `/stranka/{slug}` | CMS |
| `/:page/:x` | `/{page}/{x}` | obecné |

Tlačítko **Automatické mapování** projde konkrétní Web+ pathy a odvodí generické šablony podle shody URL (např. `/nabidky/a` + `/nabidky/b` → `/nabidky/{slug}`) — bez vazby na modul/složku. **Doplnit šablony** založí jen vestavěné seedovatelné záznamy.

## Dynamické cesty (listings / CMS)

Resolve nehledá jen přesnou path:

1. Přesný Web+ záznam (`/o-nas`)
2. Šablona s placeholdery (`/nabidky/{slug}`, `/{page}/{x}`)
3. Živá entita — publikovaný listing nebo CMS stránka

Bez ručního meta záznamu dostane `/nabidky/chalupa-rymarov` title, description, `og:image` (cover), GEO (`geo.placename`, `geo.position`, `ICBM`) a JSON-LD `RealEstateListing` z inzerátu.

V title/description šablony jdou tokeny `{title}`, `{city}`, `{slug}`, `{price}`, `{page}`, `{x}`.

Sitemap automaticky přidá publikované nabídky a CMS stránky.

FE2 Vite plugin (`vite-plugin-webplus`) injektuje stejné tagy do prvního HTML, takže Facebook / WhatsApp / Google vidí OG i bez JS.

## Google feed — více zdrojů

Nastavení `webplus.google_feed_sources`:

- `*` — všechny registrované zdroje
- `listings` — jen reality
- `products,listings` — oba vestavěné zdroje

URL šablony:

- `webplus.google_feed_product_path` — např. `/p/{sku}`
- `webplus.google_feed_listings_path` — např. `/nabidky/{slug}`
- `webplus.google_feed_extra_paths` — JSON mapování dalších zdrojů, např. `{"parts":"/dil/{sku}"}`

### Hooky pro vlastní modul

```php
// Registrace zdroje
$hooks->addFilter('webplus.google_feed.sources', static function (array $sources): array {
    $sources[] = [
        'id' => 'parts',
        'label' => 'Náhradní díly',
        'default_path' => '/dil/{sku}',
        'module' => 'parts',
    ];
    return $sources;
});

// Položky (append, když je zdroj zapnutý)
$hooks->addFilter(
    'webplus.google_feed.items',
    static function (array $items, GoogleFeedContext $ctx): array {
        if (!$ctx->isEnabled('parts')) {
            return $items;
        }
        $items[] = [
            'id' => 'parts:123',
            'title' => 'Filtr oleje',
            'description' => '…',
            'link' => $ctx->buildLink('parts', ['sku' => 'OF-1', 'id' => '123']),
            'image_link' => '',
            'price' => '199.00 CZK',
            'availability' => 'in_stock',
            'brand' => $ctx->brand,
            'condition' => 'new',
            'source' => 'parts',
        ];
        return $items;
    },
);
```

## Authenticated API

Scopes: `webplus.read`, `webplus.write`

- `GET/POST /api/v1/webplus/metas`
- `GET/PATCH/DELETE /api/v1/webplus/metas/{id}`
- `GET /api/v1/webplus/resolve?path=…`
- `GET /api/v1/webplus/site`
- `GET /api/v1/webplus/google-products`

## CMS

- Meta může mít `cms_page_id` — path se synchronizuje se slugem stránky
- Na detailu CMS stránky je tlačítko **SEO / OG (Web+)**
- Public CMS JSON (`/api/public/pages/{slug}`) obsahuje pole `webplus` (filter `cms.serialize_page`)

## Admin

- **Web+ → SEO / OG meta** — CRUD záznamů
- **Web+ → Zdraví webu** — skóre 0–100 % (SEO / OG / GEO / FE)
- **Web+ → Nastavení** — defaults, URL frontendu, llms/robots, Google feed (zdroje + šablony)

## Bundled AI submodule

`Submodules/Ai/` contains the optional **AI** feature (`webplus_ai`, version 2.0.0).
Both `WebPlusModule::SUBMODULES` and `module.php` declare it. It shares this
repository and release bundle, and can be managed separately under Web+ in Admin
→ Modules. Update both Web+ and AI on existing installations; entity tables,
settings and public URLs remain unchanged. See [AI documentation](Submodules/Ai/README.md).

## 3.0.0 — Cookie extraction

Consent settings, `cookiesConfig()`, cookie administration UI and cookie endpoints were moved to the standalone `Cookie` module. Enable Cookie to retain the legacy public `/api/public/webplus/cookies` alias. The private Web+ cookie endpoint and cookie data inside Web+ site defaults are removed; new clients should use `/api/public/cookie/config`. Cookie's migration copies existing configuration without deleting the original Web+ settings. The legal CMS cookie-policy path consumes the optional Cookie capability.
