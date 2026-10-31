# Customer email verification and password recovery

This implementation is local-only. It uses the built-in inbox simulator; it does
not contact SMTP or send external emails. Customer operations are available in
**Pohled zákazníka**, and the operator inbox is **Testovací pošta** on the same
management page. Mail settings are in **Nastavení → Zákaznické e-maily**.

## Customer flow

1. Signup creates an unverified account and a verification message atomically.
2. The customer opens the email link and explicitly confirms it. GET/prefetch does
   not verify or consume a token. Verification does not sign in another account.
3. Only a verified account may create a site, resume/retry creation, or request
   first-time platform owner setup. Existing websites/subscriptions remain readable.
4. **Poslat ověřovací e-mail znovu** sends a replacement link and invalidates the
   previous unused verification links. **Obnovit** refreshes verification status.
5. **Zapomenuté heslo** accepts an email and always returns the same accepted
   response for known and unknown accounts. No account ID is supplied by callers.
6. Recovery requires the token, a new password and matching confirmation. On success
   the password is rehashed with a fresh salt, every session for that account is
   revoked, and all outstanding account challenge links are invalidated. Other
   accounts and customer-instance passwords are unchanged. Sign-in is explicit.

Both mail purposes use random 256-bit tokens. `account_challenges` stores only
SHA-256 digests. Tokens are purpose-bound, expire, and are consumed atomically;
concurrent requests cannot consume a token twice. Credential versions prevent an
old-password login that overlaps a reset from creating a new valid session.

Links use a configured trusted loopback origin, never the request Host header:
`/#customer-view/verify/<token>` and `/#customer-view/reset/<token>`. The customer
module removes the token from the address bar/history entry as it loads, keeps it
only in memory, and sends it only in the explicit confirmation POST body. Reloading
an unconfirmed action requires reopening its email link. No tokens are returned
from signup, resend or recovery-request responses except the existing login session
bearer returned by the private Unix API; that bearer is stripped by the UI gateway.

## API

The following extend `/v1/self-service/` and the local allowlisted
`/ui/self-service/` gateway:

| Method | Route | Request / response |
| --- | --- | --- |
| POST | `verification-email` | Authenticated session + session CSRF; `{}` resends verification |
| POST | `verify-email` | `{ "token": "..." }`; explicit confirmation, no automatic login |
| POST | `request-password-reset` | `{ "email": "..." }`; generic accepted response |
| POST | `reset-password` | `{ "token": "...", "password": "...", "password_confirmation": "..." }`; revokes all account sessions |
| GET | `me` | Adds `account.email_verified` |

The local gateway requires its initial page CSRF token and exact same-origin
`Origin` for the three unauthenticated email actions. It requires session CSRF for
resend. Reset completion also expires the browser's customer cookie. Existing
operator cookies do not identify a customer, and customer API routes cannot read
mail configuration or the inbox. Unverified creation mutations return 403 with
`code: email_verification_required` before reserving a domain or starting work.

Verification resend is limited to three requests per account per ten minutes.
Recovery requests are limited to three per normalized email per ten minutes and
20 per gateway client address. Confirmation attempts are limited to 30 per client
per ten minutes. Per-email recovery throttling retains the same generic response.
Existing login/signup throttling and strict JSON/body-size limits also apply.

## Operator configuration and simulator

`GET` / `PATCH /v1/customer-mail-policy` are private operator routes. PATCH replaces
this configuration; the management page exposes the same fields:

```json
{
  "mode": "simulator",
  "customer_origin": "http://127.0.0.1:18152",
  "verification_ttl_minutes": 60,
  "reset_ttl_minutes": 30,
  "retention_hours": 24
}
```

The default mode is `disabled`; new signup and recovery requests return 503 until
mail is configured. Modes are deliberately limited to `disabled` and `simulator`.
The simulator origin must be loopback HTTP without userinfo, path, query or
fragment. Verification TTL is 5–1440 minutes, recovery TTL 5–120 minutes, retention
1–168 hours and at least as long as both TTLs. Changes affect newly issued links.

- `GET /v1/mail-simulator/messages`: newest 100 message headers, no body or token.
- `GET /v1/mail-simulator/messages/{mail_id}`: private operator detail with body,
  action link and active/used/expired status.
- The local management proxy exposes these only under the operator session, not
  under the customer allowlist. The inbox clearly identifies its operator scope.
- Simulator messages necessarily contain their action links. They are stored only
  in the private mode-0600 accounts database and its private SQLite journal, never
  in customer instances, public API responses, access-log URLs or application logs.
  Message retention is enforced on inbox reads and message issuance; expired
  challenge records are pruned on issuance after their retention window.

## Account migration and rollback

`account_email_migration.go` upgrades account schema 1 to 2 transactionally. It adds
verification/credential-version columns and challenge/mail tables. Existing accounts,
password hashes, ownership and sessions are preserved; existing accounts start
unverified and can request verification from the customer view.

For an existing schema-1 database, startup first makes a consistent SQLite
`VACUUM INTO` snapshot at `accounts.sqlite.before-email-v2.sqlite`, mode 0600.
Fresh databases do not need that snapshot. Restarting schema 2 does not remigrate.
Downgrading the binary is not automatic: schema-1 code rejects schema 2. A rollback
must explicitly restore a coherent snapshot while the central service is stopped,
and would lose changes after that snapshot. Do not restore it over live user data.
No platform/module schema or customer-instance image is changed by this feature.

## Validation

`account_email_test.go` covers verification gating, resend, expiry, purpose
separation, replay, concurrent recovery consumption, password confirmation, generic
responses, all-session revocation, unrelated-account isolation, stale-login races,
retention, trusted callback validation, private inbox access, CSRF, cookie expiry
and repeat-safe migration with a private snapshot. Existing ownership tests now
verify their fixture accounts through the real email-confirmation route.

The running local API was tested with two disposable accounts and an independent
HTTP cookie jar. No paid AI request was used for email testing. The browser checked
the simulator inbox, explicit email confirmation, removal of tokens from the URL,
and the recovery form. Password entry/reset was exercised through the HTTP API.
The secret-free runtime report is `customer-flow-test/email-recovery-report.json`
under `/home/ywnz/Personal/work/gereq/saas/var/`.

Real email delivery, production-domain gateway/HTTPS configuration and integration
into gereq.cz remain later work. The loopback management page is still an operator
simulation surface containing other privileged tabs; it is not a public portal.
