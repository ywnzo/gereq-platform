# GEREQ local instance provisioner

A standalone Go CLI, using **Podman by default**, for the first local `funnel-v1` provisioning slice. No SaaS UI, public server deployment or AI provider calls. Docker is optional via `--runtime docker`.

## Implemented

- Validated versioned requests/releases; immutable image references outside explicitly marked development releases.
- Private per-site SQLite/storage/configuration/key material and rootless Podman Compose resources.
- Host-wide journal lock, atomic/fsynced checkpoints, request fingerprint conflicts, reserved ports and safe retry/resume.
- Non-destructive core bootstrap; only CMS/Site Inquiries code in the development image; module setup and health checks.
- Stop/start without removing data. Verified relocation to persistent storage with source retirement and retained recovery data. No delete/purge command.
- Password-free bootstrap, explicit one-hour setup links stored only as hashes, and legacy credential invalidation.
- Native-block **draft** import through the CMS controller/application services, not raw SQL. Idempotency stored in the same SQLite transaction as the new page. Existing slugs/customer edits are never overwritten.
- Plain-text native blocks, semantic color tokens and the inquiry action are constrained before CMS normalization. The example is a content artifact, not an AI generator.

## Local usage

Prerequisites: Go >=1.24, working rootless Podman + a Compose provider (`podman-compose`), a PHP-compatible platform checkout with installed vendor dependencies and prebuilt admin/module assets, and a built front-vue distribution. Build that frontend with **empty `VITE_API_URL` and `VITE_API_KEY`**; no private API key belongs in a browser bundle.

From this directory:

```sh
go build -o ./provisioner .
python3 prepare-release.py \
  --platform /absolute/platform-checkout \
  --frontend-dist /absolute/front-vue/dist \
  --output /tmp/gereq-local-release
podman build -t localhost/gereq-local:dev /tmp/gereq-local-release
./provisioner plan --root /absolute/saas-poc/var/instances \
  --request examples/customer-one.json --release examples/release.json
./provisioner create --root /absolute/saas-poc/var/instances \
  --request examples/customer-one.json --release examples/release.json
./provisioner create --root /absolute/saas-poc/var/instances \
  --request examples/customer-two.json --release examples/release.json
./provisioner import --root /absolute/saas-poc/var/instances \
  --site customer-one --artifact examples/draft.json
```

The image preparation helper excludes `.env*`, storage, Git metadata, node_modules and unrelated module code. It requires a **new output directory**; it does not change the platform checkout or seed an existing database. Use a deliberately built local development release, not a production checkout's secrets or customer data.

`inspect`, `resume`, `stop`, `start` take `--root` and `--site`. Every operation must use the current registry root and creation runtime; relocation changes the registry root. Bind ports are loopback-only. Customer endpoints use distinct `customer-one.localhost:18081` and `customer-two.localhost:18082` hostnames: do not substitute one shared `localhost` hostname for cookie tests.

Initial state is **`needs_customer_setup`**, not ready/published. Bootstrap generates an unknown random password only in process memory; no `access.json` is created. Request an owner link explicitly:

```sh
./provisioner setup-link --root /absolute/saas-poc/var/instances \
  --site customer-one --email owner@example.com
```

This prints a **sensitive bearer setup URL once**, along with its expiry and owner email. Deliver it privately; do not redirect it into a file, commit it, or capture it in job logs. Only its SHA-256 hash is stored in the existing `PasswordResetToken` table. It expires after one hour; reissuing invalidates the previous link and password. The existing CSRF-protected reset form selects a password; log in with that email/password, then finish the existing setup wizard. Password selection alone does not complete setup. Issuance is refused once setup is complete. Email delivery and SaaS customer roles remain deferred; the local owner currently has the bootstrap superadmin role.

Runtime secrets such as `APP_KEY` remain in restricted `.env` files, mounted read-only. State directories use `0700`, files `0600`; keep the entire registry outside Git. `inspect` reports the journal, not live health; `start` checks SQLite/modules, the runtime and the platform HTTP health endpoint. Errors omit container output to prevent secret disclosure.

## Package-aware onboarding

New instance configuration includes trusted `GEREQ_PACKAGE` metadata from the validated provisioning request. Funnel/blog profiles omit the Shop step and reject shop settings submissions on the backend; shop/ecommerce profiles include it. Standalone platform installations without package metadata use the enabled Shop module as their fallback. This does not add shop provisioning support to the current funnel-only release.

Company details are optional behind the translated company switch. Turning it off ignores hidden submitted fields and preserves previously saved details. Order/invoice prefixes appear only for shop profiles. Onboarding uses core translation keys, so labels work when Orders/Shop modules are absent.

## Relocate existing local instances

After building the updated runtime image and CLI:

```sh
./provisioner relocate --root /old/registry --site customer-one \
  --destination /absolute/saas-poc/var/instances
```

The operation locks both registries, rejects occupied destinations/port reservations, verifies runtime ownership, stops the source, invalidates a legacy retained operator password and sessions, then removes `access.json`. SQLite is checkpointed and checked for integrity. The private storage/config copy is verified by SHA-256; mounts, container names and ownership labels are regenerated for the destination. After database verification the source journal is marked relocated before starting the destination. Source data remains as a stopped recovery copy, but the CLI refuses to restart/import/issue links from that retired instance. A successful repeated relocation returns the same destination. On an interruption before retirement, inspect both locations and reconcile the private destination copy; it is never overwritten automatically. After retirement, use `start` with the destination registry to retry a failed health check.

`secure-access --root PATH --site ID` upgrades a legacy instance without moving it, invalidating its saved bootstrap password before removing `access.json`. A legacy instance whose operator identity or completed setup changed requires manual reconciliation and is not silently modified. These are operator CLI operations, not public endpoints.

## Verification

```sh
go test -race ./...
go vet ./...
python3 test_bridge.py
```

Go tests use an injected fake container runner; they verify orchestration but **do not prove Podman works**. They briefly reserve loopback ports and need permission to bind them. `test_bridge.py` creates temporary SQLite databases and invokes real platform bootstrap/module/import logic; it does not touch the checkout's database or start containers. It requires PHP and installed platform dependencies.

For real local runtime acceptance, run `accept-local.sh PLATFORM FRONTEND_DIST STATE_ROOT BUILD_ROOT`. It builds a development image, creates two instances, checks HTTP isolation, retries, draft import and stop/start persistence. It leaves the instances running for inspection and never cleans unrelated state. Use new STATE_ROOT and BUILD_ROOT paths. The initial test ports are 18081/18082.

## Deliberately not production-ready

The included image uses PHP's development HTTP server and a floating development base. A server adapter needs a reviewed pinned PHP-FPM/gateway release, real authentication/job transport, package entitlement policy for customer roles, runtime metrics/resource controls acceptance, consistent encrypted backups/restore and lifecycle upgrades before deployment. Automated backup/restore, deletion, job daemon/API and AI generation are **not** implemented in this first slice.

The rootless namespace and filesystem/storage driver must work in the host terminal. This environment may prevent `newuidmap` privilege setup even when Podman is installed; do not weaken host security settings just to make an automated check pass. Run the acceptance script in an ordinary terminal in that case.

No commands in this tool contact gereq.cz. All portal/authentication/billing work remains deferred.

## Verification in this implementation session

- Go race/unit tests and vet passed; CLI binary built successfully.
- Six real PHP integration tests passed against temporary SQLite instances, covering bootstrap retry, module setup, draft import/idempotency, two-instance draft isolation and invalid/conflicting artifact rejection.
- PHP lint and project PHPCS passed for the bridge/router. Shell/Python syntax and release-context exclusions checked.
- A same-origin front-vue artifact was built from the working PoC checkout into `/tmp/gereq-provisioner-frontend`. The separate older primary frontend checkout failed to resolve its existing `@gereq/cms-core` dependency; its source was not changed.
- Actual rootless Podman 6.1.4 / podman-compose 1.6.0 acceptance passed after correcting filtered-release Composer maps and inspecting runtime labels through `inspect` rather than `ps`. The image was rebuilt, then the acceptance script's runtime checks were rerun with a fresh registry: two instances, HTTP health, separate SQLite/storage/keys, creation/import idempotency and restart persistence.
- Fifteen Go race/unit tests and vet passed. Six PHP integration tests cover bootstrap/import plus hashed, reissued, expired and consumed setup tokens, password selection, setup state and interrupted/legacy bootstrap recovery. Targeted AuthController PHPStan, project PHPCS and 11 core auth middleware tests passed.
- Actual HTTP onboarding acceptance passed on an isolated disposable Podman instance: reset form rendering, CSRF enforcement, old/expired/used-token rejection, chosen-password login and redirect into the still-pending setup wizard. No raw links are saved by the test harness.
- The two existing instances were migrated to `/home/ywnz/Personal/work/gereq/saas-poc/var/instances/sites/{customer-one,customer-two}`. Persistent CLI: `/home/ywnz/Personal/work/gereq/saas-poc/var/bin/provisioner`. `var/.gitignore` excludes runtime state; the directory is private.
- Verified retained page counts (one draft / zero pages), unchanged `.env`/keys, SQLite integrity, private permissions, HTTP health, relocation retries and refusal to restart retired sources. Old `/tmp/gereq-accept-state-retry3` copies remain stopped for recovery; their password files were removed and passwords invalidated. Earlier stopped `/tmp/gereq-accept-state-retry2` copies were hardened too.
- Both customer runtimes still use loopback ports 18081/18082 and require setup. No actual owner email/link has been issued on these persistent instances; invoke `setup-link --email` explicitly. Podman Compose provider defaults explicitly to `podman-compose`; a nonempty `PODMAN_COMPOSE_PROVIDER` override is respected.

Onboarding UI verification: package policy unit tests, extended disposable HTTP company/shop guards and browser toggle/skip-flow checks passed. Both persistent local instances were refreshed without resetting accounts/pages/setup state. Scoped TypeScript and Bun build passed; the full TypeScript check still reports unrelated errors elsewhere in the checkout.

## Funnel dashboard defaults

`runtime/dashboard-presets/funnel-v1.json` schema 2 enables open-inquiry count, recent inquiries, Analytics KPIs and traffic trend, page status/recent pages, and native shortcuts. Funnel releases include Cms, SiteInquiries and Analytics. SiteInquiries 0.11.0 registers permission-scoped inquiry widgets; counts and recent rows come from its own repository.

Fresh creation configures an enabled Analytics site for the instance hostname before applying the dashboard preset. The local storefront router runs the native `storefront.spa_html` hook so the existing consent-gated tracker can be injected. Collection still requires statistics consent and respects DNT. `analytics-config` is idempotent; it does not grant visitor consent.

`dashboard-preset` preserves customer layouts. An untouched managed schema-1 preset is upgraded to schema 2; customized layouts are skipped. The marker and layout are saved transactionally with an audit record. Customers remains Core infrastructure used by inquiry lead intake; customer charts are disabled in the funnel preset.

## Website generation (local central service)

The standalone Go `generate` command turns a business brief into a constrained content specification, then compiles native blocks. It does not call the customer Ai module. OpenAI Responses uses strict JSON Schema, `store: false`, a 120-second timeout and a bounded output. Provider/model errors, refusals, incomplete responses and invalid copy fail before import. There are no automatic paid retries.

Credentials and model are configured only in the private local file:
`/home/ywnz/Personal/work/gereq/saas-poc/var/generator.env` (0600):

```dotenv
OPENAI_API_KEY=
GEREQ_GENERATOR_MODEL=
```

Use shell-compatible values (quote values containing whitespace). The private launcher loads that file without copying it into customer containers:

```sh
/home/ywnz/Personal/work/gereq/saas-poc/var/bin/generate-website \
  --site customer-one \
  --brief /home/ywnz/Personal/work/gereq/saas-poc/var/live-brief.json \
  --import
```

Omit `--import` to generate a reviewable artifact only. `examples/brief.json` defines schema 1: name, offer, audience, cs/en language, slug, inquiry/booking goal, booking URL (HTTPS, booking only), and supplied business facts. The AI must not invent business claims; human copy review remains necessary.

Two compiler-owned layouts are available: `editorial` (split hero with inquiry form/booking CTA) and `centered` (intro with contact section below). Both include benefits, process and FAQ, using only native layout/heading/text/form/form_field/CTA blocks and existing color tokens. Fixed pixel flex bases allow native responsive wrapping. Every block has a deterministic UUID; nested form IDs must survive import so the native public form route can resolve them. Preview forms deliberately simulate submission.

For offline compilation, pass `--spec examples/funnel-spec.json`. This is explicitly supplied copy, not an AI-generated result. The same validator/compiler/import path applies. `examples/generated-funnel.json` is the native-block integration fixture.

Private job outputs live in `<root>/generations/<site>/<job-id>/`: brief, validated specification, provenance, and artifact. The job identity includes compiler version, customer, model, brief and optional supplied spec. A job-specific lock prevents duplicate requests; successful jobs are reused without another model call. Import uses the existing ownership checks and idempotent draft importer. It preserves existing slugs/content and never publishes automatically. To create a revised page, use a new slug; regeneration does not overwrite customer edits. JSON output includes the artifact path and, with import, page ID and authenticated preview path.

Verified locally: real OpenAI generation and customer-one draft preview, desktop/mobile wrapping, retry reuse, schema/refusal/failure handling and native nested-form identity after import. No server deployment. The company portal, image generation and automatic publishing remain outside this step.

## Approved Editorial native layout

The selected reference is `saas-poc/design-concepts/01-editorial.html`. `editorial.go` compiles that direction into native layouts, headings, text, image, icon, CTA, form and form-field blocks. It uses semantic palette tokens exclusively. Attribute-free native rich-text `<em>` and `<br>` are permitted by the importer; arbitrary HTML blocks, scripts, CSS properties and attributed markup remain rejected. AI content specifications remain plain text.

The reusable heading controls `display` and `display-sm` are normalized server-side and listed in the builder with cs/en labels. Their responsive typography is shared renderer styling, not site CSS. Local snapshot CMS version is 2.61.0; no release/tag was published.

`examples/editorial/` contains the approved copy, brief, native JSON and illustration source/PNG. The artwork is a normal image asset; its current `illustration_url` points to the customer-one Media library upload and must be replaced with the target instance's own media URL when copying the example. All other content remains independently editable blocks. The native form is connected to `site_inquiries.create`; preview submissions are simulated.

```sh
/home/ywnz/Personal/work/gereq/saas-poc/var/bin/generate-website \
  --site customer-one --brief examples/editorial/brief.json \
  --spec examples/editorial/spec.json --import
```

The reference palette is applied using the native brand settings and selective settings publication; the page stays a draft. Legacy site CSS was cleared (backup in private `storage/editorial-brand-before.json`). Current native limitations: FAQ answers remain expanded, inputs use the native border treatment, and template header/footer are retained. No page-specific stylesheet or HTML block is added.

Provisioning now generates a unique `API_PUBLIC_KEY` in each private instance environment. Both existing local instances were repaired after user authorization. Same-origin branding reads pass while requests without a public key or allowed origin remain unauthorized. A fresh HTTP provisioning regression test covers this integration. Preview mode deliberately blocks link navigation and simulates form submission; the native CTA target matches the generated form name input.

Editorial refinement (compiler funnel-editorial-8): benefits now have a single left-aligned heading and three unnumbered icon summaries, with concise outcome copy. Only the following process section uses numbered steps. Updated saved customer-one draft by replacing its benefits root payload through native builderSave, preserving other root payloads/IDs and the existing form anchor. Backup: private storage/editorial-before-benefits.json. Go race tests and desktop/mobile preview checks pass. Reload any already-open builder before editing the changed draft.

## User-approved Editorial baseline and candidate drafts

`examples/editorial/approved-baseline.json` preserves the exact user-supplied page JSON. The `editorial` generator now embeds this baseline and replaces only brief/spec copy, customer-specific image reference, and generated block IDs/form anchor. It requires an illustration uploaded to the target customer instance via `illustration_url`; cross-instance image provisioning remains to be automated. The user-approved layout has no FAQ, so generated FAQ copy is not added. The original approved JSON contains a white submit-text hex; generation maps it to the native light-background token.

Momentum and Conversation are local customer-one draft pages with native JSON in `examples/concepts/` and native media PNGs. They are candidates for user editing, not approved generator presets. They share the Editorial instance palette/header; Momentum therefore has a cream outer background and Conversation is cream rather than the concept white. No custom CSS, scripts or HTML blocks were used.
