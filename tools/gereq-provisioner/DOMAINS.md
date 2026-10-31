# Local domain registry and routing

This implementation is local simulation only. It performs **no DNS queries, DNS-provider writes, ACME calls or real certificate issuance**. HTTP is used on the loopback gateway. Do not expose these operator/test listeners publicly.

## Running

The existing central API initializes a private SQLite registry at `$STATE/central-api/domains.sqlite`, including its mutation audit trail. Existing central-state backups must eventually include this database. Hostnames are globally unique within this central service; writes use transactions and revision checks. Custom-domain removal retains an audit record, disables routing and requires fresh proof on reattachment.

```sh
provisioner domain-router --root "$STATE" --socket "$SOCKET" --listen 127.0.0.1:18154
```

The router is restricted to this loopback address and port for the prototype. The development base is `studio.gereq.cz`; the simulated ingress target is `ingress.studio.gereq.cz`. These are the chosen address names; the current router remains loopback-only and does not configure public DNS. A shared production router/domain-provider adapter is a later step.

Open the **Domény** tab on the local management page: http://127.0.0.1:18152/#domains. Its adapter retains existing Host/session/Origin/CSRF checks. It can assign a fallback subdomain, attach a custom domain, enter simulated DNS observations, simulate certificate success/failure, select a primary domain and remove custom domains. Custom domain attachment creates a fallback automatically if the legacy instance lacks one. Subdomains cannot be deleted; a second fallback for the same site is rejected.

New central/customer creation workflows automatically reserve `<site-id>.studio.gereq.cz` before provisioning. The existing onboarding flow derives the site ID from the business/domain name. Reservation survives a failed job for its retry; it is not automatically given to another customer. Legacy instances receive addresses only when explicitly assigned; no customer containers are started by domain assignment. Existing workflow URLs remain available; the new routed URL is shown in the domain tab.

## Private operator API

| Method | Path | Body / purpose |
|---|---|---|
| GET | `/v1/domains` | Domain records and explicit simulation metadata |
| POST | `/v1/domains` | `{site_id,kind:"subdomain",label}` or `{site_id,kind:"custom",hostname}` |
| POST | `/v1/domains/{hostname}/simulate` | `{revision,txt_value,dns_target,certificate_ok}` |
| POST | `/v1/domains/{hostname}/primary` | `{revision}`; only active domains |
| DELETE | `/v1/domains/{hostname}` | `{revision}`; custom domains only |
| GET | `/v1/domain-route/{hostname}` | Private router resolution; never a public API |

Management equivalents use `/ui/domains` with the same suffixes and JSON bodies. These are operator APIs, not customer-facing ownership authorization. Future Laravel integration must check account membership before accessing them. Registry ownership uses explicit site IDs, not matching email or display names.

Custom states: `awaiting_dns` → `issuing_certificate` → `active`. TXT must match the generated `_gereq-verification.<hostname>` value, and DNS must match the displayed ingress target. A simulated successful certificate cannot bypass failed DNS ownership/routing checks. Subsequent failed validation revokes activation. No polling or real certificate expiry/renewal is implemented in this simulation.

On primary removal or failed revalidation, the original subdomain becomes primary. Other active aliases redirect with HTTP 307, preserving method, path and query. Unknown/unverified/removed hosts return 404; known hosts on stopped, updating or non-entitled instances return 503. Successful resolution uses indexed hostname/site lookups, validated persisted loopback ports and only that site's registered primary address. Client-provided forwarding headers cannot choose an upstream. The development adapter maps exact same-origin Origin/Referer and backend redirects to support existing instance forms/auth; it is not a production trusted-proxy configuration.

## Custom-domain testing without real DNS

Use reserved `.test` names and a per-command local override:

```sh
curl --resolve garden-demo.example.test:18154:127.0.0.1 \
  http://garden-demo.example.test:18154/patched-funnel-check
```

The browser needs a local resolver/hosts entry for arbitrary `.test` names. The assigned `*.studio.gereq.cz` names also require a local resolver/hosts entry or `curl --resolve` while testing locally. Do not publish an actual DNS CNAME to this local ingress name. Apex routing is simulated as a target match; real A/AAAA/ALIAS validation, public-suffix/registrable-domain handling and provider-specific instructions are deferred.

## Verified locally (2026-10-30)

- Automatic assignment in central customer-creation regression fixtures.
- Actual `update-test` storefront served through its registered subdomain and custom host (simulated DNS/certificates).
- Conflicting reservations, reserved names, wrong TXT, wrong DNS target, missing certificate, stale revisions and unknown hosts rejected.
- Primary redirects, DNS revalidation failure, fallback restoration and custom removal/reattachment.
- Stopped customer's registered hostname returns 503 without starting it.
- Actual public form submission and idempotent replay through the proxy; exactly one inquiry persisted.
- Management tab/assets and add/remove HTTP adapter; mutation rejected without CSRF.
- Go race tests, go vet and management JavaScript tests/build.

Browser automation was unavailable during this implementation: rendered layout and interactive browser navigation of the new tab were not visually verified. Acceptance evidence is stored at `/home/ywnz/Personal/work/gereq/saas/var/customer-flow-test/domain-routing-report.json`. Only the pre-existing disposable `update-test` customer was left running; other customer containers remained stopped. No customer image rebuild, production deployment or real DNS/certificate change was performed.

Schema v2 migrates earlier `.sites.localhost` fallback assignments to `.studio.gereq.cz` transactionally. Conflicts abort the migration. Custom domains whose simulated ingress target changed require simulated DNS/certificate verification again.
