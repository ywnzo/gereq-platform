# Local SaaS audit — 2026-10-29

## Outcome and scope

A separate evidence-based audit pass covered the central Go API, local management/onboarding UIs, accounts and recovery, customer ownership checks, subscriptions and package changes, release/update paths, generation/import/publishing boundaries, backup scheduling/retention/credential handling, container lifecycle and relevant backend module-management entry points. This is a local code/runtime audit, not third-party certification or proof that the whole platform is vulnerability-free.

All 17 previously running customer instances were stopped. Data, uploads and containers were retained. Lifecycle state and subscription manual-stop flags were reconciled. No customer containers were left running. Central API and local test pages remain available.

## Requested changes

- Fresh administrators are named `Administrator`. Compatibility initialization replaces only the exact legacy default `Instance owner` while setup is pending; chosen names are preserved.
- Generated page titles combine business name and generated headline, with a Unicode-safe length limit.
- Package capabilities now come from the actual backend ModuleDiscovery exporter: 61 available modules/integrations, including disabled modules and dependencies. The management editor displays them in a scrollable list, selects dependencies and labels image availability. Unknown modules and missing dependencies are rejected by the API.
- Catalog availability does not imply deployability: the current verified image contains CMS, Site Inquiries and Analytics. Image checks still reject packages requiring absent modules.

## Security findings fixed

### High: customer module enablement bypass

A real isolated CMS-only backend fixture could enable Analytics outside its package through ModuleManager. The new operator-controlled ManagedModulePolicy enforces package entitlements at boot and module enable/disable/update/version-management boundaries. Managed web requests cannot mutate modules; trusted provisioning CLI transitions remain supported. Persisted database module checks independently detect drift rather than hiding it behind boot filtering. Regression tests cover bypass, transitions and malformed policy.

### Medium: known Symfony Mime vulnerability

Composer advisory PKSA-wtxr-p26d-nn42 / CVE-2026-45070 affected locked symfony/mime v7.4.18 (invalid MIME parameter names could inject headers). Updated only this dependency to v7.4.22. The final Composer scan, including development dependencies, reports no advisories or abandoned packages. Official advisory: https://symfony.com/cve-2026-45070

## Patched local release

Registered `local-cb9f364-52cf6e1b62be`, image `localhost/gereq-local:local-cb9f364-52cf6e1b62be`, ID `sha256:bebf1b7e9a47fa9875bca130b3982d4652ded582a7f8f65180550b0fd1e86b11`. A network-isolated, read-only one-shot verification confirmed patched Mime, package guards, raw module-drift checks and Administrator default. Source/staged file hashes matched before registration. Three older active releases were deactivated, retained for inspection. The onboarding test service now selects the patched release for future provisions.

Existing customer images were deliberately NOT updated: they remain stopped and must be upgraded before being served again. No customer database was rewritten for this audit.

## Verification

- Full Go race suite passed; go vet passed; govulncheck reported no vulnerabilities.
- Full PHP core suite: 359 tests, 1,527 assertions passed. Focused module-policy tests and PHPStan/PHPCS checks passed.
- CMS, Site Inquiries and Analytics module suites passed independently.
- Runtime bridge: 20 tests passed; brand initialization: 3; release preparation: 3.
- Management/onboarding JavaScript: 9 tests, 49 assertions passed; management Bun bundle passed.
- Browser verified 61 module checkboxes, original funnel selections preserved, Shop availability warning and scrollable module list. No package permissions were saved during this inspection.
- All 17 stopped instances passed private environment-file permissions, loopback port binding, read-only filesystem, dropped capabilities and private-network checks. No legacy access.json credential files remained.
- Targeted source credential-pattern scan found no matches; this is not a repository-history or compromise investigation.

Local PHP lacks GD and does not enable iconv by default. Dependency resolution ignored only the pre-existing missing GD extension; tests used an isolated temporary iconv configuration. No system PHP configuration was changed.

## Remaining production gaps

1. Local management/onboarding listeners deliberately issue operator sessions to local visitors. Keep them loopback-only; replace this test boundary with authenticated gereq.cz/admin-v2 integration before any public exposure.
2. Upgrade stopped customer instances to the patched image before serving them. Old image releases are not retroactively patched.
3. Production runtime, TLS/routing/domain ownership, pinned base images and CI provenance/signing require deployment-specific review. The local PHP development runtime is not a production deployment plan.
4. Customer backup tests do not establish central-service disaster recovery. Schedule encrypted central-state backups (accounts, subscriptions, registry/catalog) and test restoration with master-key recovery/rotation before real customers.
5. Real SMTP delivery and billing/webhook reconciliation remain unintegrated; current local simulations do not verify those providers.
6. Per-package generation limits exist, but an unset limit is unlimited. Add finite production quotas, global spend controls and public-signup/job-admission rate limits before exposure.
7. Additional packages require runtime images containing their selected modules and dependencies; listing all modules alone does not deliver shop/blog functionality.
8. Frontend image footprint includes roughly 645 MiB of material icon assets. Optimize deliberately without breaking dynamic icon loading.
9. Draft public roots return 404 until publication. Customer UI should clearly distinguish builder/preview links from published website links.

No server deployment, live AI generation, full customer recreation after the patched build, OS/base-image advisory scan, full repository-history secret scan or external penetration test was performed. No unrelated working changes were committed, stashed or discarded.

## Evidence

Private secret-free reports and test logs: `/home/ywnz/Personal/work/gereq/saas/var/customer-flow-test/audit-20261029/`. Runtime-wide inventory reports are observations at audit time; rerun before deployment. Credentials, environment files, customer databases and token-bearing inbox data are excluded.
