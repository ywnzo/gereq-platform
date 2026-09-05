<?php

declare(strict_types=1);

namespace Modules\Fleet\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BrandController extends Controller
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $brands = $this->em->getRepository(VehicleBrand::class)->findBy([], ['name' => 'ASC']);
        $models = $this->em->getRepository(VehicleModel::class)->findBy([], ['name' => 'ASC']);
        $byBrand = [];
        foreach ($models as $model) {
            if ($model instanceof VehicleModel) {
                $byBrand[$model->brandId()][] = $model;
            }
        }

        return $this->page('@fleet/brands/index.html.twig', [
            'pageTitle' => $this->translator->trans('fleet.brands.title'),
            'brands' => $brands,
            'modelsByBrand' => $byBrand,
        ]);
    }

    public function storeBrand(Request $request): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->flash('error', $this->translator->trans('fleet.error.brand_required'));

            return $this->redirect('admin.fleet.brands');
        }
        if ($this->em->getRepository(VehicleBrand::class)->findOneBy(['name' => $name]) instanceof VehicleBrand) {
            $this->flash('error', $this->translator->trans('fleet.error.brand_exists'));

            return $this->redirect('admin.fleet.brands');
        }
        $this->em->persist(new VehicleBrand($name));
        $this->em->flush();
        $this->flash('success', $this->translator->trans('fleet.flash.brand_saved'));

        return $this->redirect('admin.fleet.brands');
    }

    public function storeModel(Request $request): Response
    {
        $brandId = trim((string) $request->request->get('brand_id', ''));
        $name = trim((string) $request->request->get('name', ''));
        if (!$this->em->find(VehicleBrand::class, $brandId) instanceof VehicleBrand || $name === '') {
            $this->flash('error', $this->translator->trans('fleet.error.model_invalid'));

            return $this->redirect('admin.fleet.brands');
        }
        $this->em->persist(new VehicleModel($brandId, $name));
        $this->em->flush();
        $this->flash('success', $this->translator->trans('fleet.flash.model_saved'));

        return $this->redirect('admin.fleet.brands');
    }

    public function destroyBrand(string $id): Response
    {
        $brand = $this->em->find(VehicleBrand::class, $id);
        if ($brand instanceof VehicleBrand) {
            foreach ($this->em->getRepository(VehicleModel::class)->findBy(['brandId' => $id]) as $model) {
                $this->em->remove($model);
            }
            $this->em->remove($brand);
            $this->em->flush();
        }
        $this->flash('success', $this->translator->trans('fleet.flash.brand_deleted'));

        return $this->redirect('admin.fleet.brands');
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
