<?php

declare(strict_types=1);

namespace Modules\Media\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Media\Domain\Events\MediaDeleted;
use Modules\Media\Domain\Events\MediaUpdated;
use Modules\Media\Domain\Events\MediaUploaded;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class MediaApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly FileManager $files,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['title', 'createdAt', 'size', 'mime'],
            filterable: ['mime'],
        );

        $qb = $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m');

        if ($query->search !== '') {
            $qb->andWhere('m.title LIKE :q OR m.alt LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['mime']) && $query->filters['mime'] !== '') {
            $mime = (string) $query->filters['mime'];
            if (str_ends_with($mime, '/*')) {
                $qb->andWhere('m.mime LIKE :mime')->setParameter('mime', substr($mime, 0, -1) . '%');
            } else {
                $qb->andWhere('m.mime = :mime')->setParameter('mime', $mime);
            }
        }

        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('m.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<MediaAsset> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (MediaAsset $a): array => $this->serialize($a), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'media.upload');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            return ApiResponse::error('validation_error', 'Soubor je povinný.', ['file' => 'required'], 422);
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            return ApiResponse::error('upload_failed', $e->getMessage(), [], 422);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $stored->originalName();
        }

        $width = null;
        $height = null;
        if (str_starts_with($stored->mime(), 'image/')) {
            $info = @getimagesize($uploaded->getPathname());
            if ($info !== false) {
                $width = (int) $info[0];
                $height = (int) $info[1];
            }
        }

        $asset = new MediaAsset(
            $title,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            isset($data['alt']) ? trim((string) $data['alt']) : null,
            $width,
            $height,
        );
        $this->em->persist($asset);
        $this->em->flush();

        $payload = $this->serialize($asset);
        $this->events->dispatch(MediaUploaded::make($asset->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($asset));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'media.save');
        $asset->setTitle(trim((string) $data['title']));
        $asset->setAlt(isset($data['alt']) ? trim((string) $data['alt']) : null);
        $this->em->flush();

        $payload = $this->serialize($asset);
        $this->events->dispatch(MediaUpdated::make($asset->id(), $payload));

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return ApiResponse::notFound();
        }

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

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?MediaAsset
    {
        $asset = $this->em->find(MediaAsset::class, $id);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '' && !str_contains((string) $request->headers->get('Content-Type'), 'multipart')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
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
            'url' => '/media/' . $asset->id() . '/file',
            'created_at' => $asset->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
