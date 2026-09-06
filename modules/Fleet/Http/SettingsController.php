<?php

declare(strict_types=1);

namespace Modules\Fleet\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\Fleet\Application\VehicleCatalogClient;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
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
        private readonly EntityManagerInterface $em,
        private readonly VehicleCatalogClient $catalog,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        $key = (string) $this->settings->get('fleet.orv_api_key', '');
        $brands = $this->em->getRepository(VehicleBrand::class)->findBy([], ['name' => 'ASC']);
        $modelCounts = [];
        foreach ($this->em->getRepository(VehicleModel::class)->createQueryBuilder('m')
            ->select('m.brandId AS brandId, COUNT(m.id) AS cnt')
            ->groupBy('m.brandId')
            ->getQuery()->getArrayResult() as $row) {
            $modelCounts[(string) $row['brandId']] = (int) $row['cnt'];
        }
        $logos = [];
        $catalogCounts = [];
        foreach ($brands as $brand) {
            if ($brand instanceof VehicleBrand) {
                $logos[$brand->id()] = $this->catalog->logoUrl($brand->name());
                $catalogCounts[$brand->id()] = count($this->catalog->modelsForBrand($brand->name()));
            }
        }

        return $this->page('@fleet/settings.html.twig', [
            'pageTitle' => $this->translator->trans('fleet.settings.title'),
            'orvConfigured' => $key !== '',
            'brands' => $brands,
            'modelCounts' => $modelCounts,
            'logos' => $logos,
            'catalogCounts' => $catalogCounts,
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, 'fleet.settings');
        $key = trim((string) ($data['orv_api_key'] ?? ''));
        if ($key !== '') {
            $this->settings->set('fleet.orv_api_key', $key);
        }
        $this->forgetForm('fleet.settings');
        $this->flash('success', $this->translator->trans('fleet.flash.settings_saved'));

        return $this->redirect('admin.fleet.settings');
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
