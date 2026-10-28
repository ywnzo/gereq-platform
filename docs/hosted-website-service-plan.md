# GEREQ hosted website service — implementation plan

**Status:** Proposed; product scope, pricing and infrastructure choices are not yet agreed. Code-specific findings below were inspected in an older checkout and must be rechecked against this checkout before implementation.

## Goal

Let customers sign up, choose a funnel, blog or ecommerce package, start a 14-day trial or paid subscription, and create an AI-assisted site backed by GEREQ Platform.

## Proposed approach

- Start with an isolated GEREQ instance and database per customer, plus isolated files, credentials, cache and sessions. Use a shared, versioned Vue storefront with customer-specific content and branding.
- Add a control plane for accounts, plans, monthly/yearly billing, trials, entitlements, provisioning, domains and customer lifecycle. Evaluate the existing server hosting prototype before deciding whether to reuse it.
- Use AI to generate validated CMS blocks and theme settings as drafts. Customers review, edit and publish through the existing CMS pipeline.

## Delivery steps

1. **Choose the first package and scope.** Define its features, quotas, trial expiry, data retention, target customer and prices using measured operating costs.
2. **Prove isolated provisioning.** Automate a clean installation, database, storage, secrets, workers, backups, customer subdomain, TLS and safe retries. Test isolation and restore on a staging environment.
3. **Build the control plane.** Implement signup, subscription and webhook reconciliation, trial lifecycle, server-enforced plan entitlements and usage limits. Keep customer shop payments separate from GEREQ subscription billing.
4. **Build the site generator.** Create a validated block-generation service integrated with CMS staging, preview and publish. Existing AI page tools do not create blocks and bypass the CMS controller's staging path; resolve this before reuse.
5. **Complete the storefront for the chosen package.** Add navigation, responsive templates, SEO and the required blog, funnel or shop flows. For ecommerce, verify the checkout and payment response end to end; the current public checkout omits the payment intent returned by the service.
6. **Pilot and measure.** Test signup through publishing and billing, expiry and recovery, tenant isolation, backups, monitoring and failure retries. Load-test before setting a customer capacity or launching broadly.

## Server considerations

The inspected server already hosts separate client stacks behind nginx and has resources for a likely small pilot, but runs other production workloads. Automatic domain routing and certificate issuance, resource limits, backup/restore and operational separation need design and validation. The existing certificate covers named hosts, not a wildcard. Investigate the PostgreSQL service bound to all interfaces before adding workloads; external reachability was not checked.

## Decisions before implementation

- First target customer and first package; whether a trial requires a payment card.
- Hosting isolation and scaling model, custom-domain policy, billing provider and pricing.
- AI generation limits, available design freedom, ownership and retention after cancellation.
