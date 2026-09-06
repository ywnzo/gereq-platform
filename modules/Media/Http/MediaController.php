<?php

declare(strict_types=1);

namespace Modules\Media\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Media\Domain\Events\MediaDeleted;
use Modules\Media\Domain\Events\MediaUpdated;
use Modules\Media\Domain\Events\MediaUploaded;
use Modules\Media\Domain\MediaAsset;
use Modules\Media\Domain\MediaFolder;
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
        private readonly string $basePath,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['title', 'mime', 'size', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['mime', 'folder'],
        );
        $mime = $list->filter('mime');
        $folderId = $list->filter('folder');
        $currentFolder = $folderId !== '' ? $this->findFolder($folderId) : null;
        if ($folderId !== '' && $currentFolder === null) {
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.index');
        }

        $searching = $list->search !== '';

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
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@media/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('media.title'),
            'items' => $items,
            'folders' => $folders,
            'mime' => $mime,
            'folder' => $currentFolder?->id() ?? '',
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $this->folderBreadcrumbs($currentFolder),
            'folderOptions' => $this->folderOptions(),
        ]));
    }

    public function create(Request $request): Response
    {
        $folderId = trim((string) $request->query->get('folder', ''));
        $folder = $folderId !== '' ? $this->findFolder($folderId) : null;

        return $this->page('@media/form.html.twig', [
            'pageTitle' => $this->translator->trans('media.upload'),
            'folder' => $folder?->id() ?? '',
            'folderOptions' => $this->folderOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'media.upload');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('media.error.file_required'));

            return $this->redirect('admin.media.upload');
        }

        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($folderId !== '' && $this->findFolder($folderId) === null) {
            $this->flash('error', $this->translator->trans('media.folder.error.not_found'));

            return $this->redirect('admin.media.upload');
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
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
        $this->flash('success', $this->translator->trans('media.flash.uploaded', ['%title%' => $asset->title()]));

        return $this->redirectToFolder($asset->folderId());
    }

    public function show(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        return $this->page('@media/show.html.twig', [
            'pageTitle' => $asset->title(),
            'item' => $asset,
        ]);
    }

    public function edit(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        return $this->page('@media/edit.html.twig', [
            'pageTitle' => $this->translator->trans('media.edit'),
            'item' => $asset,
            'folderOptions' => $this->folderOptions(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $data = $this->validate($request, 'media.save');
        $asset->setTitle(trim((string) $data['title']));
        $asset->setAlt(isset($data['alt']) ? trim((string) $data['alt']) : null);

        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($folderId !== '' && $this->findFolder($folderId) === null) {
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
        $this->flash('success', $this->translator->trans('media.flash.updated', ['%title%' => $asset->title()]));

        return $this->redirect('admin.media.show', ['id' => $id]);
    }

    public function moveForm(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        return $this->page('@media/move.html.twig', [
            'pageTitle' => $this->translator->trans('media.move'),
            'item' => $asset,
            'folderOptions' => $this->folderOptions(),
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

        return $this->page('@media/folder_form.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.create'),
            'mode' => 'create',
            'folder' => null,
            'parent' => $parent,
            'parentId' => $parent?->id() ?? '',
            'folderOptions' => $this->folderOptions(),
        ]);
    }

    public function storeFolder(Request $request): Response
    {
        $data = $this->validate($request, 'media.folder.save');
        $parentId = trim((string) ($data['parent_id'] ?? ''));
        if ($parentId !== '' && $this->findFolder($parentId) === null) {
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
        $this->flash('success', $this->translator->trans('media.folder.flash.created', ['%name%' => $folder->name()]));

        return $this->redirectToFolder($folder->parentId());
    }

    public function editFolder(string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        return $this->page('@media/folder_form.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.rename'),
            'mode' => 'rename',
            'folder' => $folder,
            'parent' => $folder->parentId() !== null ? $this->findFolder($folder->parentId()) : null,
            'parentId' => $folder->parentId() ?? '',
            'folderOptions' => $this->folderOptions($folder->id()),
        ]);
    }

    public function updateFolder(Request $request, string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        $data = $this->validate($request, 'media.folder.save');
        $folder->rename(trim((string) $data['name']));
        $this->em->flush();

        $this->auditor->log('media.folder.renamed', 'media_folder', $folder->id(), null, [
            'name' => $folder->name(),
        ]);
        $this->forgetForm('media.folder.save');
        $this->flash('success', $this->translator->trans('media.folder.flash.renamed', ['%name%' => $folder->name()]));

        return $this->redirectToFolder($folder->parentId());
    }

    public function moveFolderForm(string $id): Response
    {
        $folder = $this->findFolder($id);
        if ($folder === null) {
            $this->abort(404, $this->translator->trans('media.folder.error.not_found'));
        }

        return $this->page('@media/folder_move.html.twig', [
            'pageTitle' => $this->translator->trans('media.folder.move'),
            'folder' => $folder,
            'folderOptions' => $this->folderOptions($folder->id()),
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
            $this->flash('error', $this->translator->trans('media.folder.error.not_empty'));

            return $this->redirectToFolder($folder->parentId());
        }

        $name = $folder->name();
        $parentId = $folder->parentId();
        $this->em->remove($folder);
        $this->em->flush();

        $this->auditor->log('media.folder.deleted', 'media_folder', $id);
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

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $asset->mime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setAutoLastModified();
        $response->setAutoEtag();

        if ($public || str_starts_with($asset->mime(), 'image/')) {
            $ttl = max(60, (int) $this->config->get('cache.media_ttl', 604800));
            $response->setPublic();
            $response->setMaxAge($ttl);
            $response->headers->set(
                'Cache-Control',
                sprintf('public, max-age=%d, stale-while-revalidate=%d', $ttl, min(86400, $ttl)),
            );
            if ($response->isNotModified($request)) {
                return $response;
            }
        } else {
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, max-age=300');
        }

        // Content-Disposition u inline obrázků není potřeba a UTF-8 názvy dřív shazovaly odpověď.
        if (!str_starts_with($asset->mime(), 'image/')) {
            $filename = $this->safeDownloadName($asset->title(), $asset->path());
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $filename,
            );
            $response->headers->set('Content-Disposition', $disposition);
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
    private function folderBreadcrumbs(?MediaFolder $current): array
    {
        $crumbs = [
            [
                'label' => $this->translator->trans('media.title'),
                'url' => $this->router->url('admin.media.index'),
            ],
        ];
        if ($current === null) {
            return $crumbs;
        }

        $chain = [];
        $cursor = $current;
        $guard = 0;
        while ($cursor !== null && $guard < 50) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parentId() !== null ? $this->findFolder($cursor->parentId()) : null;
            $guard++;
        }

        foreach ($chain as $folder) {
            $crumbs[] = [
                'label' => $folder->name(),
                'url' => $this->router->url('admin.media.index', ['folder' => $folder->id()]),
            ];
        }

        $last = count($crumbs) - 1;
        unset($crumbs[$last]['url']);

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
