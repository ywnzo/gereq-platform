<?php

declare(strict_types=1);

namespace Modules\Events\Http;

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
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Media\Domain\MediaLink;
use Gereq\Module\CapabilityRegistry;
use Gereq\Security\HtmlSanitizer;
use Modules\Events\Application\EventCatalog;
use Modules\Events\Application\EventCategories;
use Modules\Events\Application\EventGallery;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Application\EventSubmitterNotifier;
use Modules\Events\Application\EventTrust;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventAdminController extends Controller
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
        private readonly EventCatalog $catalog,
        private readonly EventCategories $categories,
        private readonly FileManager $files,
        private readonly CapabilityRegistry $capabilities,
        private readonly HtmlSanitizer $html,
        private readonly EventSubmitterNotifier $submitterNotifier,
        private readonly EventTrust $trust,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['startsAt', 'title', 'status', 'createdAt', 'region', 'category', 'place'],
            defaultSort: 'startsAt',
            defaultDir: 'desc',
            perPage: 50,
            filterKeys: ['status', 'region', 'spotlight'],
        );
        $result = $this->catalog->search(
            $list->search,
            [
                'status' => (string) $list->filter('status'),
                'region' => (string) $list->filter('region'),
                'spotlight' => (string) $list->filter('spotlight'),
            ],
            $list->page,
            $list->perPage,
            $list->sort,
            $list->direction(),
            false,
        );

        $items = $result['items'];
        $ids = array_map(static fn (CatalogEvent $event): string => $event->id(), $items);
        $submitterIds = array_map(static fn (CatalogEvent $event): ?string => $event->submittedByCustomerId(), $items);

        return $this->page('@events/index.html.twig', array_merge($list->viewData($result['total']), [
            'pageTitle' => $this->translator->trans('events.title'),
            'items' => $items,
            'cityGroups' => CatalogEvent::groupByCity($items),
            'submitters' => $this->catalog->customersByIds($submitterIds),
            'favoriteCounts' => $this->catalog->favoriteCounts($ids),
            'interestCounts' => $this->catalog->interestCounts($ids),
            'status' => $list->filter('status'),
            'region' => $list->filter('region'),
            'spotlight' => $list->filter('spotlight'),
            'statuses' => CatalogEvent::STATUSES,
            'regions' => CatalogEvent::REGIONS,
            'categoryChoices' => $this->categories->assignableChoices(),
            'hideDatesSlugs' => $this->categories->hideDatesSlugs(),
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->preparePayload($this->validate($request, 'events.save'), $request);
        $resolved = $this->resolveMedia($request, $data, null);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $data['media_asset_id'] = $resolved;
        $event = $this->catalog->createAdmin($data);
        $this->syncCoverLink($event);
        $this->events->emit('events.created', ['type' => 'event', 'id' => $event->id()], $this->catalog->serialize($event));
        $this->auditor->log('events.created', 'event', $event->id());
        $this->forgetForm('events.save');
        $this->flash('success', $event->status() === 'published'
            ? $this->translator->trans('events.flash.published')
            : $this->translator->trans('events.flash.created'));

        return $this->redirect('admin.events.show', ['id' => $event->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }

        return $this->page('@events/show.html.twig', [
            'pageTitle' => $item->title(),
            'item' => $item,
            'imageUrl' => EventMediaLibrary::publicUrl($item->mediaAssetId()),
            'galleryItems' => EventGallery::publicItems($item->gallery()),
            'submitter' => $this->catalog->customerById($item->submittedByCustomerId()),
            'submitterTrusted' => $this->trust->isTrusted($item->submittedByCustomerId()),
            'savers' => $this->catalog->saversForEvent($item->id()),
            'interests' => $this->catalog->interestsForEvent($item->id()),
            'hideDates' => $this->categories->hidesDates($item->category()),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $data = $this->preparePayload($this->validate($request, 'events.save'), $request);
        $resolved = $this->resolveMedia($request, $data, $item->mediaAssetId());
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $data['media_asset_id'] = $resolved;
        $this->catalog->saveAdmin($item, $data);
        $this->syncCoverLink($item);
        $this->events->emit('events.updated', ['type' => 'event', 'id' => $item->id()], $this->catalog->serialize($item));
        $this->auditor->log('events.updated', 'event', $item->id());
        $this->forgetForm('events.save');
        $this->flash('success', $item->status() === 'published'
            ? $this->translator->trans('events.flash.published')
            : $this->translator->trans('events.flash.saved'));

        return $this->redirect('admin.events.show', ['id' => $item->id()]);
    }

    public function publish(string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $item->publish();
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->submitterNotifier->notifyPublished($item);
        $this->events->emit('events.published', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
        $this->auditor->log('events.published', 'event', $id);
        $this->flash('success', $this->translator->trans('events.flash.published'));

        return $this->redirectBack('admin.events.show', ['id' => $id]);
    }

    public function archive(string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $item->archive();
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->events->emit('events.archived', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
        $this->auditor->log('events.archived', 'event', $id);
        $this->flash('success', $this->translator->trans('events.flash.archived'));

        return $this->redirectBack('admin.events.show', ['id' => $id]);
    }

    public function reject(Request $request, string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $data = $this->validate($request, 'events.reject');
        $reason = (string) $this->html->sanitizeNullable((string) ($data['reason'] ?? ''));
        $plain = trim(html_entity_decode(strip_tags($reason), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($plain) < 3) {
            $this->flash('error', $this->translator->trans('events.reject.error.empty'));

            return $this->redirectBack('admin.events.show', ['id' => $id]);
        }
        $item->reject($reason);
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->submitterNotifier->notifyRejected($item);
        $this->events->emit('events.rejected', ['type' => 'event', 'id' => $id], [
            'title' => $item->title(),
            'reason' => $reason,
            'customer_id' => $item->submittedByCustomerId(),
        ]);
        $this->auditor->log('events.rejected', 'event', $id);
        $this->flash('success', $this->translator->trans('events.flash.rejected'));

        return $this->redirectBack('admin.events.show', ['id' => $id]);
    }

    public function spotlight(Request $request, string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $data = $this->validate($request, 'events.spotlight');
        $spotlight = (string) ($data['spotlight'] ?? 'none');
        $this->catalog->assignSpotlight($item, $spotlight);
        $this->auditor->log('events.spotlight', 'event', $id);
        $this->flash('success', $this->translator->trans(
            $spotlight === 'none' ? 'events.flash.spotlight_cleared' : 'events.flash.spotlight',
            ['%region%' => $item->region()],
        ));

        return $this->redirectBack('admin.events.show', ['id' => $id]);
    }

    public function trust(string $customerId): Response
    {
        $this->trust->grant($customerId);
        $this->auditor->log('events.organizer.trusted', 'customer', $customerId);
        $this->flash('success', $this->translator->trans('events.trust.flash.granted'));

        return $this->redirectBack('admin.customers.show', ['id' => $customerId]);
    }

    public function untrust(string $customerId): Response
    {
        $this->trust->revoke($customerId);
        $this->auditor->log('events.organizer.untrusted', 'customer', $customerId);
        $this->flash('success', $this->translator->trans('events.trust.flash.revoked'));

        return $this->redirectBack('admin.customers.show', ['id' => $customerId]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.error.not_found'));
        }
        $this->events->emit('events.deleted', ['type' => 'event', 'id' => $id], ['title' => $item->title()]);
        $this->catalog->delete($item);
        $this->auditor->log('events.deleted', 'event', $id);
        $this->flash('success', $this->translator->trans('events.flash.deleted'));

        return $this->redirect('admin.events.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'events.bulk');
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
            $this->flash('error', $this->translator->trans('events.bulk.error.empty'));

            return $this->redirect('admin.events.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('events.bulk.error.confirm'));

            return $this->redirect('admin.events.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $item = $this->catalog->find($id);
            if ($item === null) {
                continue;
            }
            if ($action === 'delete') {
                $this->events->emit('events.deleted', ['type' => 'event', 'id' => $id], ['title' => $item->title()]);
                $this->catalog->delete($item);
                $this->auditor->log('events.deleted', 'event', $id);
                $affected++;
                continue;
            }
            if ($action === 'publish') {
                if ($item->status() === 'published') {
                    continue;
                }
                $item->publish();
                $this->catalog->discardStaging($item->id());
                $this->events->emit('events.published', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
                $this->auditor->log('events.published', 'event', $id);
                $affected++;
                continue;
            }
            if ($action === 'archive') {
                if ($item->status() === 'archived') {
                    continue;
                }
                $item->archive();
                $this->catalog->discardStaging($item->id());
                $this->events->emit('events.archived', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
                $this->auditor->log('events.archived', 'event', $id);
                $affected++;
            }
        }

        $this->em->flush();
        $this->flash('success', $this->translator->trans('events.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.events.index');
    }

    private function formPage(?CatalogEvent $item): Response
    {
        return $this->page('@events/form.html.twig', [
            'pageTitle' => $this->translator->trans($item ? 'events.edit' : 'events.new'),
            'item' => $item,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryAssets('image/'),
            'libraryMedia' => $this->libraryAssets(null),
            'imageUrl' => EventMediaLibrary::publicUrl($item?->mediaAssetId()),
            'galleryItems' => EventGallery::normalize($item?->gallery() ?? []),
            'categoryChoices' => $this->categories->assignableChoices($item?->category()),
            'hideDatesSlugs' => $this->categories->hideDatesSlugs(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, Request $request): array
    {
        $data['description'] = trim(strip_tags((string) ($data['description'] ?? '')));
        $data['long_description'] = $this->html->sanitizeNullable(
            isset($data['long_description']) ? (string) $data['long_description'] : null,
        );
        $gallery = $data['gallery'] ?? $request->request->all('gallery');
        $data['gallery'] = EventGallery::normalize($gallery);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveMedia(Request $request, array $data, ?string $current): Response|string|null
    {
        if ($request->request->get('clear_image') === '1' || $request->request->get('clear_image') === 'on') {
            return null;
        }

        $uploaded = $request->files->get('file');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('events.error.not_image'));

                return $this->redirectBack('admin.events.index');
            }
            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('events.error.upload'));

                return $this->redirectBack('admin.events.index');
            }
            $title = trim((string) ($data['title'] ?? $stored->originalName()));
            $info = @getimagesize($uploaded->getPathname());
            $asset = new MediaAsset(
                $title !== '' ? $title : $stored->originalName(),
                $stored->path(),
                $stored->mime(),
                $stored->size(),
                $this->auth->user()?->id(),
                null,
                is_array($info) ? (int) $info[0] : null,
                is_array($info) ? (int) $info[1] : null,
            );
            $this->em->persist($asset);
            $this->em->flush();

            return $asset->id();
        }

        $mediaId = trim((string) ($data['media_asset_id'] ?? ''));
        if ($mediaId !== '') {
            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('events.error.media_not_found'));

                return $this->redirectBack('admin.events.index');
            }

            return $asset->id();
        }

        return $current;
    }

    private function syncCoverLink(CatalogEvent $event): void
    {
        $assetId = $event->mediaAssetId();
        if ($assetId === null) {
            return;
        }
        $existing = $this->em->getRepository(MediaLink::class)->findOneBy([
            'entityType' => 'catalog_event',
            'entityId' => $event->id(),
            'label' => 'cover',
        ]);
        if ($existing instanceof MediaLink) {
            return;
        }
        $this->em->persist(new MediaLink($assetId, 'catalog_event', $event->id(), 'cover'));
        $this->em->flush();
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /** @return list<array<string, mixed>> */
    private function libraryAssets(?string $mimePrefix): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }
        $provider = $this->capabilities->get('media');
        if (!is_object($provider) || !method_exists($provider, 'search')) {
            return [];
        }
        $folderNames = [];
        if (class_exists(MediaFolder::class)) {
            foreach ($this->em->getRepository(MediaFolder::class)->findAll() as $folder) {
                if ($folder instanceof MediaFolder) {
                    $folderNames[$folder->id()] = $folder->name();
                }
            }
        }
        $options = [];
        foreach ($provider->search('', 400, $mimePrefix) as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $mime = $asset->mime();
            if ($mimePrefix === null && !str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) {
                continue;
            }
            $folderId = $asset->folderId() ?? '';
            $url = EventMediaLibrary::publicUrl($asset->id()) ?? '';
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title(),
                'thumb' => EventMediaLibrary::publicUrl($asset->id(), 320) ?? $url,
                'url' => $url,
                'mime' => $mime,
                'ext' => pathinfo($asset->path(), PATHINFO_EXTENSION),
                'width' => $asset->width(),
                'height' => $asset->height(),
                'created' => $asset->createdAt()->getTimestamp(),
                'folder_id' => $folderId,
                'folder' => $folderId !== '' ? ($folderNames[$folderId] ?? '') : '',
                'kind' => str_starts_with($mime, 'video/')
                    ? 'video'
                    : (str_contains($mime, 'gif') ? 'gif' : 'photo'),
            ];
        }

        return $options;
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
