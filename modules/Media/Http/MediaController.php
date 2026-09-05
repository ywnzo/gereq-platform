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

        if ($list->search !== '') {
            $qb->andWhere('m.title LIKE :q OR m.alt LIKE :q OR m.path LIKE :q')
                ->setParameter('q', '%' . $list->search . '%');
        }
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

        [$width, $height] = $this->imageDimensions($uploaded, $stored->mime());

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

    public function file(string $id): Response
    {
        return $this->serveFile($id);
    }

    public function publicFile(string $id): Response
    {
        return $this->serveFile($id);
    }

    private function serveFile(string $id): Response
    {
        $asset = $this->find($id);
        if ($asset === null) {
            $this->abort(404, $this->translator->trans('media.error.not_found'));
        }

        $absolute = $this->absolutePath($asset->path());
        if (!is_file($absolute)) {
            $this->abort(404, $this->translator->trans('media.error.file_missing'));
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $asset->mime());
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $asset->title(),
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    private function find(string $id): ?MediaAsset
    {
        $asset = $this->em->find(MediaAsset::class, $id);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    private function absolutePath(string $relative): string
    {
        $root = $this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/');

        return $root . '/' . ltrim($relative, '/');
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(UploadedFile $uploaded, string $mime): array
    {
        if (!str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $info = @getimagesize($uploaded->getPathname());
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
