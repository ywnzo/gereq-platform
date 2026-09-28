# Platform ZIP update guidelines

Rules for updating a deployed GEREQ installation from a platform release ZIP (e.g. `gereq-platform-YYYYMMDD-HHMM.zip`). Keep this in sync with production lessons learned.

## Source of truth

| Area | Source of truth |
| --- | --- |
| Backend core + modules | Platform ZIP / git |
| Storefront menu (header/footer) | **CMS menus in BE** (not FE hard-coded fallbacks) |
| Client `.env`, `storage/`, uploads | Never overwrite from ZIP |
| Client-only code (custom modules, local patches) | Preserve during rsync |

## Exclude checklist (do not overwrite)

When rsyncing the ZIP into an existing `be/`:

- `.env` / `.env.*`
- `storage/` (cache, logs, uploads, sessions, queue files)
- Client-local modules or forks not in the ZIP (document them per project)
- Production-only Docker / nginx files the ZIP may omit, e.g.:
  - `docker/php/php.prod.ini`
  - `docker/nginx/prod.conf`
- Custom FE mount points if FE lives outside the ZIP layout

Suggested rsync shape:

```bash
rsync -a --delete \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'vendor/' \
  --exclude 'node_modules/' \
  ./extracted-zip/ ./be/
```

Adjust excludes for any project-specific paths.

## Required steps after update

1. `composer install --no-dev` (production) or `composer install` (demo)
2. `php gereq migrate` (and module updates in admin if versions bumped)
3. `php gereq cache:clear`
4. Restart PHP-FPM / queue worker (`php gereq queue:work` under supervisor)
5. Smoke-test: admin dashboard, login, CMS page save, queue job if Redis is used

## Known platform fixes (must ship in ZIP)

These used to be **manual production patches** after ZIP deploy. They belong in the platform source so the next ZIP includes them:

| File | Why |
| --- | --- |
| `src/Queue/Queue.php` | Redis `AUTH` via `REDIS_PASSWORD` / `cache.redis.password` — without it the queue fails with `NOAUTH` |
| `src/Database/EntityManagerFactory.php` | Scan only `modules/*/Domain` so `modules/*/Tests` are not loaded under `--no-dev` (no PHPUnit) |
| `modules/Viewings/.../ViewingsProvider::dashboardViewings()` | Must not 500 the admin dashboard when the schema lags or rows have null `scheduled_at` |
| CRM workflow PHP + `crm.workflows.*` translations | Required if `CrmModule` registers automation; missing files → 500 on CMS save |

## CRM / workflow note

If a ZIP is missing `AutomationEngine`, `WorkflowRunner`, workflow entities/controllers/views/migrations, restore them from a known-good release **or** disable the CRM automation registration. Do not leave half the feature referenced from `CrmModule`.

## Storefront menu

- Edit menus in **Admin → CMS → Menus** (unified settings + items editor).
- FE fallbacks (if any) are last-resort only; after CMS is seeded, BE CMS is authoritative.
- Demo seeders should create header/footer menus that match the intended storefront IA.

## Do not

- Blind `--delete` rsync without the exclude list
- Commit client secrets or `storage/` into the platform ZIP
- Rely on FE-only menu hard-coding as the long-term source of truth
- Ship module UI/schema changes without bumping `module.php` + `Module::version()`
