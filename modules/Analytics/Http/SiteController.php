<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Router;
use Gereq\Validation\ValidationException;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\TrackerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * `admin.analytics.sites.*` — tracked sites, their HMAC secret and the tracker snippet.
 *
 * Site create / update / delete / rotate write an {@see Auditor} entry and emit the
 * `analytics.site.created` / `analytics.site.updated` platform events with plain, secret-free
 * payloads. Mutating forms keep CSRF (`csrf_field()` in the template).
 */
final class SiteController extends AdminPageController
{
    private const FORM = 'analytics.site.save';

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        Authenticator $auth,
        Menu $menu,
        CommandPalette $palette,
        TranslatorInterface $translator,
        private readonly SiteRepository $sites,
        private readonly TrackerConfig $tracker,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
    ) {
        parent::__construct($twig, $router, $requestStack, $auth, $menu, $palette, $translator);
    }

    public function index(): Response
    {
        $sites = $this->allSites($this->sites);

        return $this->adminPage('@analytics/sites/index.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.sites'),
            'sites' => array_map(fn (array $site): array => [
                'id' => (string) $site['id'],
                'name' => (string) $site['name'],
                'key' => (string) $site['key'],
                'domains' => $site['domains'],
                'enabled' => ($site['enabled'] ?? false) === true,
                'timezone' => (string) ($site['timezone'] ?? ''),
                'trackerSnippet' => $this->safe(fn (): string => $this->tracker->snippet($site), ''),
            ], $sites),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, []);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $this->siteInput($request);
        } catch (ValidationException $exception) {
            $this->formState()->fail($exception);

            // Re-render the create form: `site` must stay null so the template keeps pointing at
            // `admin.analytics.sites.store` and the error summary comes from the `errors` map.
            return $this->form(null, $exception->errors(), 422);
        }

        $id = $this->sites->create($data);
        $site = $this->sites->find($id);

        $this->auditor->log('analytics.site.created', 'analytics_site', $id, null, [
            'name' => $data['name'],
            'domains' => $data['domains'],
        ]);
        if ($site !== null) {
            $this->events->emit(
                'analytics.site.created',
                ['type' => 'analytics_site', 'id' => $id],
                ['site' => $this->eventSite($site)],
            );
        }

        return $this->redirect('admin.analytics.sites.edit', ['id' => $id]);
    }

    public function edit(string $id): Response
    {
        $site = $this->sites->find($id);
        if ($site === null) {
            $this->abort(404);
        }

        return $this->form($site, []);
    }

    public function update(Request $request, string $id): Response
    {
        $site = $this->sites->find($id);
        if ($site === null) {
            $this->abort(404);
        }

        try {
            $data = $this->siteInput($request);
        } catch (ValidationException $exception) {
            $this->formState()->fail($exception);

            return $this->form(array_merge($site, $this->postedSite($request)), $exception->errors(), 422);
        }

        $this->sites->update($id, $data);
        $updated = $this->sites->find($id);

        $this->auditor->log('analytics.site.updated', 'analytics_site', $id, [
            'name' => (string) $site['name'],
            'domains' => $site['domains'],
            'enabled' => (bool) ($site['enabled'] ?? false),
        ], [
            'name' => $data['name'],
            'domains' => $data['domains'],
            'enabled' => $data['enabled'],
        ]);
        if ($updated !== null) {
            $this->events->emit(
                'analytics.site.updated',
                ['type' => 'analytics_site', 'id' => $id],
                ['site' => $this->eventSite($updated)],
            );
        }

        return $this->redirect('admin.analytics.sites.edit', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $site = $this->sites->find($id);
        if ($site === null) {
            $this->abort(404);
        }

        $this->sites->delete($id);
        $this->auditor->log('analytics.site.deleted', 'analytics_site', $id, [
            'name' => (string) $site['name'],
            'key' => (string) $site['key'],
        ]);

        return $this->redirect('admin.analytics.sites.index');
    }

    public function rotateSecret(string $id): Response
    {
        $site = $this->sites->find($id);
        if ($site === null) {
            $this->abort(404);
        }

        $this->sites->rotateSecret($id);
        $updated = $this->sites->find($id);

        $this->auditor->log('analytics.site.key_rotated', 'analytics_site', $id, null, [
            'key' => (string) $site['key'],
        ]);
        if ($updated !== null) {
            $this->events->emit(
                'analytics.site.updated',
                ['type' => 'analytics_site', 'id' => $id],
                ['site' => $this->eventSite($updated), 'changed' => ['secret_rotated' => true]],
            );
        }

        return $this->redirect('admin.analytics.sites.edit', ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $site
     * @param array<string, string> $errors
     */
    private function form(?array $site, array $errors, int $status = 200): Response
    {
        // Only the sanitised row reaches Twig: the HMAC `secret` must never leave the service layer
        // (same helper as the overview / report controllers). Everything the form edits — name,
        // domains, excluded paths/ips, timezone, switches and the retention override — is kept.
        return $this->adminPage('@analytics/sites/form.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.sites'),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'form' => self::FORM,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Validated site input. `domains`, `excluded_paths` and `excluded_ips` accept either a list
     * of posted fields or one textarea value with one entry per line.
     *
     * @return array{name: string, domains: list<string>, enabled: bool, timezone: string,
     *   keep_bot_hits: bool, respect_dnt: bool, excluded_paths: list<string>,
     *   excluded_ips: list<string>, retention_override_days: int|null}
     */
    private function siteInput(Request $request): array
    {
        $data = $this->validate($request, self::FORM);

        return [
            'name' => trim((string) $data['name']),
            'domains' => self::lines($request->request->all()['domains'] ?? null),
            'enabled' => self::checkbox($request->request->get('enabled')),
            'timezone' => trim((string) ($data['timezone'] ?? '')) !== ''
                ? trim((string) $data['timezone'])
                : 'Europe/Prague',
            'keep_bot_hits' => self::checkbox($request->request->get('keep_bot_hits')),
            'respect_dnt' => self::checkbox($request->request->get('respect_dnt')),
            'excluded_paths' => self::lines($request->request->all()['excluded_paths'] ?? null),
            'excluded_ips' => self::lines($request->request->all()['excluded_ips'] ?? null),
            'retention_override_days' => is_numeric($data['retention_override_days'] ?? null)
                ? max(1, (int) $data['retention_override_days'])
                : null,
        ];
    }

    /**
     * Values re-rendered after a validation error, merged over the stored site so the form keeps
     * its id / key / secret. Only the editable columns are returned.
     *
     * @return array<string, mixed>
     */
    private function postedSite(Request $request): array
    {
        $posted = $request->request->all();

        return [
            'name' => trim((string) ($posted['name'] ?? '')),
            'domains' => self::lines($posted['domains'] ?? null),
            'enabled' => self::checkbox($posted['enabled'] ?? null),
            'timezone' => trim((string) ($posted['timezone'] ?? 'Europe/Prague')),
            'keep_bot_hits' => self::checkbox($posted['keep_bot_hits'] ?? null),
            'respect_dnt' => self::checkbox($posted['respect_dnt'] ?? null),
            'excluded_paths' => self::lines($posted['excluded_paths'] ?? null),
            'excluded_ips' => self::lines($posted['excluded_ips'] ?? null),
            'retention_override_days' => is_numeric($posted['retention_override_days'] ?? null)
                ? (int) $posted['retention_override_days']
                : null,
        ];
    }

    /**
     * Secret-free event payload (README: payloads are versioned plain arrays, never entities).
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function eventSite(array $site): array
    {
        return [
            'id' => (string) $site['id'],
            'name' => (string) $site['name'],
            'key' => (string) $site['key'],
            'domains' => $site['domains'],
            'enabled' => (bool) ($site['enabled'] ?? false),
            'timezone' => (string) ($site['timezone'] ?? ''),
        ];
    }

    private static function checkbox(mixed $value): bool
    {
        return in_array($value, ['1', 1, true, 'on', 'true', 'yes'], true);
    }

    /** @return list<string> */
    private static function lines(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $value) {
                if (is_scalar($value)) {
                    $parts[] = (string) $value;
                }
            }
        } else {
            $parts = preg_split('/[\r\n,]+/', (string) $raw) ?: [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        )));
    }
}
