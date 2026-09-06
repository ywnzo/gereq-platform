<?php

declare(strict_types=1);

namespace Modules\BikeService\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\BikeService\Application\BikeIndexClient;
use Modules\BikeService\Application\CityBikesClient;
use Modules\BikeService\Application\OsmBikePoisClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ExploreController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly BikeIndexClient $bikeIndex,
        private readonly CityBikesClient $cityBikes,
        private readonly OsmBikePoisClient $osmPois,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->query->get('tab', 'serial');
        if ($tab === 'manufacturers') {
            return $this->redirect('admin.bikeservice.manufacturers');
        }
        if (!in_array($tab, ['serial', 'stolen', 'citybikes', 'osm'], true)) {
            $tab = 'serial';
        }

        $serial = trim((string) $request->query->get('serial', ''));
        $location = trim((string) $request->query->get('location', (string) $this->settings->get('bikeservice.default_city', 'Praha')));
        $networkId = trim((string) $request->query->get('network', (string) $this->settings->get('bikeservice.default_citybikes_network', 'nextbike-praha')));
        $country = trim((string) $request->query->get('country', 'CZ'));
        $cityQ = trim((string) $request->query->get('city', ''));
        $lat = (float) $request->query->get('lat', (string) $this->settings->get('bikeservice.default_lat', '50.087'));
        $lon = (float) $request->query->get('lon', (string) $this->settings->get('bikeservice.default_lon', '14.421'));
        $radius = (int) $request->query->get('radius', '2500');

        $serialResult = null;
        $stolenResult = null;
        $networksResult = null;
        $networkDetail = null;
        $osmResult = null;

        if ($tab === 'serial' && $serial !== '') {
            $serialResult = $this->bikeIndex->searchBySerial($serial, 15);
        }
        if ($tab === 'stolen' && $location !== '') {
            $stolenResult = $this->bikeIndex->searchStolenNearby($location, 80, 15);
        }
        if ($tab === 'citybikes') {
            $networksResult = $this->cityBikes->networks($country !== '' ? $country : null, $cityQ !== '' ? $cityQ : null);
            if ($networkId !== '') {
                $networkDetail = $this->cityBikes->network($networkId);
            }
        }
        if ($tab === 'osm') {
            $osmResult = $this->osmPois->nearby($lat, $lon, $radius, 25);
        }

        return $this->page('@bikeservice/explore/index.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.explore.title'),
            'tab' => $tab,
            'serial' => $serial,
            'location' => $location,
            'networkId' => $networkId,
            'country' => $country,
            'cityQ' => $cityQ,
            'lat' => $lat,
            'lon' => $lon,
            'radius' => $radius,
            'serialResult' => $serialResult,
            'stolenResult' => $stolenResult,
            'networksResult' => $networksResult,
            'networkDetail' => $networkDetail,
            'osmResult' => $osmResult,
        ]);
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
