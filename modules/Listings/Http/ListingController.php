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
use Gereq\Security\HtmlSanitizer;
use Modules\Listings\Application\BrokersProvider;
use Modules\Listings\Application\ListingsProvider;
use Modules\Listings\Application\NearLocationsProvider;
use Modules\Listings\Domain\Broker;
use Modules\Listings\Domain\Listing;
use Modules\Listings\Domain\ListingImage;
use Modules\Listings\Domain\ListingNearLocation;
use Modules\Listings\Domain\NearLocation;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ListingController extends Controller
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
        private readonly ListingsProvider $provider,
        private readonly BrokersProvider $brokers,
        private readonly NearLocationsProvider $nearLocations,
        private readonly FileManager $files,
        private readonly CapabilityRegistry $capabilities,
        private readonly HtmlSanitizer $html,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['title', 'price', 'city', 'status', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'offer_type', 'market_status', 'property_type', 'featured'],
        );

        $qb = $this->em->getRepository(Listing::class)->createQueryBuilder('l');
        $list->applySearch($qb, 'l', ['title', 'city', 'slug', 'street']);
        $status = $list->filter('status');
        if (in_array($status, Listing::STATUSES, true)) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }
        $offer = $list->filter('offer_type');
        if (in_array($offer, Listing::OFFERS, true)) {
            $qb->andWhere('l.offerType = :offer')->setParameter('offer', $offer);
        }
        $market = $list->filter('market_status');
        if (in_array($market, Listing::MARKET_STATUSES, true)) {
            $qb->andWhere('l.marketStatus = :market')->setParameter('market', $market);
        }
        $type = $list->filter('property_type');
        if (in_array($type, Listing::TYPES, true)) {
            $qb->andWhere('l.propertyType = :ptype')->setParameter('ptype', $type);
        }
        $featured = $list->filter('featured');
        if ($featured === '1' || $featured === '0') {
            $qb->andWhere('l.featured = :featured')->setParameter('featured', $featured === '1');
        }
        $qb->orderBy('l.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(l.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@listings/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('listings.title'),
            'items' => $items,
            'status' => $status,
            'offer_type' => $offer,
            'market_status' => $market,
            'property_type' => $type,
            'featured' => $featured,
            'statuses' => Listing::STATUSES,
            'offers' => Listing::OFFERS,
            'marketStatuses' => Listing::MARKET_STATUSES,
            'types' => Listing::TYPES,
        ]));
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->payload($request);
        if ($data['title'] === '') {
            $this->flash('error', $this->translator->trans('listings.error.title'));

            return $this->redirect('admin.listings.create');
        }
        $listing = new Listing($data['title'], $data['slug']);
        $this->apply($listing, $data);
        if (($data['status'] ?? '') === 'published') {
            $listing->publish();
        }
        $this->em->persist($listing);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.flash.created'));

        return $this->redirect('admin.listings.edit', ['id' => $listing->id()]);
    }

    public function show(string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $images = $this->provider->gallery($listing->id());
        $nearLinks = $this->nearLocations->forListing($listing->id());

        return $this->page('@listings/show.html.twig', [
            'pageTitle' => $listing->title(),
            'listing' => $listing,
            'images' => $images,
            'imageAssets' => $this->resolveImageAssets($images),
            'imageUrls' => $this->resolveImageUrls($images),
            'hasMedia' => $this->mediaAvailable(),
            'nearLinks' => $nearLinks,
            'nearPlaces' => $this->resolveNearPlaces($nearLinks),
            'statuses' => Listing::STATUSES,
            'marketStatuses' => Listing::MARKET_STATUSES,
            'offers' => Listing::OFFERS,
            'types' => Listing::TYPES,
            'energy' => Listing::ENERGY,
        ]);
    }

    public function edit(string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        return $this->form($listing);
    }

    public function update(Request $request, string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $data = $this->payload($request);
        if ($data['title'] === '') {
            $this->flash('error', $this->translator->trans('listings.error.title'));

            return $this->redirect('admin.listings.edit', ['id' => $id]);
        }
        $listing->setTitle($data['title']);
        $listing->setSlug($data['slug']);
        $this->apply($listing, $data);
        if (($data['status'] ?? '') === 'published') {
            $listing->publish();
        } elseif (($data['status'] ?? '') === 'archived') {
            $listing->archive();
        } else {
            $listing->unpublish();
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.flash.updated'));

        return $this->redirect('admin.listings.edit', ['id' => $listing->id()]);
    }

    public function destroy(string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }
        $this->deleteListing($listing);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.flash.deleted'));

        return $this->redirect('admin.listings.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'listings.bulk');
        $action = (string) $data['action'];
        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('listings.bulk.error.empty'));

            return $this->redirect('admin.listings.index');
        }

        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('listings.bulk.error.confirm'));

            return $this->redirect('admin.listings.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $listing = $this->provider->find($id);
            if ($listing === null) {
                continue;
            }

            switch ($action) {
                case 'publish':
                    $listing->publish();
                    break;
                case 'draft':
                    $listing->unpublish();
                    break;
                case 'archive':
                    $listing->archive();
                    break;
                case 'feature':
                    $listing->setFeatured(true);
                    break;
                case 'unfeature':
                    $listing->setFeatured(false);
                    break;
                case 'delete':
                    $this->deleteListing($listing);
                    break;
                default:
                    continue 2;
            }
            $affected++;
        }

        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.listings.index');
    }

    public function togglePublish(string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        if ($listing->status() === 'published') {
            $listing->unpublish();
            $on = false;
            if (!$this->wantsJson()) {
                $this->flash('success', $this->translator->trans('listings.flash.unpublished'));
            }
        } else {
            $listing->publish();
            $on = true;
            if (!$this->wantsJson()) {
                $this->flash('success', $this->translator->trans('listings.flash.published'));
            }
        }
        $this->em->flush();

        return $this->toggleResponse($on, 'admin.listings.index');
    }

    public function toggleFeature(string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $listing->setFeatured(!$listing->featured());
        $this->em->flush();
        if (!$this->wantsJson()) {
            $this->flash('success', $this->translator->trans(
                $listing->featured() ? 'listings.flash.featured' : 'listings.flash.unfeatured'
            ));
        }

        return $this->toggleResponse($listing->featured(), 'admin.listings.index');
    }

    private function deleteListing(Listing $listing): void
    {
        foreach ($this->provider->gallery($listing->id()) as $image) {
            $this->em->remove($image);
        }
        foreach ($this->nearLocations->forListing($listing->id()) as $link) {
            $this->em->remove($link);
        }
        $this->em->remove($listing);
    }

    public function attachNearLocation(Request $request, string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $data = $this->validate($request, 'listings.near_link.save');
        $nearId = trim((string) ($data['near_location_id'] ?? ''));
        $place = $this->nearLocations->find($nearId);
        if ($place === null) {
            $this->flash('error', $this->translator->trans('listings.near.error.not_found'));

            return $this->redirectNear($id);
        }

        $existing = $this->em->getRepository(ListingNearLocation::class)->findOneBy([
            'listingId' => $id,
            'nearLocationId' => $nearId,
        ]);
        if ($existing instanceof ListingNearLocation) {
            $this->flash('error', $this->translator->trans('listings.near_links.error.already'));

            return $this->redirectNear($id);
        }

        $link = new ListingNearLocation($id, $nearId);
        $distanceRaw = trim((string) ($data['distance_m'] ?? ''));
        $walkRaw = trim((string) ($data['walk_minutes'] ?? ''));
        $auto = $this->nearLocations->fillDistanceFromCoords(
            $listing->latitude(),
            $listing->longitude(),
            $place,
            $link,
            $distanceRaw === '' ? null : max(0, (int) $distanceRaw),
            $walkRaw === '' ? null : max(0, (int) $walkRaw),
        );
        $link->setSortOrder(count($this->nearLocations->forListing($id)));
        $this->em->persist($link);
        $this->em->flush();
        $this->forgetForm('listings.near_link.save');
        $this->flash(
            'success',
            $this->translator->trans(
                $auto && $distanceRaw === '' && $walkRaw === ''
                    ? 'listings.near_links.flash.attached_auto'
                    : 'listings.near_links.flash.attached',
            ),
        );

        return $this->redirectNear($id);
    }

    public function updateNearLocation(Request $request, string $id, string $linkId): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }
        $link = $this->nearLocations->findLink($id, $linkId);
        if ($link === null) {
            $this->abort(404, $this->translator->trans('listings.near_links.error.not_found'));
        }
        $place = $this->nearLocations->find($link->nearLocationId());
        if ($place === null) {
            $this->abort(404, $this->translator->trans('listings.near.error.not_found'));
        }

        $data = $this->validate($request, 'listings.near_link.update');
        $distanceRaw = trim((string) ($data['distance_m'] ?? ''));
        $walkRaw = trim((string) ($data['walk_minutes'] ?? ''));
        $this->nearLocations->fillDistanceFromCoords(
            $listing->latitude(),
            $listing->longitude(),
            $place,
            $link,
            $distanceRaw === '' ? null : max(0, (int) $distanceRaw),
            $walkRaw === '' ? null : max(0, (int) $walkRaw),
        );
        $this->em->flush();
        $this->forgetForm('listings.near_link.update');
        $this->flash('success', $this->translator->trans('listings.near_links.flash.updated'));

        return $this->redirectNear($id);
    }

    public function destroyNearLocation(string $id, string $linkId): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }
        $link = $this->nearLocations->findLink($id, $linkId);
        if ($link === null) {
            $this->abort(404, $this->translator->trans('listings.near_links.error.not_found'));
        }

        $this->em->remove($link);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('listings.near_links.flash.removed'));

        return $this->redirectNear($id);
    }

    public function storeImage(Request $request, string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }
        if (!$this->mediaAvailable()) {
            $this->flash('error', $this->translator->trans('listings.images.error.media_required'));

            return $this->redirectImages($id);
        }

        $data = $this->validate($request, 'listings.image.upload');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('listings.images.error.file_required'));

            return $this->redirectImages($id);
        }

        $mime = (string) ($uploaded->getMimeType() ?: '');
        if (!str_starts_with($mime, 'image/')) {
            $this->flash('error', $this->translator->trans('listings.images.error.not_image'));

            return $this->redirectImages($id);
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirectImages($id);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $listing->title() . ' — ' . $stored->originalName();
        }
        $alt = trim((string) ($data['alt'] ?? ''));
        [$width, $height] = $this->imageDimensions($uploaded, $stored);

        $asset = new \Modules\Media\Domain\MediaAsset(
            $title,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            $alt !== '' ? $alt : null,
            $width,
            $height,
        );
        $this->em->persist($asset);

        $count = (int) $this->em->getRepository(ListingImage::class)->count(['listingId' => $listing->id()]);
        $image = ListingImage::fromMedia($listing->id(), $asset->id(), $count);
        if ($alt !== '') {
            $image->setAlt($alt);
        }
        if ($count === 0) {
            $image->setPrimary(true);
        }
        $this->em->persist($image);
        $this->em->flush();

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->forgetForm('listings.image.upload');
        $this->flash('success', $this->translator->trans('listings.flash.image_uploaded'));

        return $this->redirectImages($id);
    }

    public function attachImage(Request $request, string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }
        if (!$this->mediaAvailable()) {
            $this->flash('error', $this->translator->trans('listings.images.error.media_required'));

            return $this->redirectImages($id);
        }

        $data = $this->validate($request, 'listings.image.attach');
        $mediaId = trim((string) $data['media_asset_id']);
        $asset = $this->em->find(\Modules\Media\Domain\MediaAsset::class, $mediaId);
        if (!$asset instanceof \Modules\Media\Domain\MediaAsset || !$asset->isImage()) {
            $this->flash('error', $this->translator->trans('listings.images.error.media_not_found'));

            return $this->redirectImages($id);
        }

        $existing = $this->em->getRepository(ListingImage::class)->findOneBy([
            'listingId' => $listing->id(),
            'mediaAssetId' => $asset->id(),
        ]);
        if ($existing instanceof ListingImage) {
            $this->flash('error', $this->translator->trans('listings.images.error.already_attached'));

            return $this->redirectImages($id);
        }

        $count = (int) $this->em->getRepository(ListingImage::class)->count(['listingId' => $listing->id()]);
        $image = ListingImage::fromMedia($listing->id(), $asset->id(), $count);
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt !== '') {
            $image->setAlt($alt);
        } elseif ($asset->alt()) {
            $image->setAlt($asset->alt());
        }
        if ($count === 0) {
            $image->setPrimary(true);
        }
        $this->em->persist($image);
        $this->em->flush();

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->forgetForm('listings.image.attach');
        $this->flash('success', $this->translator->trans('listings.flash.image_attached'));

        return $this->redirectImages($id);
    }

    public function storeUrlImage(Request $request, string $id): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $data = $this->validate($request, 'listings.image.url');
        $url = trim((string) ($data['external_url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->flash('error', $this->translator->trans('listings.images.error.invalid_url'));

            return $this->redirectImages($id);
        }

        $count = (int) $this->em->getRepository(ListingImage::class)->count(['listingId' => $listing->id()]);
        $image = ListingImage::fromUrl($listing->id(), $url, $count);
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt !== '') {
            $image->setAlt($alt);
        }
        if ($count === 0) {
            $image->setPrimary(true);
        }
        $this->em->persist($image);
        $this->em->flush();

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->forgetForm('listings.image.url');
        $this->flash('success', $this->translator->trans('listings.flash.image_url_added'));

        return $this->redirectImages($id);
    }

    public function setPrimaryImage(string $id, string $imageId): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $image = $this->em->find(ListingImage::class, $imageId);
        if (!$image instanceof ListingImage || $image->listingId() !== $listing->id()) {
            $this->abort(404, $this->translator->trans('listings.images.error.not_found'));
        }

        foreach ($this->em->getRepository(ListingImage::class)->findBy(['listingId' => $listing->id()]) as $row) {
            $row->setPrimary($row->id() === $image->id());
        }
        $this->em->flush();

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->flash('success', $this->translator->trans('listings.flash.image_primary'));

        return $this->redirectImages($id);
    }

    public function destroyImage(string $id, string $imageId): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $image = $this->em->find(ListingImage::class, $imageId);
        if (!$image instanceof ListingImage || $image->listingId() !== $listing->id()) {
            $this->abort(404, $this->translator->trans('listings.images.error.not_found'));
        }

        $wasPrimary = $image->isPrimary();
        $this->em->remove($image);
        $this->em->flush();

        if ($wasPrimary) {
            $next = $this->em->getRepository(ListingImage::class)->findOneBy(
                ['listingId' => $listing->id()],
                ['sortOrder' => 'ASC', 'createdAt' => 'ASC'],
            );
            if ($next instanceof ListingImage) {
                $next->setPrimary(true);
            }
        }

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->flash('success', $this->translator->trans('listings.flash.image_deleted'));

        return $this->redirectImages($id);
    }

    public function moveImage(Request $request, string $id, string $imageId): Response
    {
        $listing = $this->provider->find($id);
        if ($listing === null) {
            $this->abort(404, $this->translator->trans('listings.error.not_found'));
        }

        $image = $this->em->find(ListingImage::class, $imageId);
        if (!$image instanceof ListingImage || $image->listingId() !== $listing->id()) {
            $this->abort(404, $this->translator->trans('listings.images.error.not_found'));
        }

        $direction = strtolower(trim((string) $request->request->get('direction', '')));
        if (!in_array($direction, ['up', 'down'], true)) {
            $this->flash('error', $this->translator->trans('listings.images.error.invalid_direction'));

            return $this->redirectImages($id);
        }

        $images = $this->provider->gallery($listing->id());
        $index = null;
        foreach ($images as $i => $row) {
            if ($row->id() === $image->id()) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return $this->redirectImages($id);
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($images[$swapWith])) {
            return $this->redirectImages($id);
        }

        $currentOrder = $images[$index]->sortOrder();
        $images[$index]->setSortOrder($images[$swapWith]->sortOrder());
        $images[$swapWith]->setSortOrder($currentOrder);
        $this->em->flush();

        $this->provider->syncCover($listing);
        $this->em->flush();

        $this->flash('success', $this->translator->trans('listings.flash.image_moved'));

        return $this->redirectImages($id);
    }

    private function form(?Listing $listing): Response
    {
        $images = $listing ? $this->provider->gallery($listing->id()) : [];
        $nearLinks = $listing ? $this->nearLocations->forListing($listing->id()) : [];
        $attachedNearIds = array_map(static fn (ListingNearLocation $link): string => $link->nearLocationId(), $nearLinks);

        return $this->page('@listings/form.html.twig', [
            'pageTitle' => $listing
                ? $this->translator->trans('listings.edit')
                : $this->translator->trans('listings.create'),
            'listing' => $listing,
            'statuses' => Listing::STATUSES,
            'marketStatuses' => Listing::MARKET_STATUSES,
            'offers' => Listing::OFFERS,
            'types' => Listing::TYPES,
            'energy' => Listing::ENERGY,
            'images' => $images,
            'imageAssets' => $this->resolveImageAssets($images),
            'imageUrls' => $this->resolveImageUrls($images),
            'libraryImages' => $this->libraryImageOptions($images),
            'hasMedia' => $this->mediaAvailable(),
            'brokerOptions' => $this->brokerOptions(),
            'nearLinks' => $nearLinks,
            'nearPlaces' => $this->resolveNearPlaces($nearLinks),
            'nearOptions' => $this->nearLocations->options(true, $attachedNearIds),
        ]);
    }

    /** @return array{value: string, label: string}[] */
    private function brokerOptions(): array
    {
        return $this->brokers->options(false);
    }

    /**
     * @param list<ListingNearLocation> $links
     * @return array<string, NearLocation>
     */
    private function resolveNearPlaces(array $links): array
    {
        $map = [];
        foreach ($links as $link) {
            $place = $this->nearLocations->find($link->nearLocationId());
            if ($place instanceof NearLocation) {
                $map[$link->id()] = $place;
            }
        }

        return $map;
    }

    private function redirectNear(string $id): Response
    {
        return $this->redirectTo(
            $this->router->url('admin.listings.edit', ['id' => $id], $this->request()) . '#nearby'
        );
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(\Modules\Media\Domain\MediaAsset::class);
    }

    /**
     * @param list<ListingImage> $images
     * @return array<string, \Modules\Media\Domain\MediaAsset>
     */
    private function resolveImageAssets(array $images): array
    {
        if (!$this->mediaAvailable() || $images === []) {
            return [];
        }

        $map = [];
        foreach ($images as $image) {
            if ($image->mediaAssetId() === null) {
                continue;
            }
            $asset = $this->em->find(\Modules\Media\Domain\MediaAsset::class, $image->mediaAssetId());
            if ($asset instanceof \Modules\Media\Domain\MediaAsset) {
                $map[$image->id()] = $asset;
            }
        }

        return $map;
    }

    /**
     * @param list<ListingImage> $images
     * @return array<string, string>
     */
    private function resolveImageUrls(array $images): array
    {
        $map = [];
        foreach ($images as $image) {
            $url = $this->provider->imageUrl($image);
            if ($url !== null && $url !== '') {
                $map[$image->id()] = $url;
            }
        }

        return $map;
    }

    /**
     * @param list<ListingImage> $attached
     * @return list<array{value: string, label: string}>
     */
    private function libraryImageOptions(array $attached): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }

        $attachedIds = [];
        foreach ($attached as $image) {
            if ($image->mediaAssetId() !== null) {
                $attachedIds[$image->mediaAssetId()] = true;
            }
        }

        $provider = $this->capabilities->get('media');
        if (!method_exists($provider, 'images')) {
            return [];
        }

        /** @var list<\Modules\Media\Domain\MediaAsset> $assets */
        $assets = $provider->images(80);
        $options = [];
        foreach ($assets as $asset) {
            if (isset($attachedIds[$asset->id()])) {
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

    private function redirectImages(string $id): Response
    {
        return $this->redirectTo(
            $this->router->url('admin.listings.edit', ['id' => $id], $this->request()) . '#images'
        );
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        if ($slug === '') {
            $slug = $this->slugify($title !== '' ? $title : 'listing');
        } else {
            $slug = $this->slugify($slug);
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'status' => (string) $request->request->get('status', 'draft'),
            'market_status' => (string) $request->request->get('market_status', 'available'),
            'offer_type' => (string) $request->request->get('offer_type', 'sale'),
            'property_type' => (string) $request->request->get('property_type', 'apartment'),
            'featured' => $request->request->getBoolean('featured'),
            'price' => (string) $request->request->get('price', '0'),
            'currency' => (string) $request->request->get('currency', 'CZK'),
            'area_m2' => $request->request->get('area_m2'),
            'rooms' => $request->request->get('rooms'),
            'floor' => $request->request->get('floor'),
            'floors' => $request->request->get('floors'),
            'city' => trim((string) $request->request->get('city', '')),
            'city_part' => trim((string) $request->request->get('city_part', '')),
            'street' => trim((string) $request->request->get('street', '')),
            'zip' => trim((string) $request->request->get('zip', '')),
            'address' => trim((string) $request->request->get('address', '')),
            'latitude' => $request->request->get('latitude'),
            'longitude' => $request->request->get('longitude'),
            'description' => $this->html->sanitize((string) $request->request->get('description', '')),
            'balcony' => $request->request->getBoolean('balcony'),
            'terrace' => $request->request->getBoolean('terrace'),
            'cellar' => $request->request->getBoolean('cellar'),
            'garage' => $request->request->getBoolean('garage'),
            'elevator' => $request->request->getBoolean('elevator'),
            'parking_lots' => $request->request->get('parking_lots'),
            'energy_rating' => trim((string) $request->request->get('energy_rating', '')),
            'broker_id' => trim((string) $request->request->get('broker_id', '')),
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(Listing $listing, array $data): void
    {
        $offer = (string) $data['offer_type'];
        if (in_array($offer, Listing::OFFERS, true)) {
            $listing->setOfferType($offer);
        }
        $type = (string) $data['property_type'];
        if (in_array($type, Listing::TYPES, true)) {
            $listing->setPropertyType($type);
        }
        $listing->setMarketStatus((string) $data['market_status']);
        $listing->setFeatured((bool) $data['featured']);
        $listing->setPrice(number_format((float) $data['price'], 2, '.', ''));
        $listing->setCurrency((string) $data['currency']);
        $listing->setAreaM2($this->nullableFloat($data['area_m2']));
        $listing->setRooms($this->nullableInt($data['rooms']));
        $listing->setFloor($this->nullableInt($data['floor']));
        $listing->setFloors($this->nullableInt($data['floors']));
        $listing->setCity((string) $data['city']);
        $listing->setCityPart($data['city_part'] !== '' ? (string) $data['city_part'] : null);
        $listing->setStreet($data['street'] !== '' ? (string) $data['street'] : null);
        $listing->setZip($data['zip'] !== '' ? (string) $data['zip'] : null);
        $listing->setAddress($data['address'] !== '' ? (string) $data['address'] : null);
        $listing->setLatitude($this->nullableFloat($data['latitude']));
        $listing->setLongitude($this->nullableFloat($data['longitude']));
        $listing->setDescription($data['description'] !== '' ? (string) $data['description'] : null);
        $listing->setBalcony((bool) $data['balcony']);
        $listing->setTerrace((bool) $data['terrace']);
        $listing->setCellar((bool) $data['cellar']);
        $listing->setGarage((bool) $data['garage']);
        $listing->setElevator((bool) $data['elevator']);
        $listing->setParkingLots($this->nullableInt($data['parking_lots']));
        $listing->setEnergyRating($data['energy_rating'] !== '' ? (string) $data['energy_rating'] : null);
        $this->applyBroker($listing, (string) ($data['broker_id'] ?? ''));
    }

    private function applyBroker(Listing $listing, string $brokerId): void
    {
        if ($brokerId === '') {
            $listing->setBrokerId(null);
            $listing->setBrokerName(null);
            $listing->setBrokerEmail(null);
            $listing->setBrokerPhone(null);
            $listing->setBrokerPosition(null);
            $listing->setBrokerPhotoUrl(null);

            return;
        }

        $broker = $this->brokers->find($brokerId);
        if (!$broker instanceof Broker) {
            return;
        }

        $listing->setBrokerId($broker->id());
        $listing->setBrokerName($broker->fullName());
        $listing->setBrokerEmail($broker->email());
        $listing->setBrokerPhone($broker->phone());
        $listing->setBrokerPosition($broker->position());
        $listing->setBrokerPhotoUrl($broker->photoUrl());
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'listing';
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
