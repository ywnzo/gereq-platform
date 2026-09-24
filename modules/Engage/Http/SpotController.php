<?php

declare(strict_types=1);

namespace Modules\Engage\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Engage\Application\EngageProvider;
use Modules\Engage\Domain\Spot;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SpotController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly EngageProvider $engage,
        private readonly CapabilityRegistry $capabilities,
        private readonly FileManager $files,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['title', 'placementKey', 'sortOrder', 'updatedAt'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: ['active', 'source'],
        );
        $active = $list->filter('active');
        $source = $list->filter('source');

        $qb = $this->em->getRepository(Spot::class)->createQueryBuilder('s');
        $list->applySearch($qb, 's', ['title', 'placementKey', 'body']);
        if ($active === '1') {
            $qb->andWhere('s.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('s.active = false');
        }
        if (in_array($source, Spot::SOURCES, true)) {
            $qb->andWhere('s.source = :src')->setParameter('src', $source);
        }
        $qb->orderBy('s.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@engage/spots/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('engage.spots.title'),
            'items' => $items,
            'active' => $active,
            'source' => $source,
            'sources' => Spot::SOURCES,
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->engage->findSpotByKey($data['placement_key']) instanceof Spot) {
            $this->abort(422, $this->translator->trans('engage.error.placement_taken'));
        }

        $image = $this->resolveImage($request, $data['image_url'], null, 'admin.engage.spots.create');
        if ($image instanceof Response) {
            return $image;
        }

        $spot = new Spot($data['placement_key'], $data['title']);
        $this->apply($spot, $data, $image);
        $this->em->persist($spot);
        $this->em->flush();

        $payload = $this->engage->serializeSpot($spot);
        $this->events->emit('engage.spot.created', ['type' => 'engage_spot', 'id' => $spot->id()], $payload);
        $this->auditor->log('engage.spot.created', 'engage_spot', $spot->id());
        $this->forgetForm('engage.spot.save');
        $this->flash('success', $this->translator->trans('engage.flash.spot_created'));

        return $this->redirect('admin.engage.spots.edit', ['id' => $spot->id()]);
    }

    public function edit(string $id): Response
    {
        $item = $this->engage->findSpot($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.spot_not_found'));
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->engage->findSpot($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.spot_not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->engage->findSpotByKey($data['placement_key']);
        if ($dup instanceof Spot && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('engage.error.placement_taken'));
        }

        $image = $this->resolveImage($request, $data['image_url'], $item->imageUrl(), 'admin.engage.spots.edit', ['id' => $id]);
        if ($image instanceof Response) {
            return $image;
        }

        $this->apply($item, $data, $image);
        $this->em->flush();

        $payload = $this->engage->serializeSpot($item);
        $this->events->emit('engage.spot.updated', ['type' => 'engage_spot', 'id' => $item->id()], $payload);
        $this->auditor->log('engage.spot.updated', 'engage_spot', $id);
        $this->forgetForm('engage.spot.save');
        $this->flash('success', $this->translator->trans('engage.flash.spot_saved'));

        return $this->redirect('admin.engage.spots.edit', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->engage->findSpot($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.spot_not_found'));
        }

        $payload = $this->engage->serializeSpot($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('engage.spot.deleted', ['type' => 'engage_spot', 'id' => $id], $payload);
        $this->auditor->log('engage.spot.deleted', 'engage_spot', $id);
        $this->flash('success', $this->translator->trans('engage.flash.spot_deleted'));

        return $this->redirect('admin.engage.spots.index');
    }

    private function formPage(?Spot $item): Response
    {
        return $this->page('@engage/spots/form.html.twig', [
            'pageTitle' => $item
                ? $this->translator->trans('engage.spots.edit')
                : $this->translator->trans('engage.spots.new'),
            'item' => $item,
            'cmsPages' => $this->engage->cmsPages(),
            'campaigns' => $this->engage->campaignOptions(),
            'sources' => Spot::SOURCES,
            'placements' => Spot::PLACEMENTS,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryImages(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'engage.spot.save');
        $sort = trim((string) ($data['sort_order'] ?? '0'));

        return [
            'placement_key' => Spot::normalizeKey((string) $data['placement_key']),
            'title' => trim((string) $data['title']),
            'body' => $this->nullable($data['body'] ?? null),
            'source' => (string) ($data['source'] ?? 'custom'),
            'ref_id' => $this->nullable($data['ref_id'] ?? null),
            'cms_page_id' => $this->nullable($data['cms_page_id'] ?? null),
            'campaign_id' => $this->nullable($data['campaign_id'] ?? null),
            'cta_label' => $this->nullable($data['cta_label'] ?? null),
            'cta_url' => $this->nullable($data['cta_url'] ?? null),
            'image_url' => $this->nullable($data['image_url'] ?? null),
            'sort_order' => $sort === '' ? 0 : (int) $sort,
            'active' => $this->boolish($data['active'] ?? $request->request->get('active'), true),
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(Spot $spot, array $data, ?string $imageUrl): void
    {
        $spot->setPlacementKey($data['placement_key']);
        $spot->setTitle($data['title']);
        $spot->setBody($data['body']);
        $spot->setSource($data['source']);
        $spot->setRefId($data['ref_id']);
        $spot->setCmsPageId($data['cms_page_id']);
        $spot->setCampaignId($data['campaign_id']);
        $spot->setCtaLabel($data['cta_label']);
        $spot->setCtaUrl($data['cta_url']);
        $spot->setImageUrl($imageUrl);
        $spot->setSortOrder((int) $data['sort_order']);
        $spot->setActive((bool) $data['active']);
    }

    /**
     * @param array<string, string> $redirectParams
     */
    private function resolveImage(
        Request $request,
        ?string $postedUrl,
        ?string $current,
        string $redirectRoute,
        array $redirectParams = [],
    ): Response|string|null {
        if ($request->request->get('clear_image') === '1' || $request->request->get('clear_image') === 'on') {
            return null;
        }

        $uploaded = $request->files->get('image_file');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('engage.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('engage.error.not_image'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('engage.error.file_upload'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $asset = new MediaAsset(
                $stored->originalName(),
                $stored->path(),
                $stored->mime(),
                $stored->size(),
                $this->auth->user()?->id(),
            );
            $this->em->persist($asset);
            $this->em->flush();

            return '/media/' . $asset->id() . '/file';
        }

        $mediaId = trim((string) $request->request->get('image_media_asset_id', ''));
        if ($mediaId !== '') {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('engage.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('engage.error.media_not_found'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            return '/media/' . $asset->id() . '/file';
        }

        return $postedUrl ?? $current;
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
            $options[] = ['value' => $asset->id(), 'label' => $asset->title() . ' (' . $asset->mime() . ')'];
        }

        return $options;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function boolish(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
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
