<?php

declare(strict_types=1);

namespace Gereq\Media\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Ui\Ui;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\File\ImageConverter;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Media\Domain\Events\MediaDeleted;
use Gereq\Media\Domain\Events\MediaUpdated;
use Gereq\Media\Domain\Events\MediaUploaded;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Media\Domain\MediaLink;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MediaController extends Controller
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
        private readonly FileManager $files,
        private readonly Repository $config,
        private readonly Ui $ui,
        private readonly string $basePath,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['title', 'mime', 'size', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            perPage: 48,
            filterKeys: ['mime', 'folder'],
            maxPerPage: 96,
        );
        $mime = $list->filter('mime');
        $folderId = $list->filter('folder');
        $currentFolder = $folderId !== '' ? $this->findFolder($folderId) : null;
        if ($folderId !== '' && $currentFolder === null) {
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.index');
        }

        $searching = $list->search !== '';

        /** @var list<MediaFolder> $folders */
        $folders = [];
        if (!$searching) {
            $folderQb = $this->em->getRepository(MediaFolder::class)->createQueryBuilder('f')
                ->orderBy('f.name', 'ASC');
            if ($currentFolder === null) {
                $folderQb->andWhere('f.parentId IS NULL');
            } else {
                $folderQb->andWhere('f.parentId = :parent')->setParameter('parent', $currentFolder->id());
            }
            $folders = $folderQb->getQuery()->getResult();
        }

        $qb = $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m');
        $list->applySearch($qb, 'm', ['title', 'alt', 'path']);
        if ($mime !== '') {
            if (str_ends_with($mime, '/*')) {
                $qb->andWhere('m.mime LIKE :mime')->setParameter('mime', substr($mime, 0, -1) . '%');
            } else {
                $qb->andWhere('m.mime = :mime')->setParameter('mime', $mime);
            }
        }
        if (!$searching) {
            if ($currentFolder === null) {
                $qb->andWhere('m.folderId IS NULL');
            } else {
                $qb->andWhere('m.folderId = :folder')->setParameter('folder', $currentFolder->id());
            }
        }

        $qb->orderBy('m.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<MediaAsset> $items */
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $canManage = $this->auth->user() instanceof User && $this->auth->user()->can('media.manage');
        $entries = $this->driveEntries($folders, $items);
        $t = $this->translator;

        $pagerQuery = array_filter([
            'q' => $list->search !== '' ? $list->search : null,
            'sort' => $list->sort,
            'dir' => $list->dir,
            'mime' => $mime !== '' ? $mime : null,
            'folder' => $currentFolder?->id(),
        ], static fn ($v) => $v !== null && $v !== '');

        $driveCrumbs = [
            [
                'label' => $t->trans('media.drive.my_drive'),
                'url' => $this->router->url('admin.media.index'),
            ],
        ];
        if ($currentFolder !== null) {
            $chain = [];
            $cursor = $currentFolder;
            $guard = 0;
            while ($cursor !== null && $guard < 50) {
                array_unshift($chain, $cursor);
                $cursor = $cursor->parentId() !== null ? $this->findFolder($cursor->parentId()) : null;
                $guard++;
            }
            foreach ($chain as $i => $row) {
                $isLast = $i === count($chain) - 1;
                $driveCrumbs[] = [
                    'label' => $row->name(),
                    'url' => $isLast ? null : $this->router->url('admin.media.index', ['folder' => $row->id()]),
                ];
            }
        } else {
            $driveCrumbs[0]['url'] = null;
        }

        return $this->page('media/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $t->trans('media.title'),
            'items' => $items,
            'entries' => $entries,
            'mime' => $mime,
            'folder' => $currentFolder?->id() ?? '',
            'currentFolder' => $currentFolder,
            'searching' => $searching,
            'breadcrumbs' => $this->mediaBreadcrumbs($currentFolder),
            'drive_crumbs' => $driveCrumbs,
            'folder_tree' => $this->folderTree(),
            'folderOptions' => $this->folderOptions(),
            'can_manage' => $canManage,
            'pager_query' => $pagerQuery,
            'drive_urls' => [
                'index' => $this->router->url('admin.media.index'),
                'store' => $this->router->url('admin.media.store'),
                'bulk' => $this->router->url('admin.media.bulk'),
                'folder_store' => $this->router->url('admin.media.folders.store'),
                'folder_update' => $this->router->url('admin.media.folders.update', ['id' => '__ID__']),
                'folder_destroy' => $this->router->url('admin.media.folders.destroy', ['id' => '__ID__']),
                'asset_update' => $this->router->url('admin.media.update', ['id' => '__ID__']),
                'asset_destroy' => $this->router->url('admin.media.destroy', ['id' => '__ID__']),
                'asset_move' => $this->router->url('admin.media.move', ['id' => '__ID__']),
                'folder_move' => $this->router->url('admin.media.folders.move', ['id' => '__ID__']),
                'folder_edit' => $this->router->url('admin.media.folders.edit', ['id' => '__ID__']),
                'upload_page' => $this->router->url('admin.media.upload'),
            ],
            'drive_i18n' => [
                'folder_create' => $t->trans('media.folder.create'),
                'folder_create_body' => $t->trans('media.drive.folder_prompt'),
                'folder_name' => $t->trans('media.folder.field.name'),
                'folder_rename' => $t->trans('media.folder.rename'),
                'folder_confirm_delete' => $t->trans('media.folder.confirm_delete'),
                'confirm_delete' => $t->trans('media.confirm_delete'),
                'bulk_confirm_delete' => $t->trans('media.bulk.confirm_delete'),
                'delete_title' => $t->trans('ui.confirm.delete_title'),
                'rename' => $t->trans('media.drive.rename'),
                'create' => $t->trans('common.create'),
                'save' => $t->trans('common.save'),
                'selected' => $t->trans('media.bulk.selected'),
                'folder_badge' => $t->trans('media.folder.badge'),
                'error_generic' => $t->trans('media.drive.error'),
                'upload_failed' => $t->trans('media.drive.upload_failed'),
                'upload_partial' => $t->trans('media.drive.upload_partial'),
                'preview_loading' => $t->trans('media.drive.preview_loading'),
                'preview_failed' => $t->trans('media.drive.preview_failed'),
                'preview_too_large' => $t->trans('media.drive.preview_too_large'),
            ],
        ]));
    }

    public function create(Request $request): Response
    {
        $folderId = trim((string) $request->query->get('folder', ''));
        $folder = $folderId !== '' ? $this->findFolder($folderId) : null;

        return $this->page('media/form.html.twig', [
            'pageTitle' => $this->translator->trans('media.upload'),
            'folder' => $folder?->id() ?? '',
            'folderOptions' => $this->folderOptions(),
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $this->translator->trans('media.upload')),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'media.upload');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('file_required', $this->translator->trans('media.error.file_required'));
            }
            $this->flash('error', $this->translator->trans('media.error.file_required'));

            return $this->redirect('admin.media.upload');
        }

        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($folderId !== '' && $this->findFolder($folderId) === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('folder_not_found', $this->translator->trans('media.folder.error.not_found'));
            }
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.upload');
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('upload_failed', $e->getMessage());
            }
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.media.upload');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $stored->originalName();
        }

        [$width, $height] = $this->imageDimensions($uploaded, $stored);

        $asset = new MediaAsset(
            $title,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            trim((string) ($data['alt'] ?? '')) ?: null,
            $width,
            $height,
            $folderId !== '' ? $folderId : null,
        );
        $this->em->persist($asset);
        $this->em->flush();

        $payload = $this->serialize($asset);
        $this->events->dispatch(MediaUploaded::make($asset->id(), $payload));
        $this->auditor->log('media.uploaded', 'media_asset', $asset->id(), null, [
            'title' => $asset->title(),
            'path' => $asset->path(),
        ]);

        $this->forgetForm('media.upload');

        if ($this->wantsJson($request)) {
            return $this->json([
                'id' => $asset->id(),
                'title' => $asset->title(),
                'folder_id' => $asset->folderId(),
            ]);
        }

        $this->flash('success', $this->translator->trans('media.flash.uploaded', ['%title%' => $asset->title()]));

        return $this->redirectToFolder($asset->folderId());
    }

    public function show(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $folder = $asset->folderId() !== null ? $this->findFolder($asset->folderId()) : null;
        $links = $this->em->getRepository(MediaLink::class)->findBy(
            ['mediaAssetId' => $asset->id()],
            ['createdAt' => 'DESC'],
        );

        return $this->page('media/show.html.twig', [
            'pageTitle' => $asset->title(),
            'item' => $asset,
            'folder' => $folder,
            'links' => $links,
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $asset->title()),
        ]);
    }

    public function edit(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $folder = $asset->folderId() !== null ? $this->findFolder($asset->folderId()) : null;

        return $this->page('media/edit.html.twig', [
            'pageTitle' => $this->translator->trans('media.edit'),
            'item' => $asset,
            'folderOptions' => $this->folderOptions(),
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $this->translator->trans('media.edit'), $asset),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('not_found', $this->translator->trans('media.error.not_found'), status: 404);
            }
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $data = $this->validate($request, 'media.save');
        $asset->setTitle(trim((string) $data['title']));
        $asset->setAlt(isset($data['alt']) ? trim((string) $data['alt']) : null);

        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($folderId !== '' && $this->findFolder($folderId) === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('folder_not_found', $this->translator->trans('media.folder.error.not_found'));
            }
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.edit', ['id' => $id]);
        }
        $asset->moveTo($folderId !== '' ? $folderId : null);
        $this->em->flush();

        $payload = $this->serialize($asset);
        $this->events->dispatch(MediaUpdated::make($asset->id(), $payload));
        $this->auditor->log('media.updated', 'media_asset', $asset->id(), null, [
            'title' => $asset->title(),
        ]);

        $this->forgetForm('media.save');

        if ($this->wantsJson($request)) {
            return $this->json(['id' => $asset->id(), 'title' => $asset->title()]);
        }

        $this->flash('success', $this->translator->trans('media.flash.updated', ['%title%' => $asset->title()]));

        return $this->redirect('admin.media.show', ['id' => $id]);
    }

    public function moveForm(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $folder = $asset->folderId() !== null ? $this->findFolder($asset->folderId()) : null;

        return $this->page('media/move.html.twig', [
            'pageTitle' => $this->translator->trans('media.move'),
            'item' => $asset,
            'folderOptions' => $this->folderOptions(),
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $this->translator->trans('media.move'), $asset),
        ]);
    }

    public function move(Request $request, string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $data = $this->validate($request, 'media.move');
        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($folderId !== '' && $this->findFolder($folderId) === null) {
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.move', ['id' => $id]);
        }

        $asset->moveTo($folderId !== '' ? $folderId : null);
        $this->em->flush();

        $this->events->dispatch(MediaUpdated::make($asset->id(), $this->serialize($asset)));
        $this->auditor->log('media.moved', 'media_asset', $asset->id(), null, [
            'folder_id' => $asset->folderId(),
        ]);
        $this->forgetForm('media.move');
        $this->flash('success', $this->translator->trans('media.flash.moved', ['%title%' => $asset->title()]));

        return $this->redirectToFolder($asset->folderId());
    }

    public function destroy(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            if ($this->wantsJson()) {
                return $this->jsonError('not_found', $this->translator->trans('media.error.not_found'), status: 404);
            }
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $title = $asset->title();
        $folderId = $asset->folderId();
        $payload = $this->serialize($asset);
        $path = $asset->path();

        $this->em->remove($asset);
        $this->em->flush();

        try {
            if ($this->files->filesystem()->fileExists($path)) {
                $this->files->filesystem()->delete($path);
            }
        } catch (\Throwable) {
            // Physical file cleanup is best-effort.
        }

        $this->events->dispatch(MediaDeleted::make($id, $payload));
        $this->auditor->log('media.deleted', 'media_asset', $id);

        if ($this->wantsJson()) {
            return $this->json(['id' => $id, 'deleted' => true]);
        }

        $this->flash('success', $this->translator->trans('media.flash.deleted', ['%title%' => $title]));

        return $this->redirectToFolder($folderId);
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'media.bulk');
        $action = (string) $data['action'];
        $ids = $this->normalizeIds($request->request->all('ids'));
        $returnFolder = trim((string) $request->request->get('folder', ''));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('media.bulk.error.empty'));

            return $this->redirectToFolder($returnFolder !== '' ? $returnFolder : null);
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('media.bulk.error.confirm'));

            return $this->redirectToFolder($returnFolder !== '' ? $returnFolder : null);
        }

        $targetFolderId = null;
        if ($action === 'move') {
            $targetFolderId = trim((string) ($data['folder_id'] ?? ''));
            if ($targetFolderId !== '' && $this->findFolder($targetFolderId) === null) {
                $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

                return $this->redirectToFolder($returnFolder !== '' ? $returnFolder : null);
            }
            $targetFolderId = $targetFolderId !== '' ? $targetFolderId : null;
        }

        $affected = 0;
        foreach ($ids as $id) {
            $asset = $this->find($id);
            if ($asset === null) {
                continue;
            }

            if ($action === 'delete') {
                $payload = $this->serialize($asset);
                $path = $asset->path();
                $this->em->remove($asset);
                $this->em->flush();
                try {
                    if ($this->files->filesystem()->fileExists($path)) {
                        $this->files->filesystem()->delete($path);
                    }
                } catch (\Throwable) {
                }
                $this->events->dispatch(MediaDeleted::make($id, $payload));
                $this->auditor->log('media.deleted', 'media_asset', $id);
                $affected++;
            } elseif ($action === 'move') {
                $asset->moveTo($targetFolderId);
                $this->events->dispatch(MediaUpdated::make($asset->id(), $this->serialize($asset)));
                $this->auditor->log('media.moved', 'media_asset', $asset->id(), null, [
                    'folder_id' => $asset->folderId(),
                ]);
                $affected++;
            }
        }

        if ($action === 'move') {
            $this->em->flush();
        }

        $this->flash('success', $this->translator->trans('media.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        if ($action === 'move') {
            return $this->redirectToFolder($targetFolderId);
        }

        return $this->redirectToFolder($returnFolder !== '' ? $returnFolder : null);
    }

    public function createFolder(Request $request): Response
    {
        $parentId = trim((string) $request->query->get('folder', ''));
        $parent = $parentId !== '' ? $this->findFolder($parentId) : null;

        return $this->page('media/folder_form.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.create'),
            'mode' => 'create',
            'folder' => null,
            'parent' => $parent,
            'parentId' => $parent?->id() ?? '',
            'folderOptions' => $this->folderOptions(),
            'breadcrumbs' => $this->mediaBreadcrumbs($parent, $this->translator->trans('media.folder.create')),
        ]);
    }

    public function storeFolder(Request $request): Response
    {
        $data = $this->validate($request, 'media.folder.save');
        $parentId = trim((string) ($data['parent_id'] ?? ''));
        if ($parentId !== '' && $this->findFolder($parentId) === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('folder_not_found', $this->translator->trans('media.folder.error.not_found'));
            }
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.folders.create', ['folder' => $parentId]);
        }

        $folder = new MediaFolder(trim((string) $data['name']), $parentId !== '' ? $parentId : null);
        $this->em->persist($folder);
        $this->em->flush();

        $this->auditor->log('media.folder.created', 'media_folder', $folder->id(), null, [
            'name' => $folder->name(),
            'parent_id' => $folder->parentId(),
        ]);
        $this->forgetForm('media.folder.save');

        if ($this->wantsJson($request)) {
            return $this->json(['id' => $folder->id(), 'name' => $folder->name(), 'parent_id' => $folder->parentId()]);
        }

        $this->flash('success', $this->translator->trans('media.folder.flash.created', ['%name%' => $folder->name()]));

        return $this->redirectToFolder($folder->parentId());
    }

    public function editFolder(string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        $parent = $folder->parentId() !== null ? $this->findFolder($folder->parentId()) : null;

        return $this->page('media/folder_form.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.rename'),
            'mode' => 'rename',
            'folder' => $folder,
            'parent' => $parent,
            'parentId' => $folder->parentId() ?? '',
            'folderOptions' => $this->folderOptions($folder->id()),
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $this->translator->trans('media.folder.rename')),
        ]);
    }

    public function updateFolder(Request $request, string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('not_found', $this->translator->trans('media.folder.error.not_found'), status: 404);
            }
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        $data = $this->validate($request, 'media.folder.save');
        $folder->rename(trim((string) $data['name']));
        $this->em->flush();

        $this->auditor->log('media.folder.renamed', 'media_folder', $folder->id(), null, [
            'name' => $folder->name(),
        ]);
        $this->forgetForm('media.folder.save');

        if ($this->wantsJson($request)) {
            return $this->json(['id' => $folder->id(), 'name' => $folder->name()]);
        }

        $this->flash('success', $this->translator->trans('media.folder.flash.renamed', ['%name%' => $folder->name()]));

        return $this->redirectToFolder($folder->parentId());
    }

    public function moveFolderForm(string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        return $this->page('media/folder_move.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.move'),
            'folder' => $folder,
            'folderOptions' => $this->folderOptions($folder->id()),
            'breadcrumbs' => $this->mediaBreadcrumbs($folder, $this->translator->trans('media.folder.move')),
        ]);
    }

    public function moveFolder(Request $request, string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        $data = $this->validate($request, 'media.folder.move');
        $parentId = trim((string) ($data['parent_id'] ?? ''));
        $targetParent = $parentId !== '' ? $parentId : null;

        if ($targetParent === $folder->id()) {
            $this->flash('error', $this->translator->trans('media.folder.error.into_self'));

            return $this->redirect('admin.media.folders.move', ['id' => $id]);
        }
        if ($targetParent !== null) {
            $target = $this->findFolder($targetParent);
            if ($target === null) {
                $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

                return $this->redirect('admin.media.folders.move', ['id' => $id]);
            }
            if ($this->isDescendant($targetParent, $folder->id())) {
                $this->flash('error', $this->translator->trans('media.folder.error.into_descendant'));

                return $this->redirect('admin.media.folders.move', ['id' => $id]);
            }
        }

        $folder->moveTo($targetParent);
        $this->em->flush();

        $this->auditor->log('media.folder.moved', 'media_folder', $folder->id(), null, [
            'parent_id' => $folder->parentId(),
        ]);
        $this->forgetForm('media.folder.move');
        $this->flash('success', $this->translator->trans('media.folder.flash.moved', ['%name%' => $folder->name()]));

        return $this->redirectToFolder($folder->parentId());
    }

    public function destroyFolder(string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            if ($this->wantsJson()) {
                return $this->jsonError('not_found', $this->translator->trans('media.folder.error.not_found'), status: 404);
            }
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        $childCount = (int) $this->em->getRepository(MediaFolder::class)->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.parentId = :id')->setParameter('id', $id)
            ->getQuery()->getSingleScalarResult();
        $assetCount = (int) $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.folderId = :id')->setParameter('id', $id)
            ->getQuery()->getSingleScalarResult();

        if ($childCount > 0 || $assetCount > 0) {
            if ($this->wantsJson()) {
                return $this->jsonError('not_empty', $this->translator->trans('media.folder.error.not_empty'));
            }
            $this->flash('error', $this->translator->trans('media.folder.error.not_empty'));

            return $this->redirectToFolder($folder->parentId());
        }

        $name = $folder->name();
        $parentId = $folder->parentId();
        $this->em->remove($folder);
        $this->em->flush();

        $this->auditor->log('media.folder.deleted', 'media_folder', $id);

        if ($this->wantsJson()) {
            return $this->json(['id' => $id, 'deleted' => true]);
        }

        $this->flash('success', $this->translator->trans('media.folder.flash.deleted', ['%name%' => $name]));

        return $this->redirectToFolder($parentId);
    }

    public function publicFile(Request $request, string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        // Public route may only serve images (listings/CMS/city photos). Documents stay behind auth.
        if (!str_starts_with($asset->mime(), 'image/')) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        return $this->serveFile($asset, $request, public: true);
    }

    public function file(Request $request, string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        return $this->serveFile($asset, $request, public: false);
    }

    private function serveFile(MediaAsset $asset, Request $request, bool $public): Response
    {
        $absolute = $this->absolutePath($asset->path());
        if ($absolute === null || !is_file($absolute)) {
            $this->abort(404, $this->translator->trans('media.error.file_missing'));
        }

        if ($public && str_starts_with($asset->mime(), 'image/')) {
            $resized = $this->maybeServeResized($asset, $absolute, $request);
            if ($resized !== null) {
                return $resized;
            }
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $asset->mime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setAutoLastModified();
        $response->setAutoEtag();

        if ($public || str_starts_with($asset->mime(), 'image/')) {
            // Default 30d (immutable by asset id); override via cache.media_ttl.
            $ttl = max(60, (int) $this->config->get('cache.media_ttl', 2592000));
            $response->setPublic();
            $response->setMaxAge($ttl);
            $response->headers->set(
                'Cache-Control',
                sprintf('public, max-age=%d, immutable, stale-while-revalidate=%d', $ttl, min(86400, $ttl)),
            );
            if ($response->isNotModified($request)) {
                return $response;
            }
        } else {
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, max-age=300');
        }

        // Content-Disposition not needed for inline images; UTF-8 names used to break responses.
        if (!str_starts_with($asset->mime(), 'image/')) {
            $filename = $this->safeDownloadName($asset->title(), $asset->path());
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $filename,
            );
            $response->headers->set('Content-Disposition', $disposition);
        }

        // Apply Range / Content-Length before Kernel post-processing so video players can seek.
        $response->prepare($request);

        return $response;
    }

    /**
     * Optional on-the-fly downscale: GET /media/{id}/file?w=640
     * Cached under storage/cache/media/{id}/w{N}.{ext}
     */
    private function maybeServeResized(MediaAsset $asset, string $absolute, Request $request): ?Response
    {
        $w = (int) $request->query->get('w', 0);
        if ($w < 64 || $w > 2400) {
            return null;
        }
        // Snap to common breakpoints to improve cache hit rate.
        $steps = [160, 320, 480, 640, 800, 960, 1200, 1600, 1920, 2400];
        $target = $steps[0];
        foreach ($steps as $step) {
            $target = $step;
            if ($w <= $step) {
                break;
            }
        }

        $srcW = $asset->width();
        if ($srcW !== null && $srcW > 0 && $srcW <= $target) {
            return null;
        }

        $cacheDir = $this->basePath . '/storage/cache/media/' . $asset->id();
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $converter = $this->files->converter();
        $preferAvif = $converter->supportsAvif()
            && str_contains(strtolower($request->headers->get('Accept', '')), 'image/avif');
        $ext = $preferAvif ? 'avif' : ($converter->supportsWebp() ? 'webp' : 'png');
        $cachePath = $cacheDir . '/w' . $target . '.' . $ext;

        if (!is_file($cachePath) || filemtime($cachePath) < filemtime($absolute)) {
            $bytes = @file_get_contents($absolute);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }
            // Platform ImageConverter converts format only (AVIF/WebP); max-width is cache key / future resize.
            $converted = $converter->convert($bytes, $asset->mime());
            if ($converted === null) {
                return null;
            }
            // If we asked for avif but got webp, align extension.
            $cachePath = $cacheDir . '/w' . $target . '.' . $converted['extension'];
            if (file_put_contents($cachePath, $converted['contents']) === false) {
                return null;
            }
            @chmod($cachePath, 0664);
        }

        $mime = match (strtolower(pathinfo($cachePath, PATHINFO_EXTENSION))) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        $response = new BinaryFileResponse($cachePath);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Accept');
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $ttl = max(60, (int) $this->config->get('cache.media_ttl', 2592000));
        $response->setPublic();
        $response->setMaxAge($ttl);
        $response->headers->set(
            'Cache-Control',
            sprintf('public, max-age=%d, immutable, stale-while-revalidate=%d', $ttl, min(86400, $ttl)),
        );
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function safeDownloadName(string $title, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) ?? 'file';
        $ascii = trim($ascii, '-._') ?: 'file';
        if ($ext !== '' && !str_ends_with(strtolower($ascii), '.' . strtolower($ext))) {
            $ascii .= '.' . $ext;
        }

        return $ascii;
    }

    /**
     * @param list<MediaFolder> $folders
     * @param list<MediaAsset> $items
     * @return list<array<string, mixed>>
     */
    private function driveEntries(array $folders, array $items): array
    {
        $entries = [];
        foreach ($folders as $folder) {
            $entries[] = [
                'kind' => 'folder',
                'id' => $folder->id(),
                'name' => $folder->name(),
                'url' => $this->router->url('admin.media.index', ['folder' => $folder->id()]),
                'edit_url' => $this->router->url('admin.media.folders.edit', ['id' => $folder->id()]),
                'move_url' => $this->router->url('admin.media.folders.move', ['id' => $folder->id()]),
                'created' => $folder->createdAt()->format('d. m. Y H:i'),
                'icon' => 'folder',
                'is_image' => false,
                'is_video' => false,
                'is_pdf' => false,
                'is_text' => false,
                'thumb' => null,
                'file_url' => null,
                'alt' => null,
                'folder_id' => $folder->parentId(),
                'dimensions' => null,
                'path' => null,
                'mime' => null,
                'size' => null,
                'size_label' => null,
            ];
        }
        foreach ($items as $asset) {
            $dims = null;
            if ($asset->width() && $asset->height()) {
                $dims = $asset->width() . ' × ' . $asset->height();
            }
            $mime = $asset->mime();
            $entries[] = [
                'kind' => 'asset',
                'id' => $asset->id(),
                'name' => $asset->title(),
                'url' => $this->router->url('admin.media.show', ['id' => $asset->id()]),
                'edit_url' => $this->router->url('admin.media.edit', ['id' => $asset->id()]),
                'move_url' => $this->router->url('admin.media.move', ['id' => $asset->id()]),
                'mime' => $mime,
                'size' => $asset->size(),
                'size_label' => $this->formatBytes($asset->size()),
                'created' => $asset->createdAt()->format('d. m. Y H:i'),
                'is_image' => $asset->isImage(),
                'is_video' => str_starts_with($mime, 'video/'),
                'is_pdf' => $mime === 'application/pdf',
                'is_text' => $this->isTextPreviewMime($mime),
                'thumb' => $asset->isImage()
                    ? $this->router->url('admin.media.file', ['id' => $asset->id()])
                    : null,
                'file_url' => $this->router->url('admin.media.file', ['id' => $asset->id()]),
                'alt' => $asset->alt(),
                'folder_id' => $asset->folderId(),
                'dimensions' => $dims,
                'path' => $asset->path(),
                'icon' => $this->mimeIcon($mime),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{id: string, name: string, depth: int, url: string}>
     */
    private function folderTree(): array
    {
        /** @var list<MediaFolder> $all */
        $all = $this->em->getRepository(MediaFolder::class)
            ->createQueryBuilder('f')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byParent = [];
        foreach ($all as $folder) {
            $parent = $folder->parentId() ?? '';
            $byParent[$parent][] = $folder;
        }

        $flat = [];
        $walk = function (string $parentKey, int $depth) use (&$walk, &$flat, $byParent): void {
            foreach ($byParent[$parentKey] ?? [] as $folder) {
                $flat[] = [
                    'id' => $folder->id(),
                    'name' => $folder->name(),
                    'depth' => $depth,
                    'url' => $this->router->url('admin.media.index', ['folder' => $folder->id()]),
                ];
                $walk($folder->id(), $depth + 1);
            }
        };
        $walk('', 0);

        return $flat;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            $kb = $bytes / 1024;

            return number_format($kb, $kb < 10 ? 1 : 0, ',', ' ') . ' KB';
        }
        $mb = $bytes / (1024 * 1024);

        return number_format($mb, $mb < 10 ? 1 : 0, ',', ' ') . ' MB';
    }

    private function mimeIcon(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'play';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if ($this->isTextPreviewMime($mime)) {
            return 'description';
        }

        return 'file';
    }

    private function isTextPreviewMime(string $mime): bool
    {
        return $mime === 'text/plain'
            || $mime === 'text/csv'
            || $mime === 'application/json'
            || $mime === 'text/markdown'
            || $mime === 'text/x-markdown';
    }

    private function find(string $id): ?MediaAsset
    {
        $asset = $this->em->find(MediaAsset::class, $id);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    private function findFolder(string $id): ?MediaFolder
    {
        $folder = $this->em->find(MediaFolder::class, $id);

        return $folder instanceof MediaFolder ? $folder : null;
    }

    private function redirectToFolder(?string $folderId): Response
    {
        if ($folderId === null || $folderId === '') {
            return $this->redirect('admin.media.index');
        }

        return $this->redirect('admin.media.index', ['folder' => $folderId]);
    }

    /**
     * @return list<array{label: string, url?: string|null}>
     */
    private function mediaBreadcrumbs(?MediaFolder $folder, ?string $currentLabel = null, ?MediaAsset $asset = null): array
    {
        $crumbs = [
            [
                'label' => $this->translator->trans('nav.overview'),
                'url' => $this->router->url('admin.dashboard'),
            ],
            [
                'label' => $this->translator->trans('media.title'),
                'url' => $this->router->url('admin.media.index'),
            ],
        ];

        if ($folder !== null) {
            $chain = [];
            $cursor = $folder;
            $guard = 0;
            while ($cursor !== null && $guard < 50) {
                array_unshift($chain, $cursor);
                $cursor = $cursor->parentId() !== null ? $this->findFolder($cursor->parentId()) : null;
                $guard++;
            }

            foreach ($chain as $row) {
                $crumbs[] = [
                    'label' => $row->name(),
                    'url' => $this->router->url('admin.media.index', ['folder' => $row->id()]),
                ];
            }
        }

        if ($asset !== null) {
            $crumbs[] = [
                'label' => $asset->title(),
                'url' => $this->router->url('admin.media.show', ['id' => $asset->id()]),
            ];
        }

        if ($currentLabel !== null && $currentLabel !== '') {
            $crumbs[] = ['label' => $currentLabel];
        } else {
            $last = count($crumbs) - 1;
            unset($crumbs[$last]['url']);
        }

        return array_values($crumbs);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function folderOptions(?string $excludeId = null): array
    {
        /** @var list<MediaFolder> $all */
        $all = $this->em->getRepository(MediaFolder::class)
            ->createQueryBuilder('f')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byParent = [];
        foreach ($all as $folder) {
            $parent = $folder->parentId() ?? '';
            $byParent[$parent][] = $folder;
        }

        $excluded = [];
        if ($excludeId !== null && $excludeId !== '') {
            $excluded[$excludeId] = true;
            $stack = [$excludeId];
            while ($stack !== []) {
                $id = array_pop($stack);
                foreach ($byParent[$id] ?? [] as $child) {
                    $excluded[$child->id()] = true;
                    $stack[] = $child->id();
                }
            }
        }

        $options = [
            ['value' => '', 'label' => $this->translator->trans('media.folder.root')],
        ];
        $walk = function (string $parentKey, string $prefix) use (&$walk, &$options, $byParent, $excluded): void {
            foreach ($byParent[$parentKey] ?? [] as $folder) {
                if (isset($excluded[$folder->id()])) {
                    continue;
                }
                $options[] = [
                    'value' => $folder->id(),
                    'label' => $prefix . $folder->name(),
                ];
                $walk($folder->id(), $prefix . $folder->name() . ' / ');
            }
        };
        $walk('', '');

        return $options;
    }

    private function isDescendant(string $candidateId, string $ancestorId): bool
    {
        $cursor = $this->findFolder($candidateId);
        $guard = 0;
        while ($cursor !== null && $guard < 50) {
            if ($cursor->parentId() === $ancestorId) {
                return true;
            }
            if ($cursor->id() === $ancestorId) {
                return true;
            }
            $cursor = $cursor->parentId() !== null ? $this->findFolder($cursor->parentId()) : null;
            $guard++;
        }

        return false;
    }

    /** @return list<string> */
    private function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            $ids = [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));
    }

    private function absolutePath(string $relative): ?string
    {
        $root = realpath($this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/'));
        if ($root === false) {
            return null;
        }
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\'], '/', $relative), '/');
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
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
            $resolved = $this->absolutePath($stored->path());
            $info = $resolved !== null ? @getimagesize($resolved) : false;
        }
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /** @return array<string, mixed> */
    private function serialize(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id(),
            'title' => $asset->title(),
            'path' => $asset->path(),
            'mime' => $asset->mime(),
            'size' => $asset->size(),
            'alt' => $asset->alt(),
            'width' => $asset->width(),
            'height' => $asset->height(),
            'folder_id' => $asset->folderId(),
            'uploaded_by' => $asset->uploadedBy(),
            'created_at' => $asset->createdAt()->format(\DateTimeInterface::ATOM),
        ];
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
