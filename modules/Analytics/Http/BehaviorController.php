<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Security\ContentSecurityPolicy;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\ReplayDecoder;
use Modules\Analytics\Application\Repositories\BehaviorRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BehaviorController extends AdminPageController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        Authenticator $auth,
        Menu $menu,
        CommandPalette $palette,
        TranslatorInterface $translator,
        private readonly SiteRepository $sites,
        private readonly BehaviorRepository $behavior,
        private readonly ReplayDecoder $decoder,
        private readonly ContentSecurityPolicy $csp,
    ) {
        parent::__construct($twig, $router, $requestStack, $auth, $menu, $palette, $translator);
    }

    public function forms(Request $request): Response
    {
        $range = $this->reportRange($request);
        $sites = $this->allSites($this->sites);
        $site = $this->selectedSite($request, $sites);
        $rows = $site !== null ? $this->behavior->forms((string) $site['id'], $range->from, $range->to) : [];
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row['name']] = $row;
        }

        return $this->adminPage('@analytics/forms.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.forms'),
            'range' => $range->toArray(),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'sites' => array_map(AnalyticsProvider::publicSite(...), $sites),
            'summary' => $summary,
        ]);
    }

    public function recordings(Request $request): Response
    {
        $range = $this->reportRange($request);
        $sites = $this->allSites($this->sites);
        $site = $this->selectedSite($request, $sites);

        return $this->adminPage('@analytics/recordings.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.recordings'),
            'range' => $range->toArray(),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'sites' => array_map(AnalyticsProvider::publicSite(...), $sites),
            'rows' => $site !== null ? $this->behavior->sessions((string) $site['id'], $range->from, $range->to) : [],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $site = $this->selectedSite($request, $this->allSites($this->sites));
        $session = $site !== null ? $this->behavior->session((string) $site['id'], $id) : null;
        if ($session === null) {
            return new Response('', 404);
        }

        $response = $this->adminPage('@analytics/recording.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.recording.title'),
            'site' => AnalyticsProvider::publicSite($site),
            'range' => $this->reportRange($request)->toArray(),
            'session' => $session,
            'timeline' => $this->behavior->timeline((string) $site['id'], $id),
        ]);
        $origins = array_filter(
            array_map('trim', explode(',', (string) env_value('FRONTEND_ORIGIN', ''))),
            static fn (string $origin): bool => preg_match('~\Ahttps?://[a-zA-Z0-9.-]+(?::[0-9]+)?\z~D', $origin) === 1
        );
        if ($origins !== []) {
            // Replay needs storefront images/fonts; scripts retain the normal admin policy.
            $policy = $this->csp->header((string) $request->attributes->get('csp_nonce', ''));
            $policy = preg_replace('/((?:img-src|font-src) [^;]+)/', '$1 ' . implode(' ', $origins), $policy);
            $response->headers->set(ContentSecurityPolicy::HEADER_OVERRIDE, $policy);
        }

        return $response;
    }

    public function data(Request $request, string $id): Response
    {
        $site = $this->selectedSite($request, $this->allSites($this->sites));
        if ($site === null || $this->behavior->session((string) $site['id'], $id) === null) {
            return new JsonResponse(['recordings' => []], 404);
        }

        return new JsonResponse([
            'recordings' => $this->decoder->decode($this->behavior->replayChunks((string) $site['id'], $id)),
        ], 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
