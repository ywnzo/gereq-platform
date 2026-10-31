# SaaS integration priorities

Decision confirmed with the user on 2026-10-30.

## Current priority

Make the existing SaaS usable through the company application, tested locally before deployment. The company application at /var/www/GEREQ is Laravel 13/PostgreSQL, with Vue/Inertia storefront and React/Ant Design Admin V2. Customer instances use the separate modular GEREQ_PLATFORM and front-vue. Do not implement company SaaS controls as a customer-platform module.

1. Prepare a local company-app checkout, preserving its existing remote working changes; use isolated configuration, synthetic data and simulated external services. Do not copy production secrets or customer databases.
2. Integrate a private Go-service client into Laravel and a read-only SaaS instance overview into Admin V2, using existing staff authentication and explicit SaaS authorization.
3. Extend the admin UI to the existing customer/instance, package/template, release, subscription and domain APIs; retain Go as the authoritative operational service rather than duplicating its state machines.
4. Add the customer website-creation and management flow through the existing public frontend. Resolve customer identity and explicit account/site ownership with Laravel; do not synchronize passwords with Go or grant customers staff access.
5. Test two-account isolation and one disposable site's creation, setup, generation, publishing, inquiry intake and simulated subscription lifecycle. Keep unrelated instances stopped. Deployment requires a later explicit instruction.

## Deferred: managed custom-domain DNS and mail

The accepted future direction is GEREQ-managed authoritative nameservers for customer custom domains, connected to a managed mail service and Jirka's existing nginx-proxy. This is intentionally deferred while the SaaS integration is completed.

- Customers can delegate their domains to GEREQ nameservers.
- GEREQ provisions DNS zones/records, web routes/TLS and mail domains/mailboxes through separate DNS, proxy and mail adapters.
- Use established DNS and mail server software, with redundant authoritative DNS infrastructure; implementing DNS or SMTP protocols from scratch is not the plan.
- Import and verify existing DNS before delegation, preserving third-party mail and other services. Mail provisioning manages MX, SPF, DKIM and DMARC as appropriate.
- Nameservers provide DNS only; mail delivery/storage, spam filtering, reputation and recovery need separate infrastructure.
- Confirm Jirka's selected DNS/mail infrastructure before specifying adapter contracts or implementing this phase.
- Generated site addresses remain <name>.studio.gereq.cz. Current DNS/certificate checks and hostname routing remain local simulation.

Central disaster-recovery work and global AI spending controls were separately deferred by the user; do not treat them as completed or silently reinsert them ahead of the requested integration.

### Admin V2 operator creation adapter (local, 2026-10-30)

The company checkout now calls private `GET /v1/management/creation/options` and `POST /v1/management/creation`, then existing customer workflow progress/retry/setup routes. Options reuse `selfCatalog`; submission reuses verified compatible `selfRelease`, shared port allocation, brief/package/template validation and the original customer workflow. Private `central-api/management-creation` request reservations preserve runtime choices across busy/lost-response retries and reject changed payloads. These reservations also participate in self-service port selection. Go remains the operational authority; Laravel owns staff authentication/CSRF, request validation and response projection. No public account linkage is inferred from an email.

Verified through actual local Laravel HTTP requests on one new disposable instance `admin-v2-test-ad5db2`; workflow `job-2b0248dc06905de8f39c0f02` completed native-template generation/import as a draft and passed health inspection. Every other customer container is stopped. Company-side report: `/home/ywnz/Personal/work/gereq/saas/var/company-checkout/saas-creation-acceptance.json`. No server deployment, DNS changes or new image build.

### Staff draft preview and workflow details (local, 2026-10-30)

Private `GET /v1/management/instances/{site}/preview` reads the completed workflow's generated native page through an embedded constant PHP CLI helper. Engine ownership checks, runtime state and global execution locks are preserved; a preview never starts a stopped customer. The helper selects staging, uses native CMS serialization and the current brand palette, and reports actual production visibility. It performs no publish, schema, account or runtime-image changes. Go returns only the page snapshot and fixed owned-instance storefront origin, not credentials or raw diagnostics.

The company admin-only HTML wrapper reuses the existing storefront's read-only postMessage preview bridge in a sandboxed cross-origin iframe. Existing/future compatible customer images work without a rebuild. Instance details now show workflow, publication state, setup and distinct preview/builder/public-site controls. Full Go tests and 34 company SaaS tests pass; real local HTTP/isolated wrapper-handshake acceptance succeeded on the already-running disposable site. Browser visual rendering was unavailable and remains unverified. All other customer instances remain stopped.

### Admin V2 release/update management (local, 2026-10-30)

The company admin now adapts the existing release registry, local image inventory, availability, nonmutating update plan, update journal, update/recovery/rollback and site-bound job APIs. Selection pins the target image and expected source release; backup restoration requires explicit data-loss acknowledgement. The Go management adapter now replays completed rollback/recovery jobs before journal-state preconditions, preserving exact request hashes; full Go regression coverage includes both replays.

Live local acceptance updated only `admin-v2-test-ad5db2` to metadata-only fixture `local-admin-smoke-4dfad71706`, then restored its original `local-cb9f364-52cf6e1b62be` image and identical native draft snapshot. Backup readiness, acknowledgement guards, replay, health and other-customer stop state were verified. The smoke release is inactive afterward. No production change, CI integration or application-code version upgrade. Company acceptance report: `/home/ywnz/Personal/work/gereq/saas/var/company-checkout/saas-update-acceptance.json`.

### Image archive import for Admin V2 (local, 2026-10-30)

Private `/v1/image-imports` now accepts streamed checksum-verified Docker-save tar archives (including Podman docker-archive) up to 2 GiB. It validates safe outer paths, the single image's namespace/legacy tags and only standard Podman legacy layer aliases; it does not extract members. Existing tags are protected. A persisted img-* job runs CLI load in the background under normal execution exclusion, with same-key replay, lost-response lookup and archive cleanup/restart interruption handling. The company admin upload UI streams files and polls the import, then leaves provenance verification/release registration to the existing explicit action. Nothing is deployed automatically.

Full Go suite and 67 Laravel tests pass. A real small image archive roundtrip through Laravel/Go/Podman verified identity, replay/recovery, overwrite/invalid-file rejection and cleanup. The test fixture was removed, registry/customer states stayed unchanged and other instances remain stopped. No full-size throughput or visual-browser verification was performed. Company report: `saas/var/company-checkout/saas-image-upload-acceptance.json`. Local PHP body/temp settings were configured only in the existing local runtime; production ingress/FPM configuration is deferred.
