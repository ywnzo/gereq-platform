<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Module\ModuleManager;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\PartsCatalog\Application\CatalogApiCacheStore;
use Modules\PartsCatalog\Application\CatalogCallLogger;
use Modules\PartsCatalog\Application\CategoryTaxonomy;
use Modules\PartsCatalog\Application\Dto\CatalogArticle;
use Modules\PartsCatalog\Application\Dto\CatalogNode;
use Modules\PartsCatalog\Application\PartsCatalogFacade;
use Modules\PartsCatalog\Domain\CatalogPart;
use Modules\PartsCatalog\Domain\CatalogVehicle;
use Modules\PartsCatalog\Domain\CatalogVehiclePart;
use Modules\PartsCatalog\Domain\OrderPart;
use Modules\SearchEngines\Application\SearchEnginesProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CatalogController extends Controller
{
    /** @var list<string> */
    private const SETTING_KEYS = [
        'partscatalog.jama.base_url',
    ];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly PartsCatalogFacade $catalog,
        private readonly EntityManagerInterface $em,
        private readonly CatalogApiCacheStore $apiCache,
        private readonly CapabilityRegistry $capabilities,
        private readonly ModuleManager $modules,
        private readonly \Modules\Fleet\Application\VehicleCatalogClient $vehicleCatalog,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function browse(Request $request): Response
    {
        CatalogCallLogger::log('browse.in', [
            'query' => $request->query->all(),
            'path' => $request->getPathInfo(),
        ]);

        $vehicleId = trim((string) $request->query->get('vehicle', ''));
        $nodeId = trim((string) $request->query->get('node', ''));
        $parentId = trim((string) $request->query->get('parent', ''));
        $fleetId = trim((string) $request->query->get('fleet', ''));
        $orderId = trim((string) $request->query->get('order_id', ''));
        $brandId = trim((string) $request->query->get('brand', ''));
        $carId = trim((string) $request->query->get('car', ''));

        $q = trim((string) $request->query->get('q', ''));
        $oem = trim((string) $request->query->get('oem', ''));
        $modelYear = trim((string) $request->query->get('year', ''));

        $fleetMode = $fleetId !== '';
        if ($fleetMode) {
            // Flotilové vozidlo má vlastní mapování — picker značka/model/rok nepoužíváme.
            $brandId = '';
            $carId = '';
            $modelYear = '';
        }

        $catalogBrands = $this->loadFleetCatalogBrands();
        $allCatalogCars = $this->loadFleetCatalogCars($catalogBrands);

        $selectedBrand = $this->findCatalogBrand($catalogBrands, $brandId);
        if ($selectedBrand === null) {
            $brandId = '';
        }

        // Historie / starý odkaz jen s car → doplň brand.
        $selectedCar = $this->findCatalogCar($allCatalogCars, $carId);
        if ($selectedCar !== null && $brandId === '') {
            $brandId = $selectedCar['brand_id'];
            $selectedBrand = $this->findCatalogBrand($catalogBrands, $brandId);
        }

        // Model musí patřit pod zvolenou značku.
        if ($selectedCar !== null && $brandId !== '' && $selectedCar['brand_id'] !== $brandId) {
            $carId = '';
            $selectedCar = null;
            $modelYear = '';
        }
        if ($selectedCar === null) {
            $carId = '';
            $modelYear = '';
        }

        $catalogCars = $brandId !== ''
            ? array_values(array_filter(
                $allCatalogCars,
                static fn (array $c): bool => $c['brand_id'] === $brandId,
            ))
            : [];

        $yearOptions = $this->yearOptions();
        if ($modelYear !== '' && !ctype_digit($modelYear)) {
            $modelYear = '';
        }
        if ($modelYear !== '' && !in_array((int) $modelYear, $yearOptions, true)) {
            $yearOptions[] = (int) $modelYear;
            rsort($yearOptions);
        }
        if ($selectedCar === null) {
            $modelYear = '';
        }

        $canSearch = $fleetMode || $selectedCar !== null;

        // Neúplná kaskáda → zahodit katalogové vozidlo / strom z URL.
        if (!$fleetMode && !$canSearch) {
            $vehicleId = '';
            $nodeId = '';
            $parentId = '';
        }

        // OEM / číslo dílu.
        $articleQuery = trim($oem !== '' ? $oem : $q);
        $looksLikeArticle = $articleQuery !== '' && (
            $oem !== ''
            || preg_match('/[0-9]/', $articleQuery) === 1
            || preg_match('/^[A-Za-z0-9.\-\/]{3,}$/', $articleQuery) === 1
        );

        $selectedFleet = $fleetMode ? $this->findFleetVehicle($fleetId) : null;
        $error = null;
        $explicitVehicleId = $vehicleId;

        // Flotila s fotkami — mimo fleet režim (a ne uvnitř stromu kategorií).
        $fleetVehicles = (!$fleetMode && $nodeId === '' && $parentId === '')
            ? $this->loadFleetVehicles([])
            : [];

        // Smazání karty historie (před uvolněním session).
        $forgetId = trim((string) $request->query->get('history_forget', ''));
        if ($forgetId !== '') {
            $this->forgetSearchHistory($request, $forgetId);
            $params = $request->query->all();
            unset($params['history_forget']);
            $target = $this->router->url('admin.partscatalog.browse');
            if ($params !== []) {
                $target .= '?' . http_build_query($params);
            }

            return $this->redirectTo($target);
        }

        // OEM-only historie (bez auta) — před lockem.
        if ($looksLikeArticle && $articleQuery !== '' && $carId === '' && $nodeId === '' && $parentId === '') {
            $this->pushSearchHistory($request, [
                'brand' => '',
                'car' => '',
                'year' => '',
                'q' => $articleQuery,
                'label' => $articleQuery,
                'brand_name' => '',
                'model' => '',
                'vehicle' => '',
                'vehicle_label' => '',
                'type' => '',
            ]);
        }

        $this->releaseSessionLock($request);

        // Fleet: katalogové vozidlo vybereme až po filtrování podle paliva/motorizace.

        $catalogVehicles = [];
        $nodes = [];
        $products = [];
        $searchResults = [];
        $cachedHits = [];
        $vehicleScopedSearch = false;
        $groupQuery = '';

        try {
            $vehicleQuery = null;
            if ($fleetMode && is_array($selectedFleet)) {
                $vehicleQuery = trim(
                    (string) ($selectedFleet['brand'] ?? '')
                    . ' ' . (string) ($selectedFleet['model'] ?? '')
                    . ' ' . (string) ($selectedFleet['year'] ?? ''),
                );
                if ($vehicleQuery === '') {
                    $vehicleQuery = null;
                }
            } elseif ($selectedCar !== null) {
                $vehicleQuery = trim($selectedCar['label'] . ($modelYear !== '' ? ' ' . $modelYear : ''));
            }

            $needCatalogVehicles = $fleetMode
                || $explicitVehicleId !== ''
                || ($vehicleQuery !== null && $vehicleQuery !== '');

            if ($needCatalogVehicles) {
                $catalogVehicles = $this->catalog->vehicles($vehicleQuery);
                if (is_array($selectedFleet)) {
                    $catalogVehicles = $this->filterVehiclesForFleet($catalogVehicles, $selectedFleet);
                } elseif ($selectedCar !== null) {
                    $catalogVehicles = $this->filterVehiclesForFleet($catalogVehicles, [
                        'brand' => $selectedCar['brand'],
                        'model' => $selectedCar['model'],
                        'year' => $modelYear !== '' ? (int) $modelYear : null,
                        'fuel' => null,
                        'motorization' => null,
                    ]);
                }
            }

            $providerId = $this->catalog->activeProviderId();
            if ($vehicleId !== '' && !$this->vehicleIdMatchesProvider($providerId, $vehicleId)) {
                CatalogCallLogger::log('browse.stale_vehicle', [
                    'provider' => $providerId,
                    'staleVehicleId' => $vehicleId,
                    'staleNodeId' => $nodeId,
                ]);
                $vehicleId = '';
                $nodeId = '';
                $parentId = '';
            }
            if ($nodeId !== '' && !$this->nodeIdMatchesProvider($providerId, $nodeId)) {
                CatalogCallLogger::log('browse.stale_node', [
                    'provider' => $providerId,
                    'staleNodeId' => $nodeId,
                ]);
                $nodeId = '';
                $parentId = '';
            }
            if ($parentId !== '' && !$this->nodeIdMatchesProvider($providerId, $parentId)) {
                $parentId = '';
            }

            // Flotila: bez explicitního výběru (nebo mimo filtrovaný seznam) → nejlepší match.
            if ($fleetMode && $catalogVehicles !== []) {
                $ids = array_map(static fn ($v): string => $v->id, $catalogVehicles);
                if ($explicitVehicleId === '' || !in_array($vehicleId, $ids, true)) {
                    $vehicleId = $catalogVehicles[0]->id;
                }
            } elseif ($vehicleId === '' && $catalogVehicles !== [] && ($vehicleQuery !== null && $vehicleQuery !== '')) {
                $vehicleId = $catalogVehicles[0]->id;
            }

            // Fulltext: s vybraným vozidlem jen díly pro toto vozidlo; bez vozidla globální OEM.
            if ($articleQuery !== '' && $nodeId === '' && mb_strlen($articleQuery) >= 2 && ($canSearch || $looksLikeArticle)) {
                if ($vehicleId !== '' && $canSearch) {
                    $vehicleScopedSearch = true;
                    $searchResults = $this->searchPartsForVehicle($vehicleId, $articleQuery, 60);
                } else {
                    $searchResults = $this->catalog->searchByArticle($articleQuery);
                    if ($searchResults === []) {
                        $cachedHits = $this->apiCache->searchProducts($articleQuery, 40);
                    }
                }
            }

            if ($vehicleId !== '' && $canSearch && ($searchResults === [] || $vehicleScopedSearch)) {
                // Skupiny vždy při vybraném vozidle (i při fulltextu) — filtr v UI.
                if ($nodeId === '') {
                    $nodes = $this->catalog->tree($vehicleId, $parentId !== '' ? $parentId : null);
                    $nodes = $this->preloadGroupChildren($vehicleId, $nodes);
                    $nodes = $this->localizeGroupTree($nodes);
                    $groupQuery = trim((string) $request->query->get('gq', ''));
                    if ($groupQuery !== '') {
                        $groupTokens = $this->searchTokens($groupQuery);
                        $nodes = $this->filterGroupTree($nodes, $groupTokens);
                    }
                }
            }
            if ($vehicleId !== '' && $nodeId !== '' && $canSearch) {
                $products = $this->catalog->products($vehicleId, $nodeId);
                $filterNeedle = $oem !== '' ? $oem : ($articleQuery !== '' ? $articleQuery : '');
                if ($filterNeedle !== '') {
                    $tokens = $this->searchTokens($filterNeedle);
                    $products = array_values(array_filter(
                        $products,
                        function ($p) use ($tokens): bool {
                            $hay = mb_strtolower(implode(' ', array_filter([
                                $p->name,
                                (string) ($p->oem ?? ''),
                                (string) ($p->brand ?? ''),
                            ])));

                            return $this->tokensMatch($hay, $tokens);
                        },
                    ));
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error === null) {
            $soft = $this->catalog->softError();
            if ($soft !== null) {
                $error = $soft;
            }
        }

        CatalogCallLogger::log('browse.out', [
            'provider' => $this->catalog->activeProviderId(),
            'vehicleId' => $vehicleId,
            'nodeId' => $nodeId,
            'parentId' => $parentId,
            'fleetId' => $fleetId,
            'carId' => $carId,
            'brandId' => $brandId,
            'year' => $modelYear,
            'vehicles' => count($catalogVehicles),
            'nodes' => count($nodes),
            'products' => count($products),
            'searchResults' => count($searchResults),
            'error' => $error,
        ]);

        $selectedCatalogVehicle = $this->findCatalogVehicle($catalogVehicles, $vehicleId);

        // Historie s typem katalogového vozidla (po HTTP resolve) — znovu otevři session.
        if (
            !$fleetMode
            && $selectedCar !== null
            && $vehicleId !== ''
            && $nodeId === ''
            && $parentId === ''
        ) {
            $type = $this->catalogVehicleMotorLabel($vehicleId);
            $vehicleLabel = trim((string) ($selectedCatalogVehicle?->label ?? ''));
            if ($vehicleLabel === '') {
                $vehicleLabel = $type !== '' ? $type : $this->catalogVehicleTypeLabel($vehicleId);
            }
            $this->pushSearchHistory($request, [
                'brand' => $brandId,
                'car' => $carId,
                'year' => $modelYear,
                'q' => $articleQuery,
                'label' => $selectedCar['label'],
                'brand_name' => $selectedCar['brand'],
                'model' => $selectedCar['model'],
                'vehicle' => $vehicleId,
                'vehicle_label' => $vehicleLabel,
                'type' => $type !== '' ? $type : $vehicleLabel,
                'fuel' => $this->inferFuelFromMotorLabel($type !== '' ? $type : $vehicleLabel) ?? '',
            ]);
        }
        $searchHistory = $this->loadSearchHistory($request);

        return $this->page('@partscatalog/browse/index.html.twig', [
            'pageTitle' => $this->translator->trans('partscatalog.browse.title'),
            'providerId' => $this->catalog->activeProviderId(),
            'providers' => $this->catalog->providersMeta(),
            'selectedFleet' => $selectedFleet,
            'fleetVehicles' => $fleetVehicles,
            'fleetId' => $fleetId,
            'fleetMode' => $fleetMode,
            'catalogBrands' => $catalogBrands,
            'brandId' => $brandId,
            'selectedBrand' => $selectedBrand,
            'catalogCars' => $catalogCars,
            'selectedCar' => $selectedCar,
            'carId' => $carId,
            'yearOptions' => $yearOptions,
            'canSearch' => $canSearch,
            'searchHistory' => $searchHistory,
            'catalogVehicles' => $catalogVehicles,
            'selectedCatalogVehicle' => $selectedCatalogVehicle,
            'nodes' => $nodes,
            'products' => $products,
            'searchResults' => $searchResults,
            'cachedHits' => $cachedHits,
            'vehicleScopedSearch' => $vehicleScopedSearch,
            'groupQuery' => $groupQuery,
            'vehicleId' => $vehicleId,
            'nodeId' => $nodeId,
            'parentId' => $parentId,
            'q' => $q,
            'oem' => $oem,
            'year' => $modelYear,
            'error' => $error,
            'orderId' => $orderId,
            'market' => $this->oemMarketContext(),
            'breadcrumbs' => $this->buildCatalogBreadcrumbs(
                selectedFleet: $selectedFleet,
                fleetId: $fleetId,
                vehicleId: $vehicleId,
                selectedCatalogVehicle: $selectedCatalogVehicle,
                parentId: $parentId,
                nodeId: $nodeId,
                orderId: $orderId,
                brandId: $brandId,
                carId: $carId,
                year: $modelYear,
            ),
        ]);
    }

    public function detail(Request $request): Response
    {
        $productPath = trim((string) $request->query->get('p', ''));
        $vehicleId = trim((string) $request->query->get('vehicle', ''));
        $nodeId = trim((string) $request->query->get('node', ''));
        $parentId = trim((string) $request->query->get('parent', ''));
        $fleetId = trim((string) $request->query->get('fleet', ''));
        $orderId = trim((string) $request->query->get('order_id', ''));

        $this->releaseSessionLock($request);

        $detail = null;
        $error = null;
        try {
            if ($productPath !== '') {
                $detail = $this->catalog->productDetail($productPath);
            }
            if ($detail === null) {
                $error = $this->catalog->softError() ?? $this->translator->trans('partscatalog.detail.not_found');
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $backQuery = array_filter([
            'vehicle' => $vehicleId !== '' ? $vehicleId : null,
            'node' => $nodeId !== '' ? $nodeId : null,
            'parent' => $parentId !== '' ? $parentId : null,
            'fleet' => $fleetId !== '' ? $fleetId : null,
            'order_id' => $orderId !== '' ? $orderId : null,
        ], static fn ($v) => $v !== null);

        $selectedFleet = $fleetId !== '' ? $this->findFleetVehicle($fleetId) : null;
        $detailLabel = $detail?->name;
        if ($detailLabel === null || $detailLabel === '') {
            $detailLabel = $this->translator->trans('partscatalog.detail.title');
        }

        return $this->page('@partscatalog/browse/detail.html.twig', [
            'pageTitle' => $detail?->name ?? $this->translator->trans('partscatalog.detail.title'),
            'detail' => $detail,
            'error' => $error,
            'productPath' => $productPath,
            'vehicleId' => $vehicleId,
            'nodeId' => $nodeId,
            'parentId' => $parentId,
            'fleetId' => $fleetId,
            'orderId' => $orderId,
            'backUrl' => $this->router->url('admin.partscatalog.browse') . ($backQuery !== [] ? ('?' . http_build_query($backQuery)) : ''),
            'providerId' => $this->catalog->activeProviderId(),
            'breadcrumbs' => $this->buildCatalogBreadcrumbs(
                selectedFleet: $selectedFleet,
                fleetId: $fleetId,
                vehicleId: $vehicleId,
                selectedCatalogVehicle: null,
                parentId: $parentId,
                nodeId: $nodeId,
                orderId: $orderId,
                detailLabel: $detailLabel,
            ),
        ]);
    }

    public function settings(): Response
    {
        return $this->page('@partscatalog/settings.html.twig', [
            'pageTitle' => $this->translator->trans('partscatalog.settings.title'),
            'jamaBaseUrl' => (string) $this->settings->get('partscatalog.jama.base_url', 'https://www.autodily-jama.cz'),
        ]);
    }

    public function updateSettings(Request $request): Response
    {
        $definitions = $this->settings->definitions();
        foreach (self::SETTING_KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $definition = $definitions[$key];
            $posted = $request->request->get(str_replace('.', '__', $key));
            $value = is_scalar($posted) ? (string) $posted : '';
            if ($definition['type'] === SettingRecord::TYPE_ENCRYPTED && $value === '') {
                continue;
            }
            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'partscatalog');
        }
        $this->flash('success', $this->translator->trans('partscatalog.flash.settings_saved'));

        return $this->redirect('admin.partscatalog.settings');
    }

    public function attach(Request $request): Response
    {
        $orderId = trim((string) $request->request->get('order_id', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($orderId === '' || $name === '') {
            $this->flash('error', $this->translator->trans('partscatalog.error.attach_invalid'));

            return $this->redirectToReferer($request);
        }
        $qtyRaw = (int) round((float) str_replace(',', '.', trim((string) $request->request->get('qty', '1'))));
        $qty = (string) max(1, min(9999, $qtyRaw > 0 ? $qtyRaw : 1));
        $part = new OrderPart(
            orderId: $orderId,
            nameCs: $name,
            source: trim((string) $request->request->get('source', '')),
            oem: trim((string) $request->request->get('oem', '')) ?: null,
            brand: trim((string) $request->request->get('brand', '')) ?: null,
            nameOriginal: trim((string) $request->request->get('name_original', '')) ?: null,
            qty: $qty,
            externalId: trim((string) $request->request->get('external_id', '')) ?: null,
            unitPrice: trim((string) $request->request->get('unit_price', $request->request->get('price', ''))) ?: null,
        );
        $this->em->persist($part);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('partscatalog.flash.attached'));

        if ($request->headers->get('Accept') === 'application/json' || $request->request->get('ajax')) {
            return new JsonResponse(['ok' => true, 'id' => $part->id()]);
        }

        return $this->redirect('admin.carservice.show', ['id' => $orderId]);
    }

    public function detach(string $id): Response
    {
        $part = $this->em->find(OrderPart::class, $id);
        $orderId = $part instanceof OrderPart ? $part->orderId() : null;
        if ($part instanceof OrderPart) {
            $this->em->remove($part);
            $this->em->flush();
            $this->flash('success', $this->translator->trans('partscatalog.flash.detached'));
        }

        return $orderId !== null
            ? $this->redirect('admin.carservice.show', ['id' => $orderId])
            : $this->redirect('admin.partscatalog.browse');
    }

    /**
     * @param list<\Modules\PartsCatalog\Application\Dto\CatalogVehicleOption> $vehicles
     */
    private function findCatalogVehicle(array $vehicles, string $vehicleId): ?\Modules\PartsCatalog\Application\Dto\CatalogVehicleOption
    {
        if ($vehicleId === '') {
            return null;
        }
        foreach ($vehicles as $v) {
            if ($v->id === $vehicleId) {
                return $v;
            }
        }

        return null;
    }

    private function vehicleIdMatchesProvider(string $providerId, string $vehicleId): bool
    {
        return str_starts_with($vehicleId, 'jama:');
    }

    private function nodeIdMatchesProvider(string $providerId, string $nodeId): bool
    {
        return $nodeId !== ''
            && !str_starts_with($nodeId, 'cat:')
            && !str_starts_with($nodeId, 'mod:')
            && !str_starts_with($nodeId, 'motor:');
    }

    /**
     * Zúží katalogové motorizace podle flotilového vozidla (rok, palivo, výkon, objem, kód motoru).
     *
     * @param list<\Modules\PartsCatalog\Application\Dto\CatalogVehicleOption> $vehicles
     * @param array{brand?: ?string, model?: ?string, year?: ?int, fuel?: ?string, motorization?: ?string, engine_code?: ?string, power_kw?: ?int, displacement_cc?: ?int, tech?: ?array, ...} $fleet
     * @return list<\Modules\PartsCatalog\Application\Dto\CatalogVehicleOption>
     */
    private function filterVehiclesForFleet(array $vehicles, array $fleet): array
    {
        if ($vehicles === []) {
            return [];
        }

        $year = isset($fleet['year']) && is_int($fleet['year']) ? $fleet['year'] : null;
        $fuel = mb_strtolower(trim((string) ($fleet['fuel'] ?? '')));
        $motorRaw = trim((string) ($fleet['motorization'] ?? ''));
        $tech = is_array($fleet['tech'] ?? null) ? $fleet['tech'] : [];

        $engineCode = mb_strtolower(trim((string) ($fleet['engine_code'] ?? $tech['engine_code'] ?? '')));
        if ($engineCode === '' && preg_match('/\b([a-z]{2,4}\d[a-z0-9]?)\b/iu', $motorRaw, $m) === 1) {
            $engineCode = mb_strtolower($m[1]);
        }

        $powerKw = $this->fleetPowerKw($fleet, $motorRaw);
        $displacementCc = $this->fleetDisplacementCc($fleet, $motorRaw);
        $liters = $displacementCc !== null && $displacementCc > 0
            ? round($displacementCc / 1000, 1)
            : null;

        $fuelNeedles = match ($fuel) {
            '', 'other' => [],
            'petrol' => ['benzín', 'benzin', 'petrol', 'gasoline', 'gba', 'tsi', 'tfsi', 'mpi', 'gti', 'gdi', 'fsi'],
            'diesel' => ['nafta', 'diesel', 'tdi', 'tdci', 'dci', 'hdi', 'cdi', 'crdi'],
            'lpg' => ['lpg', 'plyn'],
            'cng' => ['cng'],
            'hybrid' => ['hybrid', 'phev', 'mhev'],
            'electric' => ['elektřina', 'elektrina', 'electric', 'ev', 'bev'],
            default => [$fuel],
        };

        $petrolMarkers = ['tsi', 'tfsi', 'mpi', 'gti', 'gdi', 'fsi', 'cgi', 'benzin', 'benzín', 'petrol', 'gasoline'];
        $dieselMarkers = ['tdi', 'tdci', 'dci', 'hdi', 'cdi', 'crdi', 'nafta', 'diesel', 'ddis'];

        $score = static function ($v) use (
            $year,
            $fuel,
            $fuelNeedles,
            $petrolMarkers,
            $dieselMarkers,
            $engineCode,
            $powerKw,
            $liters,
        ): int {
            $hay = mb_strtolower(($v->hint ?? '') . ' ' . $v->label);
            $hayNorm = preg_replace('/[._]+/u', ' ', $hay) ?? $hay;
            $hayNorm = preg_replace('/\s+/u', ' ', $hayNorm) ?? $hayNorm;
            $hayCompact = preg_replace('/\s+/u', '', $hayNorm) ?? $hayNorm;

            // Tvrdý filtr: nesoulad paliva.
            if ($fuel === 'diesel') {
                foreach ($petrolMarkers as $marker) {
                    if (str_contains($hayNorm, $marker)) {
                        return -1000;
                    }
                }
            }
            if ($fuel === 'petrol') {
                foreach ($dieselMarkers as $marker) {
                    if (str_contains($hayNorm, $marker)) {
                        return -1000;
                    }
                }
            }

            $s = 0;
            if ($year !== null && (str_contains($hayNorm, (string) $year) || preg_match('/\b' . $year . '\b/', $hayNorm) === 1)) {
                $s += 35;
            } elseif ($year !== null && preg_match_all('/(19|20)\d{2}/', $hayNorm, $ym) > 0) {
                $years = array_map('intval', $ym[0]);
                $minY = min($years);
                $maxY = max($years);
                if ($year >= $minY && $year <= $maxY) {
                    $s += 30;
                } else {
                    $s -= 25;
                }
            }

            foreach ($fuelNeedles as $needle) {
                if ($needle !== '' && str_contains($hayNorm, $needle)) {
                    $s += 30;
                    break;
                }
            }

            if ($powerKw !== null) {
                if (
                    preg_match('/\b' . preg_quote((string) $powerKw, '/') . '\s*kw\b/u', $hayNorm) === 1
                    || str_contains($hayCompact, $powerKw . 'kw')
                ) {
                    $s += 90;
                } elseif (preg_match('/(\d{2,3})\s*kw/u', $hayNorm, $pm) === 1) {
                    $diff = abs((int) $pm[1] - $powerKw);
                    if ($diff <= 2) {
                        $s += 45;
                    } elseif ($diff <= 8) {
                        $s -= 15;
                    } else {
                        $s -= 50;
                    }
                } else {
                    $s -= 20;
                }
            }

            if ($liters !== null) {
                $lDot = number_format($liters, 1, '.', '');
                $lCompact = str_replace('.', '', $lDot);
                $lSpaced = str_replace('.', ' ', $lDot);
                $lDash = str_replace('.', '-', $lDot);
                if (
                    str_contains($hayNorm, $lDot)
                    || str_contains($hayNorm, $lSpaced)
                    || str_contains($hayNorm, $lDash)
                    || str_contains($hayCompact, $lCompact)
                    || preg_match('/\b' . preg_quote($lCompact[0], '/') . '\s+' . preg_quote(substr($lCompact, 1), '/') . '\b/u', $hayNorm) === 1
                ) {
                    $s += 55;
                } else {
                    $s -= 25;
                }
            }

            if ($engineCode !== '' && (str_contains($hayNorm, $engineCode) || str_contains($hayCompact, $engineCode))) {
                $s += 70;
            }

            return $s;
        };

        $ranked = [];
        foreach ($vehicles as $v) {
            $ranked[] = ['v' => $v, 's' => $score($v)];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['s'] <=> $a['s']);

        // Odfiltruj tvrdé neshody.
        $viable = array_values(array_filter($ranked, static fn (array $r): bool => $r['s'] > -500));
        if ($viable === []) {
            $viable = $ranked;
        }

        // Když známe kW, preferuj jen řádky s rozumným skóre (výkon/objem).
        $strong = array_values(array_filter($viable, static fn (array $r): bool => $r['s'] >= 50));
        $use = $strong !== [] ? $strong : array_values(array_filter($viable, static fn (array $r): bool => $r['s'] > 0));
        if ($use === []) {
            $use = $viable;
        }

        return array_map(static fn (array $r) => $r['v'], $use);
    }

    /** @param array<string, mixed> $fleet */
    private function fleetPowerKw(array $fleet, string $motorRaw): ?int
    {
        $tech = is_array($fleet['tech'] ?? null) ? $fleet['tech'] : [];
        foreach ([$fleet['power_kw'] ?? null, $tech['power_kw'] ?? null] as $raw) {
            if (is_numeric($raw) && (int) $raw > 0) {
                return (int) $raw;
            }
        }
        if (preg_match('/(\d{2,3})\s*k\s*w/iu', $motorRaw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** @param array<string, mixed> $fleet */
    private function fleetDisplacementCc(array $fleet, string $motorRaw): ?int
    {
        $tech = is_array($fleet['tech'] ?? null) ? $fleet['tech'] : [];
        foreach ([$fleet['displacement_cc'] ?? null, $tech['displacement_cc'] ?? null] as $raw) {
            if (is_numeric($raw) && (int) $raw > 0) {
                return (int) $raw;
            }
        }
        if (preg_match('/(\d{3,4})\s*cm/iu', $motorRaw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array{id: string, plate: string, brand: ?string, model: ?string, year: ?int, vin: ?string, fuel: ?string, motorization: ?string, engine_code: ?string, power_kw: ?int, displacement_cc: ?int, tech: array<string, mixed>, status: string, label: string, photo_url: ?string}|null
     */
    private function findFleetVehicle(string $id): ?array
    {
        if (!class_exists(\Modules\Fleet\Domain\Vehicle::class) || $id === '') {
            return null;
        }
        try {
            $v = $this->em->find(\Modules\Fleet\Domain\Vehicle::class, $id);
        } catch (\Throwable) {
            return null;
        }
        if (!$v instanceof \Modules\Fleet\Domain\Vehicle) {
            return null;
        }
        $label = trim(($v->brand() ?? '') . ' ' . ($v->model() ?? ''));
        if ($label === '') {
            $label = $v->plate();
        }
        $tech = $v->techJson() ?? [];
        $engineCode = isset($tech['engine_code']) ? trim((string) $tech['engine_code']) : '';
        $powerKw = isset($tech['power_kw']) && is_numeric($tech['power_kw']) ? (int) $tech['power_kw'] : null;
        $displacementCc = isset($tech['displacement_cc']) && is_numeric($tech['displacement_cc']) ? (int) $tech['displacement_cc'] : null;

        return [
            'id' => $v->id(),
            'plate' => $v->plate(),
            'brand' => $v->brand(),
            'model' => $v->model(),
            'year' => $v->modelYear(),
            'vin' => $v->vin(),
            'fuel' => $v->fuel(),
            'motorization' => $v->motorization(),
            'engine_code' => $engineCode !== '' ? $engineCode : null,
            'power_kw' => $powerKw !== null && $powerKw > 0 ? $powerKw : null,
            'displacement_cc' => $displacementCc !== null && $displacementCc > 0 ? $displacementCc : null,
            'tech' => $tech,
            'status' => $v->status(),
            'label' => $label,
            'photo_url' => $this->fleetPhotoUrl($v->id()),
        ];
    }

    /**
     * @param array{q?: string, plate?: string, vin?: string, brand?: string, year?: string} $filters
     * @return list<array{id: string, plate: string, brand: ?string, model: ?string, year: ?int, vin: ?string, fuel: ?string, motorization: ?string, status: string, label: string, photo_url: ?string}>
     */
    private function loadFleetVehicles(array $filters): array
    {
        if (!class_exists(\Modules\Fleet\Domain\Vehicle::class)) {
            return [];
        }

        try {
            $qb = $this->em->createQueryBuilder()
                ->select('v')
                ->from(\Modules\Fleet\Domain\Vehicle::class, 'v')
                ->orderBy('v.updatedAt', 'DESC')
                ->setMaxResults(60);

            if (($filters['plate'] ?? '') !== '') {
                $qb->andWhere('LOWER(v.plate) LIKE :plate')->setParameter('plate', '%' . mb_strtolower($filters['plate']) . '%');
            }
            if (($filters['vin'] ?? '') !== '') {
                $qb->andWhere('LOWER(v.vin) LIKE :vin')->setParameter('vin', '%' . mb_strtolower($filters['vin']) . '%');
            }
            if (($filters['brand'] ?? '') !== '') {
                $qb->andWhere('LOWER(v.brand) LIKE :brand OR LOWER(v.model) LIKE :brand')
                    ->setParameter('brand', '%' . mb_strtolower($filters['brand']) . '%');
            }
            if (($filters['year'] ?? '') !== '' && ctype_digit($filters['year'])) {
                $qb->andWhere('v.modelYear = :year')->setParameter('year', (int) $filters['year']);
            }
            if (($filters['q'] ?? '') !== '') {
                $q = '%' . mb_strtolower($filters['q']) . '%';
                $qb->andWhere('LOWER(v.plate) LIKE :q OR LOWER(v.vin) LIKE :q OR LOWER(v.brand) LIKE :q OR LOWER(v.model) LIKE :q')
                    ->setParameter('q', $q);
            }

            $rows = $qb->getQuery()->getResult();
        } catch (\Throwable $e) {
            bdump(['error' => $e->getMessage()], 'partscatalog.fleet.load');

            return [];
        }

        $out = [];
        foreach ($rows as $v) {
            if (!$v instanceof \Modules\Fleet\Domain\Vehicle) {
                continue;
            }
            $label = trim(($v->brand() ?? '') . ' ' . ($v->model() ?? ''));
            if ($label === '') {
                $label = $v->plate();
            }
            $out[] = [
                'id' => $v->id(),
                'plate' => $v->plate(),
                'brand' => $v->brand(),
                'model' => $v->model(),
                'year' => $v->modelYear(),
                'vin' => $v->vin(),
                'fuel' => $v->fuel(),
                'motorization' => $v->motorization(),
                'status' => $v->status(),
                'label' => $label,
                'photo_url' => $this->fleetPhotoUrl($v->id()),
            ];
        }

        return $out;
    }

    private function fleetPhotoUrl(string $vehicleId): ?string
    {
        if ($vehicleId === '' || !$this->capabilities->has('media')) {
            return null;
        }
        if (!class_exists(\Modules\Media\Domain\MediaAsset::class)
            || !class_exists(\Modules\Media\Application\MediaProvider::class)) {
            return null;
        }
        try {
            $media = $this->capabilities->get('media');
            if (!$media instanceof \Modules\Media\Application\MediaProvider) {
                return null;
            }
            $items = $media->forEntity('fleet_vehicle', $vehicleId, null, 12);
        } catch (\Throwable) {
            return null;
        }
        foreach ($items as $row) {
            $asset = $row['asset'] ?? null;
            if ($asset instanceof \Modules\Media\Domain\MediaAsset && $asset->isImage()) {
                try {
                    return $this->router->url('admin.media.file', ['id' => $asset->id()]);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function redirectToReferer(Request $request): Response
    {
        $ref = $request->headers->get('Referer');
        if (is_string($ref) && $ref !== '') {
            return $this->redirectTo($ref);
        }

        return $this->redirect('admin.partscatalog.browse');
    }

    /**
     * Značky z katalogu Fleet settings.
     *
     * @return list<array{id: string, name: string}>
     */
    private function loadFleetCatalogBrands(): array
    {
        if (!class_exists(\Modules\Fleet\Domain\VehicleBrand::class)) {
            return [];
        }

        try {
            /** @var list<\Modules\Fleet\Domain\VehicleBrand> $brands */
            $brands = $this->em->getRepository(\Modules\Fleet\Domain\VehicleBrand::class)->findBy([], ['name' => 'ASC']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($brands as $brand) {
            $out[] = [
                'id' => $brand->id(),
                'name' => $brand->name(),
            ];
        }

        return $out;
    }

    /**
     * Modely z katalogu Fleet settings (značka + model).
     *
     * @param list<array{id: string, name: string}>|null $brands už načtené značky (bez druhého SELECT)
     * @return list<array{id: string, brand_id: string, brand: string, model: string, label: string}>
     */
    private function loadFleetCatalogCars(?array $brands = null): array
    {
        if (!class_exists(\Modules\Fleet\Domain\VehicleBrand::class)
            || !class_exists(\Modules\Fleet\Domain\VehicleModel::class)) {
            return [];
        }

        try {
            if ($brands === null) {
                /** @var list<\Modules\Fleet\Domain\VehicleBrand> $brandEntities */
                $brandEntities = $this->em->getRepository(\Modules\Fleet\Domain\VehicleBrand::class)->findBy([], ['name' => 'ASC']);
                $brands = [];
                foreach ($brandEntities as $brand) {
                    $brands[] = [
                        'id' => $brand->id(),
                        'name' => $brand->name(),
                    ];
                }
            }
            /** @var list<\Modules\Fleet\Domain\VehicleModel> $models */
            $models = $this->em->getRepository(\Modules\Fleet\Domain\VehicleModel::class)->findBy([], ['name' => 'ASC']);
        } catch (\Throwable) {
            return [];
        }

        $brandNames = [];
        foreach ($brands as $brand) {
            $brandNames[$brand['id']] = $brand['name'];
        }

        $out = [];
        foreach ($models as $model) {
            $brandName = $brandNames[$model->brandId()] ?? '';
            $label = trim($brandName . ' ' . $model->name());
            if ($label === '') {
                continue;
            }
            $out[] = [
                'id' => $model->id(),
                'brand_id' => $model->brandId(),
                'brand' => $brandName,
                'model' => $model->name(),
                'label' => $label,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * @param list<array{id: string, name: string}> $brands
     * @return array{id: string, name: string}|null
     */
    private function findCatalogBrand(array $brands, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($brands as $brand) {
            if ($brand['id'] === $id) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: string, brand_id: string, brand: string, model: string, label: string}> $cars
     * @return array{id: string, brand_id: string, brand: string, model: string, label: string}|null
     */
    private function findCatalogCar(array $cars, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($cars as $car) {
            if ($car['id'] === $id) {
                return $car;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function yearOptions(): array
    {
        $years = [];
        for ($y = (int) date('Y') + 1; $y >= 1985; $y--) {
            $years[] = $y;
        }

        return $years;
    }

    /**
     * @return list<array{id: string, brand_id: string, car: string, year: string, q: string, label: string, brand: string, model: string, vehicle: string, vehicle_label: string, type: string, at: int}>
     */
    private function loadSearchHistory(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $raw = $session->get('partscatalog.search_history', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $brandId = (string) ($row['brand_id'] ?? '');
            $brandName = (string) ($row['brand_name'] ?? '');
            if ($brandId === '' && isset($row['brand']) && is_string($row['brand'])) {
                if (preg_match('/^[0-9a-f-]{36}$/i', $row['brand']) === 1) {
                    $brandId = $row['brand'];
                } elseif ($brandName === '') {
                    $brandName = $row['brand'];
                }
            } elseif ($brandName === '' && isset($row['brand'])) {
                $brandName = (string) $row['brand'];
            }
            $vehicle = (string) ($row['vehicle'] ?? '');
            $vehicleLabel = (string) ($row['vehicle_label'] ?? '');
            $type = (string) ($row['type'] ?? '');
            if ($type === '' && $vehicle !== '') {
                $type = $this->catalogVehicleMotorLabel($vehicle);
            }
            if ($type === '') {
                $type = $vehicleLabel;
            }
            $fuel = (string) ($row['fuel'] ?? '');
            if ($fuel === '') {
                $fuel = $this->inferFuelFromMotorLabel($type !== '' ? $type : $vehicleLabel) ?? '';
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                $id = $this->searchHistoryId([
                    'brand_id' => $brandId,
                    'car' => (string) ($row['car'] ?? ''),
                    'year' => (string) ($row['year'] ?? ''),
                    'q' => (string) ($row['q'] ?? ''),
                    'vehicle' => $vehicle,
                ]);
            }
            $logoUrl = null;
            if ($brandName !== '') {
                try {
                    $logoUrl = $this->vehicleCatalog->logoUrl($brandName);
                } catch (\Throwable) {
                    $logoUrl = null;
                }
            }
            $out[] = [
                'id' => $id,
                'brand_id' => $brandId,
                'car' => (string) ($row['car'] ?? ''),
                'year' => (string) ($row['year'] ?? ''),
                'q' => (string) ($row['q'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'brand' => $brandName,
                'model' => (string) ($row['model'] ?? ''),
                'vehicle' => $vehicle,
                'vehicle_label' => $vehicleLabel,
                'type' => $type,
                'fuel' => $fuel,
                'logo_url' => $logoUrl,
                'at' => (int) ($row['at'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array{brand?: string, car: string, year: string, q: string, label: string, brand_name?: string, model: string, vehicle?: string, vehicle_label?: string, type?: string, fuel?: string} $entry
     */
    private function pushSearchHistory(Request $request, array $entry): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $vehicle = (string) ($entry['vehicle'] ?? '');
        $vehicleLabel = (string) ($entry['vehicle_label'] ?? '');
        $type = (string) ($entry['type'] ?? '');
        $fuel = (string) ($entry['fuel'] ?? '');
        if ($fuel === '') {
            $fuel = $this->inferFuelFromMotorLabel($type !== '' ? $type : $vehicleLabel) ?? '';
        }
        $id = $this->searchHistoryId([
            'brand_id' => (string) ($entry['brand'] ?? ''),
            'car' => $entry['car'],
            'year' => $entry['year'],
            'q' => $entry['q'],
            'vehicle' => $vehicle,
        ]);
        $items = $this->loadSearchHistory($request);
        $items = array_values(array_filter(
            $items,
            static fn (array $row): bool => $row['id'] !== $id,
        ));
        array_unshift($items, [
            'id' => $id,
            'brand_id' => (string) ($entry['brand'] ?? ''),
            'car' => $entry['car'],
            'year' => $entry['year'],
            'q' => $entry['q'],
            'label' => $entry['label'] !== '' ? $entry['label'] : ($entry['q'] !== '' ? $entry['q'] : '—'),
            'brand' => (string) ($entry['brand_name'] ?? ''),
            'model' => $entry['model'],
            'vehicle' => $vehicle,
            'vehicle_label' => $vehicleLabel,
            'type' => $type,
            'fuel' => $fuel,
            'at' => time(),
        ]);
        $session->set('partscatalog.search_history', array_slice($items, 0, 24));
        $session->save();
    }

    private function forgetSearchHistory(Request $request, string $id): void
    {
        if ($id === '' || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $items = array_values(array_filter(
            $this->loadSearchHistory($request),
            static fn (array $row): bool => $row['id'] !== $id,
        ));
        $session->set('partscatalog.search_history', $items);
        $session->save();
    }

    /** @param array{brand_id?: string, car?: string, year?: string, q?: string, vehicle?: string} $parts */
    private function searchHistoryId(array $parts): string
    {
        $raw = mb_strtolower(implode('|', [
            (string) ($parts['brand_id'] ?? ''),
            (string) ($parts['car'] ?? ''),
            (string) ($parts['year'] ?? ''),
            (string) ($parts['vehicle'] ?? ''),
            (string) ($parts['q'] ?? ''),
        ]));

        return substr(hash('sha256', $raw), 0, 16);
    }

    private function catalogVehicleMotorLabel(string $vehicleId): string
    {
        if (preg_match('/^jama:[^:]+:[^:]+:(.+)$/', $vehicleId, $m) === 1) {
            return $this->humanizeSlug($m[1]);
        }

        return '';
    }

    /**
     * @return list<CatalogArticle>
     */
    private function searchPartsForVehicle(string $vehicleId, string $query, int $limit = 60): array
    {
        $tokens = $this->searchTokens($query);
        if ($tokens === []) {
            return [];
        }

        $hits = [];
        $seen = [];

        foreach ($this->searchSyncedPartsForVehicle($vehicleId, $tokens, $limit) as $article) {
            $key = mb_strtolower($article->id . '|' . ($article->oem ?? '') . '|' . $article->name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hits[] = $article;
            if (count($hits) >= $limit) {
                return $hits;
            }
        }

        $path = $this->jamaVehiclePathPrefix($vehicleId);
        if ($path !== null) {
            foreach ($this->apiCache->searchProductsForVehicle($query, $path, $limit) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $key = mb_strtolower($id . '|' . ($row['oem'] ?? '') . '|' . $row['name']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $hits[] = new CatalogArticle(
                    $id,
                    (string) $row['name'],
                    null,
                    isset($row['oem']) ? (string) $row['oem'] : null,
                    isset($row['brand']) ? (string) $row['brand'] : null,
                    isset($row['image_url']) ? (string) $row['image_url'] : null,
                );
                if (count($hits) >= $limit) {
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $tokens
     * @return list<CatalogArticle>
     */
    private function searchSyncedPartsForVehicle(string $vehicleExternalId, array $tokens, int $limit): array
    {
        if (!class_exists(CatalogVehicle::class) || !class_exists(CatalogPart::class)) {
            return [];
        }
        try {
            $vehicle = $this->em->getRepository(CatalogVehicle::class)->findOneBy([
                'externalId' => $vehicleExternalId,
            ]);
        } catch (\Throwable) {
            return [];
        }
        if (!$vehicle instanceof CatalogVehicle) {
            return [];
        }

        try {
            $qb = $this->em->createQueryBuilder()
                ->select('p')
                ->from(CatalogPart::class, 'p')
                ->innerJoin(CatalogVehiclePart::class, 'vp', 'WITH', 'vp.partId = p.id')
                ->where('vp.vehicleId = :vid')
                ->setParameter('vid', $vehicle->id())
                ->orderBy('p.name', 'ASC')
                ->setMaxResults(max(80, $limit * 3));

            /** @var list<CatalogPart> $parts */
            $parts = $qb->getQuery()->getResult();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $hay = mb_strtolower(implode(' ', array_filter([
                $part->name(),
                (string) ($part->oem() ?? ''),
                (string) ($part->brand() ?? ''),
            ])));
            if (!$this->tokensMatch($hay, $tokens)) {
                continue;
            }
            $out[] = new CatalogArticle(
                $part->externalId(),
                $part->name(),
                null,
                $part->oem(),
                $part->brand(),
                $part->imageUrl(),
            );
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function jamaVehiclePathPrefix(string $vehicleId): ?string
    {
        if (preg_match('/^jama:([^:]+):([^:]+):(.+)$/', $vehicleId, $m) !== 1) {
            return null;
        }

        return '/autodily/' . $m[1] . '/' . $m[2] . '/' . $m[3];
    }

    /** @return list<string> */
    private function searchTokens(string $query): array
    {
        $parts = preg_split('/[\s,;+]+/u', mb_strtolower(trim($query))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param list<string> $tokens */
    private function tokensMatch(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!str_contains($haystack, $token)) {
                return false;
            }
        }

        return $tokens !== [];
    }

    private function inferFuelFromMotorLabel(string $label): ?string
    {
        $v = mb_strtolower(trim($label));
        if ($v === '') {
            return null;
        }
        if (preg_match('/\b(tdi|tdci|dci|hdi|cdi|crdi|jtd|nafta|diesel|ddis|skyactiv-d)\b/u', $v) === 1) {
            return 'diesel';
        }
        if (preg_match('/\b(phev|mhev|hybrid|e-hybrid)\b/u', $v) === 1) {
            return 'hybrid';
        }
        if (preg_match('/\b(ev|bev|electric|elektro|elektromobil)\b/u', $v) === 1) {
            return 'electric';
        }
        if (preg_match('/\blpg\b/u', $v) === 1) {
            return 'lpg';
        }
        if (preg_match('/\bcng\b/u', $v) === 1) {
            return 'cng';
        }
        if (preg_match('/\b(tsi|tfsi|mpi|gti|gdi|fsi|cgi|benzin|petrol|gasoline|gba|skyactiv-g)\b/u', $v) === 1) {
            return 'petrol';
        }

        return null;
    }

    /**
     * Zápis + zavření session před dlouhými outbound HTTP.
     * Bez toho drží PHP exclusive lock na session souboru → paralelní request
     * (nebo kill při timeoutu) = ztráta přihlášení.
     */
    private function releaseSessionLock(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }
    }

    /**
     * Přehled → [auto z flotily] → Katalog dílů → [typ] → [kategorie…] → [detail dílu].
     *
     * @param array{id: string, plate: string, label: string, ...}|null $selectedFleet
     * @return list<array{label: string, url?: string|null}>
     */
    private function buildCatalogBreadcrumbs(
        ?array $selectedFleet,
        string $fleetId,
        string $vehicleId,
        ?\Modules\PartsCatalog\Application\Dto\CatalogVehicleOption $selectedCatalogVehicle,
        string $parentId,
        string $nodeId,
        string $orderId,
        string $brandId = '',
        string $carId = '',
        string $year = '',
        ?string $detailLabel = null,
    ): array {
        $crumbs = [
            [
                'label' => $this->translator->trans('nav.overview'),
                'url' => $this->router->url('admin.dashboard'),
            ],
        ];

        $baseQuery = [];
        if ($orderId !== '') {
            $baseQuery['order_id'] = $orderId;
        }
        if ($brandId !== '') {
            $baseQuery['brand'] = $brandId;
        }
        if ($carId !== '') {
            $baseQuery['car'] = $carId;
        }
        if ($year !== '') {
            $baseQuery['year'] = $year;
        }

        if ($selectedFleet !== null) {
            $fleetShowUrl = null;
            try {
                $fleetShowUrl = $this->router->url('admin.fleet.show', ['id' => $selectedFleet['id']]);
            } catch (\Throwable) {
                $fleetShowUrl = null;
            }
            $crumbs[] = [
                'label' => $selectedFleet['plate'] !== '' ? $selectedFleet['plate'] : $selectedFleet['label'],
                'url' => $fleetShowUrl,
            ];
        }

        $catalogQuery = $baseQuery;
        if ($fleetId !== '') {
            $catalogQuery['fleet'] = $fleetId;
        }

        $atCatalogRoot = $vehicleId === '' && $parentId === '' && $nodeId === '' && $detailLabel === null;
        $crumbs[] = [
            'label' => $this->translator->trans('nav.partscatalog'),
            'url' => $atCatalogRoot ? null : $this->browseQueryUrl($catalogQuery),
        ];

        if ($vehicleId === '') {
            return $this->finalizeBreadcrumbs($crumbs);
        }

        $typeLabel = trim((string) ($selectedCatalogVehicle?->label ?? ''));
        if ($typeLabel === '') {
            $typeLabel = $this->catalogVehicleTypeLabel($vehicleId);
        }

        $typeQuery = $catalogQuery;
        $typeQuery['vehicle'] = $vehicleId;
        $atTypeRoot = $parentId === '' && $nodeId === '' && $detailLabel === null;
        $crumbs[] = [
            'label' => $typeLabel,
            'url' => $atTypeRoot ? null : $this->browseQueryUrl($typeQuery),
        ];

        $path = $nodeId !== '' ? $nodeId : $parentId;
        $segments = $this->categoryPathSegments($path);
        $lastIdx = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            $isTrailEnd = $i === $lastIdx && $detailLabel === null;
            $segQuery = $typeQuery;
            if ($nodeId !== '' && $i === $lastIdx) {
                $segQuery['node'] = $seg['id'];
                if ($i > 0) {
                    $segQuery['parent'] = $segments[$i - 1]['id'];
                } elseif ($parentId !== '') {
                    $segQuery['parent'] = $parentId;
                }
                $url = $isTrailEnd ? null : $this->browseQueryUrl($segQuery);
            } else {
                $segQuery['parent'] = $seg['id'];
                $url = $isTrailEnd ? null : $this->browseQueryUrl($segQuery);
            }
            $crumbs[] = [
                'label' => $seg['label'],
                'url' => $url,
            ];
        }

        if ($detailLabel !== null && $detailLabel !== '') {
            $crumbs[] = ['label' => $detailLabel];
        }

        return $this->finalizeBreadcrumbs($crumbs);
    }

    /**
     * @param list<array{label: string, url?: string|null}> $crumbs
     * @return list<array{label: string, url?: string|null}>
     */
    private function finalizeBreadcrumbs(array $crumbs): array
    {
        if ($crumbs === []) {
            return $crumbs;
        }
        $last = count($crumbs) - 1;
        unset($crumbs[$last]['url']);

        return array_values($crumbs);
    }

    /** @param array<string, scalar> $query */
    private function browseQueryUrl(array $query): string
    {
        $base = $this->router->url('admin.partscatalog.browse');
        $filtered = array_filter(
            $query,
            static fn ($v) => $v !== null && $v !== '',
        );

        return $filtered === [] ? $base : $base . '?' . http_build_query($filtered);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function categoryPathSegments(string $path): array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
        $out = [];
        $accum = '';
        foreach ($parts as $part) {
            $accum = $accum === '' ? $part : $accum . '/' . $part;
            $out[] = [
                'id' => $accum,
                'label' => CategoryTaxonomy::resolve($this->translator, $part, $this->humanizeSlug($part))['label'],
            ];
        }

        return $out;
    }

    private function catalogVehicleTypeLabel(string $vehicleId): string
    {
        if (preg_match('/^jama:[^:]+:([^:]+):(.+)$/', $vehicleId, $m) === 1) {
            return $this->humanizeSlug($m[1] . '-' . $m[2]);
        }

        return $vehicleId;
    }

    private function humanizeSlug(string $slug): string
    {
        $s = rawurldecode(str_replace(['-', '_', ':'], ' ', $slug));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Preload one level of subgroups for expandable category rows.
     * Jen DB / api_cache — bez N× remote fetch při cold cache.
     *
     * @param list<CatalogNode> $nodes
     * @return list<CatalogNode>
     */
    private function preloadGroupChildren(string $vehicleId, array $nodes): array
    {
        $parentIds = [];
        foreach ($nodes as $node) {
            if ($node->hasChildren) {
                $parentIds[] = $node->id;
            }
        }

        $trees = [];
        if ($parentIds !== []) {
            try {
                $trees = $this->catalog->treesLocal($vehicleId, $parentIds);
            } catch (\Throwable) {
                $trees = [];
            }
        }

        $out = [];
        foreach ($nodes as $node) {
            if (!$node->hasChildren) {
                $out[] = $node;
                continue;
            }
            $out[] = $node->withChildren($trees[$node->id] ?? []);
        }

        return $out;
    }

    /**
     * Apply Czech/English labels + icons from taxonomy map.
     *
     * @param list<CatalogNode> $nodes
     * @return list<CatalogNode>
     */
    private function localizeGroupTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $meta = CategoryTaxonomy::resolve($this->translator, $node->id, $node->name);
            $children = [];
            foreach ($node->children as $child) {
                $childMeta = CategoryTaxonomy::resolve($this->translator, $child->id, $child->name);
                $children[] = $child->withPresentation($childMeta['label'], $childMeta['icon']);
            }
            $out[] = $node->withPresentation($meta['label'], $meta['icon'])->withChildren($children);
        }

        return $out;
    }

    /**
     * Keep groups that match the filter, or have a matching subgroup.
     *
     * @param list<CatalogNode> $nodes
     * @param list<string> $tokens
     * @return list<CatalogNode>
     */
    private function filterGroupTree(array $nodes, array $tokens): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $hay = mb_strtolower($node->name . ' ' . CategoryTaxonomy::leafSlug($node->id));
            $selfMatch = $this->tokensMatch($hay, $tokens);
            $matchedChildren = [];
            foreach ($node->children as $child) {
                $childHay = mb_strtolower($child->name . ' ' . CategoryTaxonomy::leafSlug($child->id));
                if ($this->tokensMatch($childHay, $tokens)) {
                    $matchedChildren[] = $child;
                }
            }
            if ($selfMatch) {
                $out[] = $node;
                continue;
            }
            if ($matchedChildren !== []) {
                $out[] = $node->withChildren($matchedChildren);
            }
        }

        return $out;
    }

    /**
     * @return array{enabled: bool, search_url: string, offers_url: string, sources: list<string>}
     */
    private function oemMarketContext(): array
    {
        $empty = [
            'enabled' => false,
            'search_url' => '',
            'offers_url' => '',
            'sources' => [],
        ];

        if (!$this->modules->isEnabled('searchengines') || !$this->capabilities->has('searchengines')) {
            return $empty;
        }

        $user = $this->auth->user();
        if ($user === null || !$user->can('searchengines.view')) {
            return $empty;
        }

        $cap = $this->capabilities->get('searchengines');
        if (!$cap instanceof SearchEnginesProvider) {
            return $empty;
        }

        $sources = $cap->availableSourceIds();
        $allowed = $this->configuredMarketSources();
        if ($allowed !== []) {
            $sources = array_values(array_filter(
                $sources,
                static fn (string $id): bool => in_array($id, $allowed, true),
            ));
        }
        if ($sources === []) {
            return $empty;
        }

        try {
            return [
                'enabled' => true,
                'search_url' => $this->router->url('admin.searchengines.search'),
                'offers_url' => $this->router->url('admin.searchengines.offers'),
                'sources' => $sources,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Poskytovatelé porovnání cen z nastavení CarService (bez cyklistických zdrojů ve výchozím stavu).
     *
     * @return list<string>
     */
    private function configuredMarketSources(): array
    {
        $raw = trim((string) $this->settings->get('carservice.market_sources', 'heureka,seznam,hledejceny,jama,autodilypro,autokelly'));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): string => trim($id),
            explode(',', $raw),
        ), static fn (string $id): bool => $id !== ''));
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
