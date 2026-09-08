<?php

declare(strict_types=1);

namespace Modules\Listings\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Publish\Adapter\CityPublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Modules\Listings\Application\CitiesProvider;
use Modules\Listings\Domain\City;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CityController extends Controller
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
        private readonly CitiesProvider $provider,
        private readonly FileManager $files,
        private readonly CapabilityRegistry $capabilities,
        private readonly ProductionFreeze $freeze,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezeCity(City $city): void
    {
        if (!$city->active() && !$this->freeze->hasFreeze('listings.cities', $city->id())) {
            return;
        }
        $this->freeze->freezeIfNeeded(
            'listings.cities',
            'city',
            $city->id(),
            fn (): array => CityPublishAdapter::capture($city),
            $city->name(),
            $this->auth->user()?->id(),
        );
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'sortOrder', 'createdAt'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: ['active'],
        );

        $qb = $this->em->getRepository(City::class)->createQueryBuilder('c');
        $list->applySearch($qb, 'c', ['name']);
        $active = $list->filter('active');
        if ($active === '1') {
            $qb->andWhere('c.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('c.active = false');
        }
        $deleteKeys = $this->freeze->pendingDeleteKeys('listings.cities');
        if ($deleteKeys !== []) {
            $qb->andWhere('c.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }
        $qb->orderBy('c.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@listings/cities/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('listings.cities.title'),
            'items' => $items,
            'active' => $active,
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $image = $this->resolveImageUrl($request, $data, null);
        if ($image instanceof Response) {
            return $image;
        }

        $city = new City($data['name']);
        $this->apply($city, $data, $image);
        $this->em->persist($city);
        $this->em->flush();
        if ($city->active()) {
            $this->freeze->stageCreate(
                'listings.cities',
                'city',
                $city->id(),
                $city->name(),
                $this->auth->user()?->id(),
            );
        }
        $this->forgetForm('listings.cities.save');
        $this->flash(
            'success',
            $city->active()
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('listings.cities.flash.created'),
        );

        return $this->redirect('admin.listings.cities.show', ['id' => $city->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.cities', $id)) {
            $this->abort(404, $this->translator->trans('listings.cities.error.not_found'));
        }

        return $this->page('@listings/cities/show.html.twig', [
            'pageTitle' => $item->name(),
            'item' => $item,
            'listings' => $this->provider->listingsForCity($item),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.cities', $id)) {
            $this->abort(404, $this->translator->trans('listings.cities.error.not_found'));
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.cities', $id)) {
            $this->abort(404, $this->translator->trans('listings.cities.error.not_found'));
        }

        $data = $this->validated($request);
        $image = $this->resolveImageUrl($request, $data, $item->imageUrl());
        if ($image instanceof Response) {
            return $image;
        }

        $wasActive = $item->active();
        $this->freezeCity($item);
        $item->setName($data['name']);
        $this->apply($item, $data, $image);
        $this->em->flush();
        if ($item->active() && !$wasActive && !$this->freeze->hasFreeze('listings.cities', $item->id())) {
            $this->freeze->stageCreate(
                'listings.cities',
                'city',
                $item->id(),
                $item->name(),
                $this->auth->user()?->id(),
            );
        }
        $this->forgetForm('listings.cities.save');
        $this->flash(
            'success',
            ($wasActive || $item->active())
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('listings.cities.flash.saved'),
        );

        return $this->redirect('admin.listings.cities.show', ['id' => $item->id()]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.cities', $id)) {
            $this->abort(404, $this->translator->trans('listings.cities.error.not_found'));
        }

        if ($item->active() || $this->freeze->hasFreeze('listings.cities', $id)) {
            $this->freeze->stageDelete(
                'listings.cities',
                'city',
                $id,
                fn (): array => CityPublishAdapter::capture($item),
                $item->name(),
                $this->auth->user()?->id(),
            );
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
            $this->em->remove($item);
            $this->em->flush();
            $this->flash('success', $this->translator->trans('listings.cities.flash.deleted'));
        }

        return $this->redirect('admin.listings.cities.index');
    }

    public function toggleActive(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null || $this->freeze->isPendingDelete('listings.cities', $id)) {
            $this->abort(404, $this->translator->trans('listings.cities.error.not_found'));
        }

        $this->freezeCity($item);
        if (!$item->active()) {
            $this->freeze->freezeIfNeeded(
                'listings.cities',
                'city',
                $item->id(),
                fn (): array => CityPublishAdapter::capture($item),
                $item->name(),
                $this->auth->user()?->id(),
            );
        }
        $item->setActive(!$item->active());
        $this->em->flush();
        $message = $this->translator->trans('publish.flash.staged');
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse($item->active(), 'admin.listings.cities.index', [], $message);
    }

    private function formPage(?City $item): Response
    {
        return $this->page('@listings/cities/form.html.twig', [
            'pageTitle' => $item
                ? $this->translator->trans('listings.cities.edit')
                : $this->translator->trans('listings.cities.new'),
            'item' => $item,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryImages(),
        ]);
    }

    /**
     * @return array{name: string, image_url: ?string, media_asset_id: ?string, sort_order: int, active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'listings.cities.save');
        $image = trim((string) ($data['image_url'] ?? ''));
        $mediaId = trim((string) ($data['media_asset_id'] ?? ''));
        $sort = trim((string) ($data['sort_order'] ?? '0'));
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'name' => trim((string) $data['name']),
            'image_url' => $image !== '' ? $image : null,
            'media_asset_id' => $mediaId !== '' ? $mediaId : null,
            'sort_order' => $sort === '' ? 0 : (int) $sort,
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /**
     * @param array{image_url: ?string, media_asset_id: ?string, sort_order: int, active: bool} $data
     */
    private function apply(City $city, array $data, ?string $imageUrl): void
    {
        $city->setImageUrl($imageUrl);
        $city->setSortOrder($data['sort_order']);
        $city->setActive($data['active']);
    }

    /**
     * @param array{image_url: ?string, media_asset_id: ?string} $data
     */
    private function resolveImageUrl(Request $request, array $data, ?string $current): Response|string|null
    {
        if ($request->request->get('clear_image') === '1' || $request->request->get('clear_image') === 'on') {
            return null;
        }

        $uploaded = $request->files->get('file');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('listings.images.error.media_required'));

                return $this->redirectBack('admin.listings.cities.index');
            }

            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('listings.cities.error.not_image'));

                return $this->redirectBack('admin.listings.cities.index');
            }

            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('listings.cities.error.file_upload'));

                return $this->redirectBack('admin.listings.cities.index');
            }

            $title = trim((string) $request->request->get('name', '')) ?: $stored->originalName();
            [$width, $height] = $this->imageDimensions($uploaded, $stored);
            $asset = new MediaAsset(
                $title,
                $stored->path(),
                $stored->mime(),
                $stored->size(),
                $this->auth->user()?->id(),
                null,
                $width,
                $height,
            );
            $this->em->persist($asset);
            $this->em->flush();

            return $this->publicMediaUrl($asset->id());
        }

        if ($data['media_asset_id'] !== null) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('listings.images.error.media_required'));

                return $this->redirectBack('admin.listings.cities.index');
            }

            $asset = $this->em->find(MediaAsset::class, $data['media_asset_id']);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('listings.cities.error.media_not_found'));

                return $this->redirectBack('admin.listings.cities.index');
            }

            return $this->publicMediaUrl($asset->id());
        }

        if ($data['image_url'] !== null) {
            return $data['image_url'];
        }

        return $current;
    }

    private function publicMediaUrl(string $assetId): string
    {
        return '/media/' . $assetId . '/file';
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /** @return list<array{value: string, label: string}> */
    private function libraryImages(): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }

        $provider = $this->capabilities->get('media');
        if (!is_object($provider) || !method_exists($provider, 'images')) {
            return [];
        }

        $options = [];
        foreach ($provider->images(100) as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title() . ' (' . $asset->mime() . ')',
            ];
        }

        return $options;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(UploadedFile $uploaded, \Gereq\File\StoredFile $stored): array
    {
        if (!str_starts_with($stored->mime(), 'image/')) {
            return [null, null];
        }

        $meta = $stored->metadata();
        if (is_array($meta) && isset($meta['width'], $meta['height'])) {
            return [(int) $meta['width'], (int) $meta['height']];
        }

        $info = @getimagesize($uploaded->getPathname());
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
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
