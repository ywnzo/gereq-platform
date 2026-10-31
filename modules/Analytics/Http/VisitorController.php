<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\Repositories\ReportRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * `admin.analytics.visitors` — pseudonymous visitor rows.
 *
 * Requires `analytics.raw`: the rows carry the HMAC `visitor_hash`, never an IP address
 * or a user agent (README "Pseudonymity rules").
 */
final class VisitorController extends AdminPageController
{
    /** Hard cap for one page of the raw visitor list (no paging UI in phase 1). */
    private const LIMIT = 200;

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        Authenticator $auth,
        Menu $menu,
        CommandPalette $palette,
        TranslatorInterface $translator,
        private readonly SiteRepository $sites,
        private readonly ReportRepository $reports,
    ) {
        parent::__construct($twig, $router, $requestStack, $auth, $menu, $palette, $translator);
    }

    public function index(Request $request): Response
    {
        $range = $this->reportRange($request, 30);
        $site = $this->selectedSite($request, $this->allSites($this->sites));
        $rows = [];

        if ($site !== null) {
            $rows = $this->safe(
                fn (): array => $this->reports->visitors((string) $site['id'], $range->from, $range->to, self::LIMIT),
                [],
            );
        }

        return $this->adminPage('@analytics/visitors.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.visitors'),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'range' => $range->toArray(),
            'rows' => array_map(fn (array $row): array => [
                'visitor_hash' => mb_substr((string) ($row['visitor_hash'] ?? ''), 0, 16),
                'first_seen_at' => (string) ($row['first_seen_at'] ?? ''),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                'sessions' => (int) ($row['session_count'] ?? 0),
                'pageviews' => (int) ($row['pageview_count'] ?? 0),
                'events' => (int) ($row['event_count'] ?? 0),
                'device_type' => (string) ($row['device_type'] ?? ''),
                'browser' => (string) ($row['browser'] ?? ''),
                'os' => (string) ($row['os'] ?? ''),
                'language' => (string) ($row['language'] ?? ''),
                'country_code' => (string) ($row['country_code'] ?? ''),
                'landing_path' => (string) ($row['landing_path'] ?? ''),
                'last_path' => (string) ($row['last_path'] ?? ''),
                'first_referrer_host' => (string) ($row['first_referrer_host'] ?? ''),
            ], $rows),
        ]);
    }
}
