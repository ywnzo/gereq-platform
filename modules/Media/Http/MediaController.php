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
            filterKeys: ['mime'],
        );
        $mime = $list->filter('mime');

        $qb = $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m');

        $list->applySearch($qb, 'm', ['title', 'alt', 'path']);
        if ($mime !== '') {
            if (str_ends_with($mime, '/*')) {
                $qb->andWhere('m.mime LIKE :mime')->setParameter('mime', substr($mime, 0, -1) . '%');
            } else {
                $qb->andWhere('m.mime = :mime')->setParameter('mime', $mime);
            }
        }

        $qb->orderBy('m.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@media/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('media.title'),
            'items' => $items,
            'mime' => $mime,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@media/form.html.twig', [
            'pageTitle' => $this->translator->trans('media.upload'),
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

        return $this->redirect('admin.media.show', ['id' => $asset->id()]);
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

    public function destroy(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $title = $asset->title();
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

        return $this->redirect('admin.media.index');
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
