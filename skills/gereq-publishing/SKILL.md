---
name: gereq-publishing
description: Úpravy publish pipeline a admin CRUD veřejného obsahu nebo registrovaných CRM entit GEREQ se stagingem a produkčními snapshots. Nepoužívej pro deploy aplikace.
---

# Obsah STAGE → PRODUCTION

Přečti kořenový `AGENTS.md`, `.cursor/rules/publishables.mdc` a dotčené implementace v `src/Publish/`. API adapterů a ProductionFreeze ověř ve zdroji.

Pipeline se vztahuje na admin CRUD ovlivňující obsah /api/public a CRM entity zapojené do publishables. Auth/permissions, payments a runtime/intake operace (cart/checkout, Viewings requests, CustomerUploads) zůstávají okamžité. Staging nezaváděj do každé veřejné API operace.

## Registrace (fluent)

```php
$context->publishing($this->id())
    ->category('my.category')
    ->labelKey('publish.category.my')
    ->sort(40)
    ->adapter(MyPublishAdapter::class);
```

Adapter žije v modulu (`modules/<Id>/Publish/…`), ne v `src/Publish/Adapter/` (tam zůstává jen branding/customers core). Implementuje `PublishableAdapterInterface` včetně `discard()`.

## Mutace

- Před změnou veřejné entity použij `ProductionFreeze::freezeIfNeeded` s původním produkčním snapshotem.
- Delete: `stageDelete`. Create → public: `stageCreate`.
- Zachovej selective publish, topbar badge a `app.content_version`.
- Workspace UI: `/admin/content/staging` (publish / discard / snapshot detail).
- Live badge: `StagingSignal` + hlavička `X-Gereq-Staging-Count` + event `publish:pending-changed`.

Ověř relevantní přechody: create před/po publish, edit s původním public snapshotem, staged delete bez předčasného hard delete, discard a selective publish. Použij existující publish/module tests a projektový testing engine.
