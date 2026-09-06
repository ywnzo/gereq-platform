<?php

declare(strict_types=1);

namespace Modules\Fleet\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Fleet\Application\VehicleCatalogClient;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleModel;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly VehicleCatalogClient $catalog,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        return $this->redirect('admin.fleet.settings');
    }

    public function show(string $id): Response
    {
        $brand = $this->em->find(VehicleBrand::class, $id);
        if (!$brand instanceof VehicleBrand) {
            $this->abort(404, $this->translator->trans('fleet.error.brand_not_found'));
        }
        $models = $this->em->getRepository(VehicleModel::class)->findBy(['brandId' => $id], ['name' => 'ASC']);
        $catalogMeta = [];
        foreach ($this->catalog->modelsForBrand($brand->name()) as $row) {
            $catalogMeta[mb_strtolower($row['name'])] = $row;
        }

        return $this->page('@fleet/brands/show.html.twig', [
            'pageTitle' => $brand->name(),
            'brand' => $brand,
            'models' => $models,
            'logo' => $this->catalog->logoUrl($brand->name()),
            'catalogCount' => count($catalogMeta),
            'catalogMeta' => $catalogMeta,
        ]);
    }

    public function catalogSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $items = $this->catalog->searchMakes($q, 24);

        return new JsonResponse(['data' => $items]);
    }

    public function storeBrand(Request $request): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        $importModels = $request->request->getBoolean('import_models');
        if ($name === '') {
            $this->flash('error', $this->translator->trans('fleet.error.brand_required'));

            return $this->redirect('admin.fleet.settings');
        }
        $canonical = $this->catalog->resolveCanonicalName($name);
        if ($canonical !== null) {
            $name = $canonical;
        }
        if ($this->em->getRepository(VehicleBrand::class)->findOneBy(['name' => $name]) instanceof VehicleBrand) {
            $this->flash('error', $this->translator->trans('fleet.error.brand_exists'));

            return $this->redirect('admin.fleet.settings');
        }
        $brand = new VehicleBrand($name);
        $this->em->persist($brand);
        $added = 0;
        if ($importModels) {
            $added = $this->importModelsForBrand($brand);
        }
        $this->em->flush();
        $this->flash(
            'success',
            $added > 0
                ? $this->translator->trans('fleet.flash.brand_imported', ['%count%' => (string) $added])
                : $this->translator->trans('fleet.flash.brand_saved'),
        );

        return $this->redirect('admin.fleet.brands.show', ['id' => $brand->id()]);
    }

    public function storeModel(Request $request): Response
    {
        $brandId = trim((string) $request->request->get('brand_id', ''));
        $name = trim((string) $request->request->get('name', ''));
        if (!$this->em->find(VehicleBrand::class, $brandId) instanceof VehicleBrand || $name === '') {
            $this->flash('error', $this->translator->trans('fleet.error.model_invalid'));

            return $this->redirect('admin.fleet.settings');
        }
        if ($this->em->getRepository(VehicleModel::class)->findOneBy(['brandId' => $brandId, 'name' => $name]) instanceof VehicleModel) {
            $this->flash('error', $this->translator->trans('fleet.error.model_exists'));

            return $this->redirect('admin.fleet.brands.show', ['id' => $brandId]);
        }
        $this->em->persist(new VehicleModel($brandId, $name));
        $this->em->flush();
        $this->flash('success', $this->translator->trans('fleet.flash.model_saved'));

        return $this->redirect('admin.fleet.brands.show', ['id' => $brandId]);
    }

    public function importModels(string $id): Response
    {
        $brand = $this->em->find(VehicleBrand::class, $id);
        if (!$brand instanceof VehicleBrand) {
            $this->abort(404, $this->translator->trans('fleet.error.brand_not_found'));
        }
        $added = $this->importModelsForBrand($brand);
        $this->em->flush();
        if ($added === 0) {
            $this->flash('info', $this->translator->trans('fleet.flash.models_none'));
        } else {
            $this->flash('success', $this->translator->trans('fleet.flash.models_imported', ['%count%' => (string) $added]));
        }

        return $this->redirect('admin.fleet.brands.show', ['id' => $id]);
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

        return $this->redirect('admin.fleet.settings');
    }

    public function destroyModel(string $id): Response
    {
        $model = $this->em->find(VehicleModel::class, $id);
        $brandId = $model instanceof VehicleModel ? $model->brandId() : null;
        if ($model instanceof VehicleModel) {
            $this->em->remove($model);
            $this->em->flush();
        }
        $this->flash('success', $this->translator->trans('fleet.flash.model_deleted'));

        return $brandId !== null
            ? $this->redirect('admin.fleet.brands.show', ['id' => $brandId])
            : $this->redirect('admin.fleet.settings');
    }

    private function importModelsForBrand(VehicleBrand $brand): int
    {
        $existing = [];
        foreach ($this->em->getRepository(VehicleModel::class)->findBy(['brandId' => $brand->id()]) as $model) {
            if ($model instanceof VehicleModel) {
                $existing[mb_strtolower($model->name())] = true;
            }
        }
        $added = 0;
        foreach ($this->catalog->modelsForBrand($brand->name()) as $row) {
            $key = mb_strtolower($row['name']);
            if (isset($existing[$key])) {
                continue;
            }
            $this->em->persist(new VehicleModel($brand->id(), $row['name']));
            $existing[$key] = true;
            ++$added;
        }

        return $added;
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
