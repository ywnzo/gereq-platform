---
name: gereq-publishing
description: Úpravy publish pipeline a admin CRUD veřejného obsahu nebo registrovaných CRM entit GEREQ se stagingem a produkčními snapshots. Nepoužívej pro deploy aplikace.
---

# Obsah STAGE → PRODUCTION

Přečti kořenový `AGENTS.md`, `.cursor/rules/publishables.mdc` a dotčené implementace v `src/Publish/`. API adapterů a ProductionFreeze ověř ve zdroji.

Pipeline se vztahuje na admin CRUD ovlivňující obsah /api/public a CRM entity zapojené do publishables. Auth/permissions, payments a runtime/intake operace (cart/checkout, Viewings requests, CustomerUploads) zůstávají okamžité. Staging nezaváděj do každé veřejné API operace.

- Modul registruje adapter přes context publishables registry v register. Adapter implementuje Gereq\Publish\PublishableAdapterInterface.
- Settings adapter uvádí klíče v settingsKeys. Stageují se odlišné hodnoty; public čte production.
- Před změnou veřejné entity použij ProductionFreeze::freezeIfNeeded s původním produkčním snapshotem. Admin zapisuje pracovní kopii, public čte snapshot do publish.
- Delete používá stageDelete: řádek i snapshot zůstávají pro public, admin skrývá pending deletes. Hard delete nastává při apply DELETE.
- Create určený k publikování používá stageCreate: public ho vylučuje před publish. Draft/inactive create posuzuj podle konkrétního lifecycle.
- Zachovej selective publish, staging badge a app.content_version. Neodvozuj původní snapshot z již upravené pracovní kopie.

Ověř relevantní přechody: create před/po publish, edit s původním public snapshotem, staged delete bez předčasného hard delete a selective publish bez zveřejnění ostatních změn. Pokud se jich úprava dotýká, prověř opakované změny a cancel/discard.

Použij existující publish/module tests a projektový testing engine. Testy nesměruj na klientská produkční data. Module změny mají konzistentní version bump; implementace není pokyn publikovat živý obsah.
