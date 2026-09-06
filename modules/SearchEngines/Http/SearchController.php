<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\SearchEngines\Application\SearchEnginesProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SearchController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly CapabilityRegistry $capabilities,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $provider = $this->provider();
        $q = trim((string) $request->query->get('q', ''));
        $source = trim((string) $request->query->get('source', ''));
        $engines = $provider->engines();
        $pending = [];

        if ($q !== '') {
            if ($source === '' || $source === 'all') {
                $pending = $provider->availableSourceIds();
            } elseif ($provider->isAvailable($source)) {
                $pending = [$source];
            }
        }

        return $this->page('@searchengines/index.html.twig', [
            'pageTitle' => $this->translator->trans('searchengines.title'),
            'engines' => $engines,
            'q' => $q,
            'source' => $source !== '' ? $source : 'all',
            'pending' => $pending,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $provider = $this->provider();
        $q = trim((string) $request->query->get('q', ''));
        $source = trim((string) $request->query->get('source', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(80, (int) $request->query->get('limit', 20)));

        if ($q === '' || $source === '') {
            return $this->json([
                'ok' => false,
                'items' => [],
                'error' => 'searchengines.error.query_required',
                'has_more' => false,
            ]);
        }

        $res = $provider->search($source, $q, $limit, $page);

        return $this->json([
            'ok' => $res['ok'],
            'source' => $source,
            'items' => $this->decorateItems($res['items']),
            'error' => $res['error'],
            'has_more' => $res['has_more'],
            'page' => $page,
            'display_currency' => $this->money->displayCode(),
        ]);
    }

    public function offers(Request $request): JsonResponse
    {
        $provider = $this->provider();
        $source = trim((string) $request->query->get('source', ''));
        $url = trim((string) $request->query->get('url', ''));
        if ($source === '' || $url === '') {
            return $this->json([
                'ok' => false,
                'offers' => [],
                'error' => 'searchengines.error.query_required',
            ]);
        }
        $res = $provider->offers($source, $url);

        return $this->json([
            'ok' => $res['ok'],
            'source' => $source,
            'offers' => $this->decorateOffers($res['offers']),
            'error' => $res['error'],
            'display_currency' => $this->money->displayCode(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function decorateItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $amount = $item['min_price'] ?? null;
            $from = isset($item['currency']) ? (string) $item['currency'] : null;
            $parsed = is_int($amount) || is_float($amount) || is_string($amount)
                ? $this->money->parse($amount)
                : null;
            $net = $parsed !== null ? $this->money->withoutVat($parsed) : null;
            $money = $this->money->make($net, $from);
            if ($net !== null) {
                $item['min_price'] = $net;
                $item['unit_price'] = $net;
            }
            $item['money'] = $money;
            $item['price_label'] = $money->localized;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    private function decorateOffers(array $offers): array
    {
        $out = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $amount = $offer['price'] ?? null;
            $from = isset($offer['currency']) ? (string) $offer['currency'] : null;
            $parsed = is_int($amount) || is_float($amount) || is_string($amount)
                ? $this->money->parse($amount)
                : null;
            $net = $parsed !== null ? $this->money->withoutVat($parsed) : null;
            $money = $this->money->make($net, $from);
            if ($net !== null) {
                $offer['price'] = $net;
                $offer['unit_price'] = $net;
            }
            $offer['money'] = $money;
            $offer['price_label'] = $money->localized;
            $out[] = $offer;
        }

        return $out;
    }

    private function provider(): SearchEnginesProvider
    {
        $cap = $this->capabilities->get('searchengines');
        if (!$cap instanceof SearchEnginesProvider) {
            throw new \RuntimeException('searchengines capability missing');
        }

        return $cap;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
