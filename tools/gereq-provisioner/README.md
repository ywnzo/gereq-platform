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

Prerequisites: Go >=1.24, PHP, Bun, rootless Podman and a Compose provider (`podman-compose`). Install dependencies in the original `GEREQ_PLATFORM` and `front-vue` checkouts. The sibling `cms-lib` checkout supplies the storefront libraries. The helper builds assets with empty frontend API URL and key.

From this directory:

```sh
go build -o ./provisioner .
python3 prepare-release.py \
  --platform /home/ywnz/Personal/work/gereq/platform/GEREQ_PLATFORM \
  --output /tmp/gereq-local-release
# Run the podman build command printed by prepare-release.py.
# Use /tmp/gereq-local-release/release.json for provisioning.
./provisioner plan --root /absolute/private/instances \
  --request /absolute/private/customer-one.json --release /tmp/gereq-local-release/release.json
./provisioner create --root /absolute/private/instances \
  --request /absolute/private/customer-one.json --release /tmp/gereq-local-release/release.json
./provisioner create --root /absolute/private/instances \
  --request /absolute/private/customer-two.json --release /tmp/gereq-local-release/release.json
./provisioner import --root /absolute/private/instances \
  --site customer-one --artifact examples/draft.json
```

For a new build, copy the example request and set its `release_id` to the `id` in the generated `release.json`.

The helper reads the current working trees, including uncommitted edits, and records their Git revisions and content fingerprint in the release metadata. It compiles assets into the release context. The image preparation helper excludes `.env*`, storage, Git metadata, node_modules and unrelated module code. It requires a **new output directory**; it does not change the platform checkout or seed an existing database. Use a deliberately built local development release, not a production checkout's secrets or customer data.

`inspect`, `resume`, `stop`, `start` take `--root` and `--site`. Every operation must use the current registry root and creation runtime; relocation changes the registry root. Bind ports are loopback-only. Customer endpoints use distinct `customer-one.localhost:18081` and `customer-two.localhost:18082` hostnames: do not substitute one shared `localhost` hostname for cookie tests.

Initial state is **`needs_customer_setup`**, not ready/published. Bootstrap generates an unknown random password only in process memory; no `access.json` is created. Request an owner link explicitly:

```sh
./provisioner setup-link --root /absolute/private/instances \
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
  --destination /absolute/private/instances
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

## AI-authored funnel layouts

The OpenAI generator returns an Editorial-inspired native block tree and seven brand colors, not HTML, CSS, or scripts. The compiler validates supported blocks, URLs, form actions, palette values and text contrast; it creates the inquiry fields and trusted links itself. Generation returns a draft, and stages the palette through the normal brand publishing pipeline. A pending different palette blocks import to protect existing work. The previous approved Editorial JSON remains available through a supplied legacy specification.

Customer images must contain the matching bridge version before AI-generated palettes can be imported. The provisioner checks this before a paid model call; build a release from the original source checkouts and update the instance separately. The local model and API key stay in the central service environment.

## Local instance updates

The updater targets one local development instance at a time. Plan first and use the exact image ID returned by the plan:

```sh
provisioner update-plan --root "$STATE" --site SITE --release release.json
provisioner update --root "$STATE" --site SITE --release release.json --expected-image-id IMAGE_ID
provisioner update-status --root "$STATE" --site SITE --update-id UPDATE_ID
```

The update requires `restic` in `PATH` and the private 32-byte `GEREQ_BACKUP_MASTER_KEY_FILE`. It stops the instance, checks SQLite, and encrypts and verifies a rollback snapshot before applying migrations. If interrupted, run `provisioner update-recover --root "$STATE" --site SITE`. To roll back a completed update, run `provisioner update-rollback --root "$STATE" --site SITE --update-id UPDATE_ID --accept-data-loss`. Recovery also requires `--accept-data-loss` if the new runtime may have accepted writes. Later data is retained separately in the encrypted update repository, but is not merged into the restored database. Export it when needed with `provisioner update-export-retained --root "$STATE" --site SITE --update-id UPDATE_ID`; the export is plaintext and must be protected.

New rollback snapshots are encrypted under `$STATE/updates/`; preserve the master key separately or recovery becomes impossible. Older snapshots created before encryption remain unencrypted and are not migrated automatically. The environment file is not copied, and recovery refuses a changed environment. Custom mounts, non-SQLite instances, platform/module downgrades, and non-development releases are rejected. This is a local workflow, not a production rollout or off-site backup system; migrations are not rehearsed against a disposable copy before the instance is stopped.

## Encrypted backup foundation

The provisioner supports encrypted restic repositories (local paths and S3-compatible destinations). Install `restic` and `sqlite3` in the service's `PATH`. Configure a JSON file, for example:

```json
{"schema_version":1,"repository":"/absolute/path/to/restic-repository","password_file":"/absolute/path/to/private-password"}
```

The password file must be a regular file with no group or other permissions and contain at least 16 characters. Keep it independent of the repository and back it up securely; losing it makes the encrypted data unrecoverable. For S3 use a repository such as `s3:https://endpoint.example/bucket/prefix` and supply provider credentials to the service environment, never in the URL. Local storage is useful for testing but is not off-site.

```sh
provisioner backup-init --root "$STATE" --backup-config config.json
provisioner backup-create --root "$STATE" --backup-config config.json --site SITE
provisioner backup-verify --root "$STATE" --backup-config config.json --site SITE --snapshot FULL_ID
provisioner backup-restore --root "$STATE" --backup-config config.json --site SITE --snapshot FULL_ID
```

For a running instance, backup stops it, checks SQLite and copies storage, then restarts it before uploading the encrypted backup. Downtime depends on the size of storage and is not guaranteed to be one minute. If restart fails or the service crashes while stopped, use `provisioner backup-recover --root "$STATE" --site SITE`. An already stopped instance remains stopped. Backups encrypt storage, `.env`, state and compose configuration. The temporary local storage copy is plaintext in a private directory. After a crash, the API removes marked temporary copies on restart under the registry lock; unmarked or unverifiable data is preserved. Verification restores into a temporary private directory, verifies restic data and SQLite, then deletes the temporary copy. Restore exports to a new private `$STATE/backup-restores/SITE/FULL_ID` directory without touching a running instance; the exported files, including `.env`, are **plaintext**, so protect and remove them when no longer needed. The exported configuration may refer to the original host paths and needs reconciliation before use on another host.

The central admin and live instance restore are not implemented, and remote storage has not been tested. Update rollback snapshots use their own local encrypted repositories. Do not treat this as a production backup system yet.

## Private backup API

Start the provisioner as a local service (make sure `restic` and `sqlite3` are in `PATH`):

```sh
provisioner backup-api --root "$STATE" --socket "$STATE/backup-api/api.sock"
```

The Unix socket and its parent directory are private to the service account. This is a privileged service-to-service interface: the future central backend must authenticate and authorize its users before calling it. Do not expose the socket through a public proxy. A second API process cannot use the same state directory.

| Method and path | Purpose |
| --- | --- |
| `GET /v1/backup/config` | Read non-secret configuration status and destination. |
| `POST /v1/backup/credentials` | Store repository password and optional S3 credentials encrypted; returns a credential ID and never returns the secret. |
| `PUT /v1/backup/config` | Set the repository and either a credential ID or legacy private password-file reference. |
| `GET`, `PUT /v1/backup/schedules/{site}` | Read or configure a per-site schedule and retention policy. |
| `GET /v1/backup/sites/{site}/snapshots` | List that site's snapshot IDs and timestamps. |
| `POST /v1/backup/jobs` | Start `init`, `create`, `verify`, `restore`, `recover` or `scheduled`; returns a job. |
| `GET /v1/backup/jobs` | List up to the latest 100 jobs. |
| `GET /v1/backup/jobs/{id}` | Read a job's status and result. |

A job request requires an `Idempotency-Key` header (8–128 letters, digits, `.`, `_` or `-`) and JSON such as `{"operation":"create","site_id":"customer-one"}`. `verify` and `restore` also require the full `snapshot_id`; `init` takes neither a site nor a snapshot. Only one job runs at a time. Retrying the same key and request returns its existing job; reusing the key for another request fails. Jobs interrupted by a service restart are marked `interrupted` and are **not** retried automatically: inspect the repository before deciding what to do. A failed or interrupted create might already have produced a snapshot; check the site snapshot list before retrying.

For the encrypted credential store, set `GEREQ_BACKUP_MASTER_KEY_FILE` when starting the service to a separate private regular file containing exactly 32 random bytes. Protect and back up that master key separately from the server and the encrypted credential files. Losing it can make the repository password and therefore the backup data unrecoverable. The API accepts `{"password":"...","aws_access_key_id":"...","aws_secret_access_key":"..."}` (S3 fields optional); use the returned `credential_id` with `{"schema_version":1,"repository":"...","credential_id":"..."}`. Credential values are encrypted with AES-GCM and cannot be read back through the API. The master key is a host bootstrap secret, not an admin setting; S3 credentials are provided to restic in its process environment. Old credential records are retained to avoid silently destroying access to older repositories.

Configure a schedule with `{"enabled":true,"interval_seconds":86400,"keep_last":7}`. Intervals range from one hour to 31 days; retention must keep at least two verified backups. The service must remain running for scheduling; it attempts overdue jobs after restart. Each scheduled job makes and restores a backup before retention runs. Retention forgets only snapshots with a recorded successful verification for that site and repository; it does not prune underlying repository data. Failed or interrupted jobs are reported and retried only at the next interval, not immediately. The `scheduled` job operation accepts `site_id` and `keep_last` to run this flow on demand. `recover` takes only `site_id` and restarts a customer left in the snapshot window. Remote storage, deployment, monitoring and key recovery have not been tested, and legacy update rollback snapshots remain unencrypted. A restore exports plaintext files to a private directory rather than replacing a customer instance.


Encrypted update recovery may materialize temporary plaintext under the private update directory. After interruption, API startup removes marked temporary working copies. It deletes a pre-update or retained plaintext copy only after verifying its encrypted replacement; otherwise it preserves the data for manual recovery. Cleanup does not touch explicit plaintext exports, unknown directories or legacy unencrypted backups. Running only the CLI does not trigger API startup cleanup.

## Central instance API (local development)

Run the central service with an operator-managed release catalog and a private socket:

```sh
provisioner central-api --root "$STATE" --socket "$SOCKET" --release-catalog "$CATALOG"
```

`$CATALOG` is an existing directory containing approved release JSON files named `<release-id>.json`. The API accepts only local development releases from that directory. On creation it validates the image's embedded provenance and pins its actual image ID. The catalog and service socket must be controlled by the operator; do not expose this privileged local API publicly. The future central backend must authenticate users, authorize each customer operation and deliver setup links securely.

| Method and path | Purpose |
| --- | --- |
| `GET /v1/releases` | List catalog releases. |
| `GET /v1/instances` and `GET /v1/instances/{site}` | List or inspect instances. An instance with an unfinished operation may show `inspection_required`. |
| `POST /v1/central/jobs` | Create, resume, start or stop an instance; generate a funnel; update, recover or roll back an update; or export encrypted retained data. |
| `GET /v1/central/jobs` and `GET /v1/central/jobs/{id}` | Inspect durable job status. |
| `POST /v1/update-plans/{site}` | Plan an update with `{"release_id":"..."}`; use the returned image ID in the update job. |
| `GET /v1/update-status/{site}` or `GET /v1/update-status/{site}/{update_id}` | Inspect an update. |
| `GET /v1/generations/{site}/{generation_id}` | Read a generated native-block artifact. |
| `POST /v1/setup-links/{site}` | Issue a one-time setup link with `{"email":"..."}`. It is returned only in this response and must be handled as a secret. |
| `/v1/backup/...` | Existing backup configuration, credentials, schedules, snapshots and jobs. |

Central job requests require an `Idempotency-Key` header and are serialized with backup jobs. Updates require `restic` in `PATH` and `GEREQ_BACKUP_MASTER_KEY_FILE` in the service environment. Examples:

```json
{"operation":"create","site_id":"customer-one","release_id":"local-release","request":{"schema_version":1,"request_id":"request-0001","site_id":"customer-one","customer_ref":"customer-1","package":"funnel-v1","release_id":"local-release","port":18371}}
{"operation":"generate","site_id":"customer-one","brief":{"schema_version":1,"name":"Example","offer":"Consultations","audience":"Small teams","language":"en","slug":"example-funnel","goal":"inquiry","booking_url":"","facts":[]},"import_draft":true}
{"operation":"update","site_id":"customer-one","release_id":"new-release","expected_image_id":"sha256:..."}
{"operation":"update-rollback","site_id":"customer-one","update_id":"upd-...","accept_data_loss":true}
```

A `generate` request can include a validated `spec` for offline simulation; otherwise the existing OpenAI generator uses the service's `OPENAI_API_KEY` and `GEREQ_GENERATOR_MODEL`. Imported pages are drafts. API jobs persist business briefs and results in private local state, so do not place passwords or other secrets in briefs. Interrupted jobs are marked `interrupted` and are not replayed automatically; inspect the customer and explicitly request recovery or resume. The existing generation quality and customer-image capabilities still apply.

This local service is not a production SaaS API: account authentication, customer authorization, billing, production routing, deployment, and remote-storage tests remain separate work.

## Package and template catalog API

The central API exposes `GET /v1/catalog/packages` and `GET /v1/catalog/templates`, and `GET`, `PUT`, `DELETE` at `/v1/catalog/{packages|templates}/{id}`. `PUT` creates with `revision: 0` or replaces an existing entry using its current revision. `DELETE` requires the current integer revision in `If-Match` and archives the entry; it never deletes customer data. Stale revisions return 409. An active package's referenced templates cannot be retired until the package is changed or retired.

Example template and package definitions:

```json
{"id":"centered-plus","name":"Centered Plus","description":"","driver":"native-centered","active":true,"archived":false,"revision":0}
{"id":"funnel-plus","name":"Funnel Plus","description":"","runtime_package":"funnel-v1","template_ids":["centered-plus","editorial-ai"],"currency":"CZK","monthly_minor":99000,"yearly_minor":990000,"trial_days":14,"active":true,"archived":false,"revision":0}
```

The initial catalog contains the existing Editorial AI, Editorial native and Centered native drivers, plus the funnel package. Prices are optional metadata in minor currency units; the initial package has no set price. Billing and trial enforcement are not implemented. Currently `funnel-v1` is the only supported runtime package. The endpoints support the three existing template drivers and `native-json` designs containing validated native builder blocks.

To choose a commercial package, include `catalog_package_id` in the create job's `request`; omit it for `funnel-v1`. The service records the package revision and a snapshot of its allowed template IDs on the instance. Clients may not supply those assigned fields themselves. Retiring a package prevents new selections without deleting existing instances. Template activation is global; a retired template cannot be used for generation, including by existing instances.

A generation job may include `template_id`. It must be active and assigned to the instance. Editorial AI uses the existing AI design generator; native Editorial and Centered require an offline copy specification. A `native-json` template accepts either a supplied copy spec or the existing AI provider. The AI generates customer-specific copy and a palette; the saved block layout is preserved rather than redesigned. Omitting `template_id` selects an assigned active profile compatible with the supplied spec, or Editorial AI when no spec is supplied. No catalog operation publishes a customer website or creates a payment.

To add a real layout, `PUT /v1/catalog/templates/{id}` with `driver: "native-json"` and `native: {"blocks": [...]}` containing the CMS builder block tree. Put `{{headline}}` in the single level-one heading, `{{intro}}` in a text block and `{{cta}}` in a CTA label. Optional copy slots are `{{name}}`, `{{offer}}`, `{{audience}}`, `{{eyebrow}}`, `{{benefits_title}}`, `{{process_title}}`, `{{faq_title}}`, `{{contact_title}}`, `{{contact_body}}`, and `{{benefit_1_title}}`/`{{benefit_1_body}}`, `{{process_1_title}}`/`{{process_1_body}}`, `{{faq_1_title}}`/`{{faq_1_body}}` (also 2 and 3). Image blocks must use `{{image_url}}`; generation replaces it with media belonging to the customer instance. The template must contain one `site_inquiries.create` form with required `name`, `email` and `details` fields; the compiler adds the required phone field if missing, rewires CTA links, and supports the booking goal. HTML blocks, scripts, custom CSS and literal colors are rejected. The template request is limited to 1 MiB; the local combined catalog is limited to 3 MiB.

After adding a template to a package, new instances receive it. Existing instances retain their prior assignment until an admin calls `GET /v1/instances/{site}/templates`, then `PUT` the desired `{"template_ids":[...],"revision":0}` using the current revision. Assigned templates must be active and belong to the instance's original or current package. This updates central metadata without rewriting the customer instance. A template can still be globally retired after it is removed from active package definitions; retired templates cannot be used for generation.

Template definitions and per-instance template assignments live in the central service's private state (`central-api/`); customer instance backups do not include this central metadata. Back up and restore the central service state separately before relying on these settings in production.

## Combined customer creation (local operator API)

`POST /v1/customers` provisions a separate instance and generates/imports its draft in one durable workflow. It uses the same private Unix socket and global job exclusion as backups and other central jobs. The future backend must authorize the customer and choose the approved release and local port; this endpoint is not exposed directly to visitors.

Send an `Idempotency-Key` (8–110 characters) and JSON:

```json
{
  "site_id": "garden-studio",
  "customer_ref": "customer-123",
  "email": "owner@example.invalid",
  "package_id": "funnel-v1",
  "template_id": "editorial-ai",
  "release_id": "local-approved-release",
  "port": 18147,
  "brief": {
    "schema_version": 1,
    "name": "Garden studio",
    "offer": "Garden consultations",
    "audience": "Owners of small gardens",
    "language": "en",
    "slug": "garden-consultation",
    "goal": "inquiry",
    "booking_url": "",
    "facts": []
  }
}
```

The response is `202` with `id` and `status_url`. Poll `GET /v1/customers/{id}`. Phases are `provisioning`, `generating`, `importing`, and `ready_for_review`; final status is `complete`, `failed`, or `interrupted`. A completed result includes `generation_id`, `page_id`, `admin_url`, `preview_url`, `builder_url`, and `setup_link_endpoint`. Preview and builder require owner sign-in. `published` is always false: the customer reviews and explicitly publishes both the draft and staged branding.

To deliver access, `POST` to the returned `setup_link_endpoint` with no body. This uses the saved owner email and returns the one-time `setup_url` and expiry only in that response. Links are issued at retrieval time, so a slow generation cannot consume their validity; no password or bearer link is stored in workflow status, listings, or instance metadata. Treat that response as a secret and deliver it privately. Requesting another setup link invalidates the previous one and resets the bootstrap account's access; use it only for the intended customer setup/recovery.

Identical requests with the same key return the existing workflow without repeating work. A changed payload with the same key, an existing site, or a second workflow for that site is rejected. `POST /v1/customers/{id}/retry` resumes a failed/interrupted workflow; do not submit a new creation. The accepted package entitlements, template definition/revision, release and model are saved privately, and the image ID is pinned before provisioning. A retry preserves the instance and secrets and reuses the saved generation artifact on an import failure. After a service restart, running workflows become `interrupted` for explicit retry. Status responses omit the brief and owner email; private `.diagnostic` files retain operator error details.

The central process needs `OPENAI_API_KEY` and `GEREQ_GENERATOR_MODEL` configured before accepting a customer. Supported creation templates are `ai-editorial` and catalog `native-json` layouts; offline-only native drivers remain available through the existing generation API. Prices remain catalog metadata; the central subscription service described below now enforces trial expiry and simulated paid periods. Real billing remains deferred.

Local acceptance (creates a new disposable customer and makes a live model request):

```sh
python3 tools/gereq-provisioner/test_customer_flow.py \
  --socket /absolute/private/customer-flow.sock --root /absolute/private/test-state \
  --release local-approved-release --site disposable-customer --port 18147 \
  --template editorial-ai --report /absolute/private/customer-flow-report.json
```

For an interrupted/failed test, add `--resume-job job-...` with the same input and a new report path. It retries that exact workflow and verifies the instance secrets remain unchanged. The report never includes the setup token. Native JSON templates use a copy-and-palette-only model schema; their existing layout is validated and compiled from the catalog rather than asking the model to invent a second unused layout.

## Local customer onboarding screen

The Go executable also serves a small browser demo around the existing private customer workflow:

```sh
provisioner onboarding-ui \
  --root /absolute/private/onboarding-ui \
  --socket /absolute/private/central-api.sock \
  --listen 127.0.0.1:18150 \
  --release-id local-approved-release
```

Open `http://127.0.0.1:18150`. The central API must already be running with its AI configuration and approved image. The screen offers active compatible packages/templates, a business brief and owner email, inquiry or external booking goals, saved progress, explicit recovery/retry, and account-setup/preview/editor links. It creates drafts only. Payments, subscriptions and customer authentication for the central service are deferred to the gereq.cz integration.

This is a **local operator demo**, not a publicly accessible signup service. The listener accepts only loopback IPs, verifies the exact Host and mutation Origin, uses a session cookie and CSRF token, and exposes only the scoped onboarding actions through its adapter. The full operator API remains on its private Unix socket. The approved release is selected at startup. Site IDs and ports (18200–18299, skipping reserved/in-use ports) are assigned by the adapter; users cannot submit arbitrary release IDs, images or runtime configuration. Only compatible active templates are offered.

The private UI state retains request reservations and workflow IDs, so a disconnected submission can be replayed with the same identity. Browser storage contains only an opaque request identifier, not the email, brief or bearer setup link. Setup links are created only after an explicit button click, checked against the expected customer host and port, and used to open the password setup page. The customer enters the password themselves. Existing API tests and `onboarding_ui_test.go` cover catalog filtering, session/Origin/Host boundaries, replay, saved request recovery and cross-customer setup-link rejection. Browser verification covers required fields, booking URL validation, real generation, refresh recovery, account setup and desktop/mobile layout.

Customer names and local domains: the onboarding form suggests a `.localhost` address label from the business name (lowercase, without diacritics). It follows further name edits until the address is manually edited. “Použít doménu podle názvu” restores automatic suggestions. Labels are validated and duplicates are rejected before provisioning. The selected label becomes the new instance's local hostname; existing instances are not renamed. Custom production-domain routing remains deferred.

The combined creation flow derives the lower-level request's optional `brand_name` from `brief.name`. During bootstrap, the embedded `runtime/brand.php` initializer publishes exactly `brand.name` and `brand.site_name` and initializes the admin application name. A marker and checks for existing owner settings prevent retries from overwriting later customizations. The initializer uses the platform settings/publish services and runs from the Go binary, so approved local images do not need a rebuild. Its explicit `repair` mode is for a targeted operator correction and publishes only the two name keys, preserving unrelated staged content and palette changes. `test_brand.py` covers this against disposable SQLite instances; Go and Bun tests cover name propagation, domain validation/collisions and manual overrides.

## Local customer and instance management

The private central API now exposes a management projection of registered instances and customer creation workflows. Customers are grouped by the stable `customer_ref`; the display name and owner email come from the saved creation brief. Legacy instances without a creation workflow remain visible, but cannot issue owner access without a verified owner email.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/v1/management/overview` | Customers, instances, recent actions and shared busy state |
| GET | `/v1/management/customers` | Customer list |
| GET | `/v1/management/customers/{customer-id}` | Customer and their instances |
| GET | `/v1/management/instances` | Instance list |
| GET | `/v1/management/instances/{site-id}` | Instance details and available controls |
| GET | `/v1/management/jobs/{job-id}` | Sanitized lifecycle action status |
| GET | `/v1/management/workflows/{job-id}` | Sanitized customer creation status |
| POST | `/v1/management/instances/{site-id}/inspect` | Queue runtime, platform health and onboarding verification |
| POST | `/v1/management/instances/{site-id}/start` | Queue start and live verification |
| POST | `/v1/management/instances/{site-id}/stop` | Queue stop and live verification |
| POST | `/v1/management/instances/{site-id}/retry` | Resume the existing failed/interrupted creation workflow |
| POST | `/v1/management/instances/{site-id}/setup-link` | Recheck live onboarding and issue access only while setup is pending |

The inspect/start/stop/retry/setup-link actions above accept no body and require an `Idempotency-Key` (8–100 characters using the existing API key pattern). Start, stop and inspect return `202` with `{kind:"action",job:{id,...}}`; retry returns `{kind:"workflow",job:{id,...}}`. Poll the matching status endpoint. Lifecycle retries with the same key return the same job. Setup returns the bearer link only to the explicit caller; it is never included in inventory or saved in the management cache. Reissuing setup access replaces the previous link and is forbidden once onboarding is complete.

Saved provisioning status is distinct from observed runtime/setup status. New entries show `observation: null` until inspected. An observation includes its check time and is cached against the instance fingerprint; it is not a continuous monitor. Runtime ownership labels are checked before commands. Existing backup, provisioning and management operations share the same exclusion guard. No destructive delete or arbitrary runtime command is exposed through this adapter.

Run the local operator screen:

```sh
provisioner management-ui \
  --root /absolute/private/management-ui \
  --socket /absolute/private/central-api.sock \
  --listen 127.0.0.1:18152
```

Open `http://127.0.0.1:18152/`. The page supports customer/site search, customer grouping, live checks, start/stop confirmations, workflow retry, explicit setup-link issuance and links to the existing admin, preview and editor. It uses its own cookie so it can run alongside `onboarding-ui`. Host, Origin and CSRF validation protect the scoped browser adapter; the operator API remains on a private Unix socket. Public authentication, billing, production domains and integration into gereq.cz are still deferred.

Validation: `go test -race ./...`, `go vet ./...`, and `bun build management/app.js --target browser --outfile /tmp/gereq-management-check.js`. Management tests cover customer grouping, observed rather than assumed setup state, lifecycle idempotency, preserved workflow retry, completed/stopped account setup protection, secret omission, mutual exclusion, loopback binding, scoped routes, and browser-session/Origin/Host boundaries. Local browser acceptance uses a separate disposable Podman instance for stop/start; it does not restart existing customer sites.

### Management update controls

The local management page now offers approved release selection, a read-only plan, explicit update confirmation, live journal phases, recovery of an interrupted/failed update, and rollback of the latest completed update. All operations use the existing central job queue and shared exclusion guard. Registry and journal projections remain readable while the worker holds the mutation lock, including after reloading the page.

Additional scoped routes (same `/ui/` adapter, Host/Origin/CSRF rules):

| Method | Management endpoint | Payload/result |
| --- | --- | --- |
| GET | `/v1/management/releases` | Approved local release IDs and modules |
| GET | `/v1/management/instances/{site}/updates` | Latest/active update phase, backup readiness and recovery/rollback controls |
| POST | `/v1/management/instances/{site}/update-plan` | `{ "release_id": "approved-id" }` → source release, target release, exact image ID |
| POST | `/v1/management/instances/{site}/update` | `{ "release_id": "approved-id", "current_release": "source-id", "expected_image_id": "sha256:..." }` → central action |
| POST | `/v1/management/instances/{site}/update-recover` | `{ "accept_data_loss": false }`; acknowledgement must be true if the new runtime may have accepted writes |
| POST | `/v1/management/instances/{site}/update-rollback` | `{ "update_id": "upd-...", "accept_data_loss": true }` → central action |

Mutation requests require the existing `Idempotency-Key`. Update requests bind the displayed source release and the exact approved target image; the worker rechecks both. Module provenance downgrades are now refused during planning, before stopping the instance. The existing stopped-database module checks still run before and after migration. Planning also verifies that `restic` is on the central process PATH and that the private backup master key is configured. Successful update/recovery/rollback refreshes the observed platform health and onboarding status.

Raw journals, database data, environment values, backup credentials and runtime diagnostics are not returned through management. Recovery and rollback warnings explicitly explain that later writes are retained separately and are not automatically merged. Only the operator CLI exposes plaintext retained-data exports. Backup repositories and the master key must be preserved independently; production rollout, billing and automatic multi-customer deployment remain outside this local workflow.

Local acceptance uses a separate `update-test` instance and two existing images, upgrading CMS 2.64.0 to 2.65.1. Database/file markers, credential hashes, encrypted snapshot verification, rollback and retained-write export are checked. An intentional SQLite trigger on this disposable database exercises real update failure and recovery; it is removed before the final successful upgrade. Go tests also cover stale plans, changed tags, missing backup prerequisites, module downgrade refusal, readable inventory/journals under the mutation lock and strict UI payloads. Add the installed local restic directory to PATH when running the suite so encrypted snapshot tests execute rather than skip.

## Release registry and local image registration

The release catalog is now stored in the central service's private SQLite database, `$STATE/central-api/releases.sqlite`. There is no release JSON file to create for new versions. Start `central-api` with `--root` and `--socket`; `--release-catalog` is now an optional **one-time legacy import**. Existing files remain untouched for rollback, but are not read as the active catalog after import. Remove the legacy flag on subsequent starts. Imported entries retain their previous approval and are visibly marked as unverified legacy records until their local image is registered. Existing instance state and pinned workflow records are not rewritten.

On the management page, use **Verze platformy → Načíst lokální obrazy → Registrovat verzi**. The service inspects an installed image and reads its embedded GEREQ provenance. The release ID, platform version and module versions come from the image, not manual form fields. New registrations are pinned to the exact local `sha256:` image ID. The page passes the image ID it displayed, so a tag changed since selection is rejected. Registering the same image again is safe; reusing a verified release ID for another image/content is refused. Build a new release ID for new content.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/v1/local-images` | Installed tagged `localhost/gereq-local` images, without pulling |
| POST | `/v1/releases` | Register an installed image: `{ "image": "localhost/gereq-local:build-id", "expected_image_id": "sha256:..." }` |
| GET | `/v1/releases` | Active releases in the compatible provisioning response shape |
| GET | `/v1/releases/{id}` | Release details, exact image identity, versions and availability |
| PATCH | `/v1/releases/{id}` | Set `{ "active": false }` or `{ "active": true }` |
| GET | `/v1/management/releases` | All releases, including excluded and imported records |

Registration requires `Idempotency-Key`. `expected_image_id` is optional for direct operator clients and required by the local page's registration flow. Registration returns `201` for a new/first-verified record, or `200` for an existing matching record/replay. A conflicting ID/key returns `409`. Availability changes are naturally idempotent and audited alongside registrations/imports in the same SQLite transaction. Excluding a release removes it from new provisioning/update choices; existing accepted work, instances and stored rollback releases remain intact. Deletion and replacement of verified content are not exposed.

The scoped `/ui/releases` and `/ui/local-images` adapter retains Host, Origin, session-cookie, strict-body and CSRF protections. The central API remains on its private Unix socket. Only installed local development images with supported funnel module provenance can be registered here. CI, remote image upload/pull, registry authentication, public exposure and automated rollouts are deferred. Local registration does not build an image, migrate a customer or start a rollout.

The SQLite driver is `github.com/mattn/go-sqlite3`, pinned in `go.mod`/`go.sum`. Building this operator binary requires CGO and a C compiler; SQLite is bundled by the driver. Include the central database and any live WAL files in future operator-state backup plans. Customer-instance backup jobs do not back up the central service's registry.

Validation covers immutable/idempotent registration, stale image selection, invalid provenance, active/inactive choices, durable restart without catalog files, legacy verification, registry privacy, audit and strict browser-adapter input. Local acceptance registers existing Podman images through the page, toggles availability, restarts the service without its legacy catalog flag and provisions a disposable instance from a registered image.

## Local subscription and trial enforcement

The central service stores per-instance subscriptions, simulated payment receipts, enforcement policy and audit events in its private `$STATE/central-api/subscriptions.sqlite`. Existing instances are **unmanaged until explicitly enrolled**. Newly provisioned instances through the combined customer API or central create job automatically receive the selected package's trial. Trial days are pinned into the service-assigned request, so retries do not restart or extend a free trial. Package/customer identity comes from the instance, never from a subscription form.

An instance is entitled while `now < trial_ends_at` or `now < paid_until`. Expiry is effective at the exact UTC boundary. Start, resume, repeated create, generation and update/recovery operations check entitlement on acceptance and again in the worker. Setup-link retrieval is also gated. The background scheduler queues enforcement through the existing central job runner and shares its exclusion guard with provisioning, updates and backups.

Suspension stops the owned customer container and verifies that it is stopped. It does not remove or replace storage, uploads, databases, credentials or configuration. The management page remains available. This local implementation has no reverse-proxy suspension page: the customer's port becomes unavailable while suspended. Physical suspension waits for the current operator job; failed stop/start attempts remain visible and retry automatically. Privileged Podman/CLI access remains outside central billing authorization.

The service persists its intention to suspend **before** stopping a running instance. Renewal automatically starts only a runtime that billing stopped, preserving manual stops. Pending transitions and ownership survive a service restart. Runtime ownership and package/customer identity are rechecked before reconciliation. A failed or unverifiable runtime command is not reported as a completed suspension.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/v1/subscriptions` | Managed subscriptions with effective entitlement/status |
| GET | `/v1/subscriptions/{site}` | One subscription (`404` for unmanaged instances) |
| POST | `/v1/subscriptions/{site}/trial` | Explicit legacy opt-in: optional `{ "trial_days": 14 }` or RFC3339 `expires_at` |
| POST | `/v1/subscriptions/{site}/simulate-renewal` | `{ "term": "monthly" }` or `yearly`; records a simulated payment and queues restoration |
| POST | `/v1/subscriptions/{site}/simulate-expiry` | `{ "expire_after_seconds": 0 }`; use a short delay to test real clock-driven expiry |
| POST | `/v1/subscriptions/{site}/reconcile` | `{}`; explicitly queue a state/runtime check |
| GET | `/v1/subscription-policy` | Current enforcement interval and simulation setting |
| PATCH | `/v1/subscription-policy` | `{ "check_interval_seconds": 15, "simulation_enabled": true }` |

Mutations require `Idempotency-Key` except the naturally idempotent policy patch. Subscription state, audit and the simulated payment receipt commit together. Replaying a payment key never adds another period; reusing it for different input is rejected. Monthly/yearly periods extend from the later of now, remaining trial or existing paid-through date, with end-of-month/leap-year clamping. All receipts have `simulated: true`; no card data, payment processor or actual charge is involved. Disabling simulation blocks simulated renewal/expiry, while normal expiry enforcement continues.

The check interval is configurable from 1 to 3600 seconds (default 15); a changed interval is read on the next scheduler iteration. Trial length is configured in the existing package catalog (default funnel trial: 14 days). The management page exposes trial opt-in, effective deadlines, suspension state, month/year simulated renewal, delayed simulated expiry, retry and policy controls. It refreshes while idle without interrupting active form edits. Host, session, Origin, strict-body and CSRF protections remain in place.

Include this SQLite database and its WAL files in future central-service state backups; customer-instance backups do not include central subscription records. Package switching, real billing/webhooks, invoice handling, grace/dunning rules, production routing and the gereq.cz customer portal remain deferred.

Tests cover exact expiry boundaries, automatic trials, payment idempotency, manual stops, interrupted suspension recovery, worker-time checks, scheduler operation without client requests, failed stop retries, simulation disablement, calendar periods and browser-adapter security. Local acceptance uses a disposable instance: short timed expiry, background suspension, blocked restart, simulated renewal, runtime health and unchanged database/file/credential markers.


### Local package changes

`GET /v1/package-changes/{site}/options` lists compatible active packages and recorded usage. `POST .../preview` with `{"package_id":"..."}` creates a private 30-minute plan. `POST .../apply` with `{"plan_id":"pkg-..."}` and `Idempotency-Key` atomically updates the subscription's pinned package snapshot and audit record. The local management page exposes these actions under **Balíček a oprávnění**. An enrolled subscription is required; unmanaged legacy instances are unchanged.

Package records optionally accept `generation_limit` (nonnegative integer, null/omitted means unlimited) and `modules` (the supported funnel set `cms`, `site_inquiries`, `analytics`). A change requiring different runtime modules is queued as a recoverable module transition when the installed image supports it; this does not install shop/blog modules absent from that image. Editing a catalog package does not silently change existing subscription snapshots: preview/apply the new revision explicitly.

The generation allowance is **total central generation attempts per instance**, not a monthly allowance. Successful and failed attempts count; a replay of the same job does not count twice, and importing an already saved generation does not consume another attempt. Recording starts with this service version; older attempts are not reconstructed. Switching or renewing a package does not reset usage. Downgrades below recorded usage, stale previews, and templates outside the new package are rejected. Existing published/draft content is retained even if its template is no longer available for new generation. Package changes preserve customer files, runtime ownership, credentials, trial/payment dates and suspension state. Changes involving modules briefly stop the instance; metadata-only changes do not. Neither charges/prorates payments.

The service migrates its own private `subscriptions.sqlite` from schema 1 to 2 transactionally at startup, adding usage records. Customer databases are untouched. Plans are private JSON snapshots; package definitions are managed through the catalog API, without editing files manually.


### Package editor (local management)

The **Balíčky** section at `http://127.0.0.1:18152/#package-editor` creates and edits packages through scoped, CSRF-protected catalog API routes. It supports name/description, allowed modules and templates, explicit feature toggles, trial days, total generation allowance, currency, monthly/yearly prices, activation and archiving. Prices are stored as exact integer minor units; blank is unspecified, zero is free. Saves require the loaded revision, so a stale editor cannot overwrite a concurrent change. Existing subscription snapshots remain pinned until explicitly changed in the instance detail.

Supported central features are `website_generation`, `draft_import` and `instance_updates`. Missing keys preserve the legacy enabled default. Unknown feature keys are rejected, and disabling generation requires disabling draft import too. Recovery/rollback remains available when new updates are disabled. These switches govern the central service, not individual CMS controls inside customer instances. The combined customer-creation flow requires generation, draft import, and a positive generation allowance; packages without those features can still be assigned to existing sites.

Active website packages require `cms`. The bundled `site_inquiries` and `analytics` modules are optional; the current funnel generator additionally requires `site_inquiries` for its forms. Unsupported module lists may be saved as inactive drafts. This editor does not claim to enable shop/blog provisioning or remove modules from existing customer databases. Only implemented limits are editable; page/storage/module-specific limits require enforcement work before being offered.


### Package-selected module provisioning

New creation requests pin `enabled_modules` from the selected package alongside its revision and templates. `Release.modules` describes modules available in the image; it is no longer the activation list. New images advertise `module_selection: true` in image provenance and the release registry. A partial selection on an older fixed-module image is rejected before instance allocation. Package edits never silently change an existing instance's pinned modules.

The runtime receives the validated selection through its private environment, validates manifest dependencies before module activation, activates in dependency order, and verifies the exact enabled set during health checks. Optional analytics initialization is skipped when omitted, and dashboard presets omit widgets from unselected modules. Upgrades and module compatibility checks preserve the selected set rather than enabling the full image catalog. Missing image modules, duplicates and missing dependencies are rejected. Legacy instances without an explicit selection retain their original release module set.

Existing customer module changes now use the recoverable package-module workflow described below. This support covers newly provisioned websites with compatible modules present in the chosen image. It does not install arbitrary modules into images or enable shop/blog runtimes.


### Existing-instance package/module changes

Preview includes `module_change`, `source_modules` and `target_modules`. Metadata-only apply retains its synchronous response. A module-changing apply returns HTTP 202 with `{kind:"action",job:...}` and uses the shared durable job queue. The management page polls it and explains the brief downtime. The worker validates image availability and manifest dependencies, stops the instance, takes and verifies an encrypted storage/environment/state snapshot, enables added modules through the platform module manager/updater, and soft-disables removed modules without deleting data or prebuilt image assets.

The original request/fingerprint stays immutable; current modules are stored in `State.active_modules` and used by inventory, generation guards and later update checks. Trial/payment dates, credentials, content and module data are preserved. Dashboard registration naturally follows the enabled providers, retaining saved widget preferences. Added analytics receives its initial site configuration.

The target is checked in an isolated one-off container with no published ports before the central subscription is committed. If an earlier step fails, the worker restores the encrypted pre-change snapshot and original runtime state. If startup fails after commit, recovery completes the committed package instead of rolling back over subsequent customer data. A durable instance marker blocks conflicting operations after interruption. The local management page exposes **Obnovit změnu balíčku**, backed by `POST /v1/package-changes/{site}/recover` with an idempotency key; recovery never requires an unexpired preview. Verified recovery snapshots remain encrypted, private temporary plaintext copies are removed, and interrupted decrypted verification stages are cleaned at service startup.

A manually stopped instance stays stopped. Expired subscriptions must be renewed before a module transition. Images without module-selection support must be updated first; modules absent from the installed image are rejected. Disabling inquiries makes existing inquiry forms unavailable while retaining their stored content and submissions.


### Local management tabs

The management demo remains one page, with tabs for instances, customers, packages, releases, jobs and settings. Instance details use separate overview/package/subscription/release/access/history tabs. Package edits remain mounted when switching main tabs, and instance input selections survive detail refreshes. Legacy instance and package-editor bookmarks are supported. Job lists and instance histories have bounded, keyboard-focusable scroll areas. Subscription checking remains on the existing interval for local expiry testing; no daily scheduler change was made.

## Customer account facade

The local management page now includes a separate customer-view tab. See
[SELF_SERVICE.md](SELF_SERVICE.md) for account/session security, ownership rules,
customer routes, explicit operator assignment and local acceptance coverage.

Customer email verification and password recovery, including the local inbox
simulator, configuration and migration, are documented in [EMAIL_AUTH.md](EMAIL_AUTH.md).

AI layout generation now assigns required native heading, CTA and form roles before
strict validation, so model cardinality mistakes do not discard an otherwise valid
layout. Customer workflow errors expose safe categories while private diagnostics
retain details. The previously failing local `test` workflow resumed successfully.

## Backend module catalog and local audit

Set `GEREQ_PLATFORM_SOURCE` to the canonical backend source directory. Package capabilities are exported from its ModuleDiscovery metadata, including disabled modules and dependencies; unavailable exporters fail closed. Catalog choices are separate from release-image capabilities. New instances use the Administrator default, and generated page titles include the business and headline.

See [the local audit](SAAS_AUDIT_2026-10-29.md) for verified fixes, release evidence and remaining production requirements.

## Local domain registry

The management page now has a **Domény** tab for fallback subdomains and simulated custom-domain verification/certificates. New central customer creations automatically reserve `<site-id>.studio.gereq.cz`. A loopback-only `domain-router` on port 18154 resolves active registered hosts to customer instances, with primary redirects and fail-closed unknown/stopped/expired routing. See [DOMAINS.md](DOMAINS.md) for the API, startup command, testing instructions and simulation boundaries.

## Integration priorities

See [INTEGRATION_PLAN.md](INTEGRATION_PLAN.md) for the company-app integration sequence and the deferred managed DNS/mail decision.
