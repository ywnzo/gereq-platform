<?php

declare(strict_types=1);

namespace Modules\BikeService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\BikeService\Application\Catalog\BikeDataCatalogClient;
use Modules\BikeService\Application\Catalog\ComponentTranslations;
use Modules\BikeService\Application\Catalog\PartsSearchService;
use Modules\BikeService\Application\Catalog\PartsTaxonomy;
use Modules\BikeService\Domain\Bike;
use Modules\Customers\Domain\Customer;
use Gereq\Setting\Settings;
use Gereq\Module\CapabilityRegistry;
use Modules\SearchEngines\Application\SearchEnginesProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CatalogController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly BikeDataCatalogClient $catalog,
        private readonly PartsSearchService $partsSearch,
        private readonly Settings $settings,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->query->get('tab', 'parts');
        if ($tab === 'brands') {
            return $this->redirect('admin.bikeservice.manufacturers', array_filter([
                'q' => trim((string) $request->query->get('q', '')) ?: null,
                'frames' => $request->query->getBoolean('frames') ? '1' : null,
                'ebike' => $request->query->getBoolean('ebike') ? '1' : null,
            ]));
        }
        if (!in_array($tab, ['parts', 'specs'], true)) {
            $tab = 'parts';
        }

        $q = trim((string) $request->query->get('q', ''));
        $group = trim((string) $request->query->get('group', ''));
        $taxonomyId = trim((string) $request->query->get('taxonomy', ''));
        $fresh = $request->query->getBoolean('fresh');
        $selectedBrands = $this->parseSelectedBrands($request);
        $selectedPrice = trim((string) $request->query->get('price', ''));

        $partsResult = null;
        $specs = null;

        if ($tab === 'parts') {
            $partsResult = $this->partsSearch->search($q, $group, $taxonomyId, 60, $fresh);
            if ($q === '' && isset($partsResult['query']) && is_string($partsResult['query']) && $partsResult['query'] !== '') {
                $q = $partsResult['query'];
            }
        } else {
            $specs = $this->localizedSpecs();
        }

        $componentGroups = [];
        foreach ($this->catalog->componentGroups() as $g) {
            $componentGroups[] = ['value' => $g, 'label' => ComponentTranslations::group($g)];
        }

        $activeTaxonomy = $taxonomyId !== '' ? PartsTaxonomy::find($taxonomyId) : null;

        return $this->page('@bikeservice/catalog/index.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.catalog.title'),
            'tab' => $tab,
            'q' => $q,
            'group' => $group,
            'taxonomyId' => $taxonomyId,
            'partsResult' => $partsResult,
            'specs' => $specs,
            'taxonomy' => PartsTaxonomy::groups(),
            'activeTaxonomy' => $activeTaxonomy,
            'filterBrands' => is_array($activeTaxonomy) ? $activeTaxonomy['brands'] : PartsTaxonomy::allBrands(),
            'selectedBrands' => $selectedBrands,
            'selectedPrice' => $selectedPrice,
            'componentGroups' => $componentGroups,
            'heurekaConfigured' => $this->heurekaConfigured(),
        ]);
    }

    private function heurekaConfigured(): bool
    {
        if (!$this->capabilities->has('searchengines')) {
            return false;
        }
        $cap = $this->capabilities->get('searchengines');

        return $cap instanceof SearchEnginesProvider && $cap->isAvailable('heureka');
    }

    public function manufacturers(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $framesOnly = $request->query->getBoolean('frames');
        $ebikeOnly = $request->query->getBoolean('ebike');

        $brands = $this->catalog->manufacturers(
            $q,
            $framesOnly ? true : null,
            $ebikeOnly ? true : null,
            $q !== '' ? 250 : 2000,
        );
        $localCounts = $this->localBikeCountsByBrand();
        if ($brands['ok']) {
            $items = [];
            foreach ($brands['items'] as $item) {
                $key = mb_strtolower((string) $item['name']);
                $item['local_count'] = $localCounts[$key] ?? 0;
                $items[] = $item;
            }
            $brands['items'] = $items;
        }

        return $this->page('@bikeservice/manufacturers/index.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.manufacturers.title'),
            'q' => $q,
            'framesOnly' => $framesOnly,
            'ebikeOnly' => $ebikeOnly,
            'brands' => $brands,
            'totalLocal' => array_sum($localCounts),
        ]);
    }

    public function show(string $id): Response
    {
        $name = rawurldecode($id);
        $result = $this->catalog->manufacturers($name, null, null, 50);
        $manufacturer = null;
        if ($result['ok']) {
            foreach ($result['items'] as $item) {
                if (strcasecmp((string) $item['name'], $name) === 0) {
                    $manufacturer = $item;
                    break;
                }
            }
            if ($manufacturer === null && $result['items'] !== []) {
                $manufacturer = $result['items'][0];
            }
        }
        if ($manufacturer === null) {
            $this->abort(404, $this->translator->trans('bikeservice.error.manufacturer_not_found'));
        }

        $want = mb_strtolower((string) $manufacturer['name']);
        $bikes = [];
        foreach ($this->em->getRepository(Bike::class)->createQueryBuilder('b')
            ->orderBy('b.updatedAt', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult() as $bike) {
            if (!$bike instanceof Bike) {
                continue;
            }
            if (mb_strtolower($bike->brand()) !== $want) {
                continue;
            }
            $bikes[] = $bike;
            if (count($bikes) >= 50) {
                break;
            }
        }

        return $this->page('@bikeservice/catalog/show.html.twig', [
            'pageTitle' => (string) $manufacturer['name'],
            'manufacturer' => $manufacturer,
            'bikes' => $bikes,
            'customers' => $this->customerMap($bikes),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $result = $this->catalog->manufacturers($q, null, null, 20);

        return $this->json([
            'ok' => $result['ok'],
            'items' => $result['ok'] ? $result['items'] : [],
            'error' => $result['error'],
        ]);
    }

    public function marketSource(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $source = trim((string) $request->query->get('source', ''));
        if ($q === '') {
            return $this->json([
                'ok' => false,
                'source' => $source,
                'items' => [],
                'error' => 'bikeservice.error.parts_query_required',
                'sources' => [],
                'pending_sources' => [],
                'complete' => false,
                'cached_at' => null,
            ], 422);
        }

        $result = $this->partsSearch->searchMarketSource(
            $source,
            $q,
            $request->query->getBoolean('fresh'),
            trim((string) $request->query->get('taxonomy', '')),
            max(1, (int) $request->query->get('page', 1)),
        );

        $response = $this->json($result);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /** @return array<string, array{ok: bool, items: list<array{name: string, name_en?: string}>, error: ?string}> */
    private function localizedSpecs(): array
    {
        $brakes = $this->catalog->brakeTypes();
        $wheels = $this->catalog->wheelSizes();
        $bars = $this->catalog->handlebarTypes();

        $mapBrakes = [];
        if ($brakes['ok']) {
            foreach ($brakes['items'] as $item) {
                $en = (string) ($item['name'] ?? '');
                $mapBrakes[] = ['name' => ComponentTranslations::brake($en), 'name_en' => $en];
            }
        }
        $mapBars = [];
        if ($bars['ok']) {
            foreach ($bars['items'] as $item) {
                $en = (string) ($item['name'] ?? '');
                $mapBars[] = ['name' => ComponentTranslations::handlebar($en), 'name_en' => $en];
            }
        }
        $mapWheels = [];
        if ($wheels['ok']) {
            foreach ($wheels['items'] as $item) {
                $mapWheels[] = ['name' => (string) ($item['name'] ?? '')];
            }
        }

        return [
            'brakes' => ['ok' => $brakes['ok'], 'items' => $mapBrakes, 'error' => $brakes['error'] ?? null],
            'wheels' => ['ok' => $wheels['ok'], 'items' => $mapWheels, 'error' => $wheels['error'] ?? null],
            'handlebars' => ['ok' => $bars['ok'], 'items' => $mapBars, 'error' => $bars['error'] ?? null],
        ];
    }

    /** @return array<string, int> */
    private function localBikeCountsByBrand(): array
    {
        $map = [];
        foreach ($this->em->getRepository(Bike::class)->createQueryBuilder('b')->select('b.brand')->getQuery()->getArrayResult() as $row) {
            $key = mb_strtolower(trim((string) ($row['brand'] ?? '')));
            if ($key === '') {
                continue;
            }
            $map[$key] = ($map[$key] ?? 0) + 1;
        }

        return $map;
    }

    /** @param list<Bike> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Bike $b): ?string => $b->customerId(), $items))));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->where('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $map[$c->id()] = $c;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function parseSelectedBrands(Request $request): array
    {
        $bag = $request->query->all();
        $raw = $bag['brands'] ?? null;
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $single = trim((string) ($raw ?? ''));
            $parts = $single !== '' ? (preg_split('/\s*,\s*/u', $single) ?: []) : [];
        }
        $out = [];
        $seen = [];
        foreach ($parts as $brand) {
            if (!is_string($brand) && !is_numeric($brand)) {
                continue;
            }
            $brand = trim((string) $brand);
            if ($brand === '') {
                continue;
            }
            $key = mb_strtolower($brand);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $brand;
        }

        return $out;
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
