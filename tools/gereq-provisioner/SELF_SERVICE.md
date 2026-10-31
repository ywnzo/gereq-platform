# Customer accounts and self-service API (local prototype)

The customer API is a separate, authenticated facade over the private central
operator API. It does not give customers access to operator routes. The local
management page has a **Pohled zákazníka** tab at
`http://127.0.0.1:18152/#customer-view`.

## Boundary and storage

- `central-api/accounts.sqlite`, mode `0600`, holds accounts, explicit site
  ownership, sessions, creation reservations, account policy and assignment events.
  Include this file in central-service backups; it is not part of customer images.
- Passwords are salted PBKDF2-HMAC-SHA256 hashes with 600,000 iterations. Accepted
  password length is 12–128 UTF-8 bytes. Passwords are never written to jobs,
  instances, logs or API responses.
- Sessions last 12 hours. The database stores only the SHA-256 token digest.
  Logout revokes the current session immediately; expiry is checked on every call.
- Login/signup are rate limited using persistent ten-minute buckets: 8 requests
  per normalized email/action and 40 combined requests per gateway client address.
  The trusted local gateway derives the address from its socket peer and ignores
  caller-supplied forwarding headers.
- Mutations require a session-derived `X-CSRF-Token`. Customer gateway mutations
  also require an exact same-origin `Origin`. Signup/login use the local page's
  initial CSRF token and exact Origin before a customer session exists.
- The local gateway keeps the session token in a separate `HttpOnly`,
  `SameSite=Strict` cookie scoped to `/ui/self-service/`. It removes the token from
  JSON responses and ignores browser-provided bearer tokens and the operator cookie
  when determining customer identity. No tokens are placed in localStorage or URLs.
- All ownership checks use account IDs and stored customer references. Existing
  websites are **not** claimed by matching email addresses or business names.
  Foreign and missing resources both return 404, including setup links and jobs.
- Customer responses omit owner references, deployment paths, image settings,
  internal failure diagnostics and template block payloads.

## Customer routes

All routes start with `/v1/self-service/`. The Unix-socket API uses
`Authorization: Bearer <session_token>` after signup/login. Authenticated mutations
also require the `csrf_token` from the session response. The local browser gateway
exposes only the allowlisted equivalents under `/ui/self-service/` and supplies
bearer authentication from its customer cookie.

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `signup` | `{name,email,password}`; create account and session |
| POST | `login` | `{email,password}`; create session |
| GET | `me` | Account, expiry and CSRF token; never returns the bearer token |
| POST | `logout` | Revoke current session |
| GET | `catalog` | Active, compatible packages, prices and template descriptions |
| GET | `sites` | Only the authenticated account's websites and progress |
| GET | `sites/{site_id}` | One owned website |
| POST | `sites` | Provision and generate a new draft |
| POST | `sites/{site_id}/retry-creation` | Resume a reserved creation after interruption or a busy service |
| POST | `sites/{site_id}/setup-link` | Explicit first-time platform owner setup; not password reset or SSO |
| GET | `subscriptions` | Only the authenticated account's subscriptions |
| GET | `subscriptions/{site_id}` | One owned subscription, dates and price snapshot |
| GET | `jobs/{job_id}` | Progress of an owned creation workflow |
| POST | `jobs/{job_id}/retry` | Retry an owned failed/interrupted creation workflow |

`POST sites` requires an `Idempotency-Key` (8–128 allowed ASCII characters).
The server namespaces keys by account, so two accounts may safely use the same key.
Reusing a key with different input returns 409. A creation body is:

```json
{
  "domain": "example-studio",
  "package_id": "funnel-v1",
  "template_id": "editorial-ai",
  "brief": {
    "schema_version": 1,
    "name": "Example Studio",
    "offer": "Consultation for a new garden",
    "audience": "Owners of smaller gardens",
    "language": "en",
    "slug": "home",
    "goal": "inquiry",
    "booking_url": "",
    "facts": []
  }
}
```

The UI derives the domain from the business name until the domain is manually
edited. An omitted domain is derived on the server. Domain labels are validated,
not arbitrary hostnames. The server chooses a verified active compatible release,
a free local port, the authenticated account's email and immutable customer
reference. Client-supplied owner, email, port and release fields are rejected.
Publication remains manual. Account creation limits count persistent reservations
as well as provisioned sites. A busy response may leave a reservation; the website
card then offers **Pokračovat ve vytvoření** without needing the original browser
idempotency key. Retries reuse the same stored request and central workflow.

## Private operator controls

These are Unix-socket operator endpoints, not customer routes:

- `GET /v1/customer-accounts`: account IDs, names, emails and creation timestamps.
- `POST /v1/customer-accounts/{account_id}/sites` with `{ "site_id": "..." }`:
  explicitly assign a valid existing instance. Assignment is unique and audited;
  this endpoint does not transfer ownership or claim sites by email.
- `GET /v1/customer-account-policy` and `PATCH` with
  `{ "max_sites_per_account": 5 }`: configure a limit from 1–100. The management
  **Nastavení** tab exposes this field. Reducing the limit preserves existing sites.

## Local acceptance and remaining integration

`self_service_test.go` covers two accounts, account-scoped creation with a fixture
generator, same-key isolation/replay, website/subscription/job isolation, setup and
retry denial, explicit adoption, identity mismatch, password login/throttling,
CSRF, expiry/logout, token hashing, strict gateway allowlisting, cookie separation,
quota enforcement and interrupted reservation recovery.

The running local API was separately exercised with two real disposable Podman
instances (`self-account-a:18418`, `self-account-b:18419`) and two random-password
accounts. Website/subscription lists and details, cross-account setup denial,
forbidden billing mutations, gateway login/cookie isolation and logout passed.
The runtime isolation test used the operator create route plus explicit ownership
assignment to avoid paid AI calls; the Go creation tests use the fixture generator.
The secret-free acceptance report is under the local runtime directory at
`customer-flow-test/customer-self-service-report.json`.

The management listener is an **operator test page**, including the other privileged
tabs. It binds to loopback and must not become the public customer portal. A future
public gateway must expose only the customer allowlist, use HTTPS and Secure
cookies, and leave the operator API private. Email verification and password recovery are described in [EMAIL_AUTH.md](EMAIL_AUTH.md).
Real email delivery, real payments and the gereq.cz account/frontend integration remain
separate work. Customer subscriptions are read-only here; customers cannot invoke
operator payment simulation, package-module edits, instance stop/delete or releases.

Verification is now required for customer creation/setup mutations. Existing account
schema 1 is migrated to 2 with a private snapshot; see [EMAIL_AUTH.md](EMAIL_AUTH.md).
