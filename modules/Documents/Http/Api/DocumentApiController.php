<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;
use Modules\Documents\Domain\Events\DocumentDeleted;
use Modules\Documents\Domain\Events\DocumentLinked;
use Modules\Documents\Domain\Events\DocumentUploaded;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class DocumentApiController extends Controller
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
            filterable: ['mime', 'entity_type', 'entity_id'],
        );

        $qb = $this->em->getRepository(Document::class)->createQueryBuilder('d');

        if ($query->search !== '') {
            $qb->andWhere('d.title LIKE :q OR d.originalName LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['mime']) && $query->filters['mime'] !== '') {
            $qb->andWhere('d.mime = :mime')->setParameter('mime', $query->filters['mime']);
        }

        $entityType = (string) ($query->filters['entity_type'] ?? '');
        $entityId = (string) ($query->filters['entity_id'] ?? '');
        if ($entityType !== '' && $entityId !== '') {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . DocumentLink::class . ' l WHERE l.documentId = d.id AND l.entityType = :etype AND l.entityId = :eid)'
            )
                ->setParameter('etype', $entityType)
                ->setParameter('eid', $entityId);
        }

        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('d.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Document> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Document $d): array => $this->serialize($d), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'documents.save');
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

        $document = new Document(
            $title,
            $stored->path(),
            $stored->originalName(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
        );
        $this->em->persist($document);
        $this->em->flush();

        $payload = $this->serialize($document);
        $this->events->dispatch(DocumentUploaded::make($document->id(), $payload));

        $entityType = trim((string) ($data['entity_type'] ?? ''));
        $entityId = trim((string) ($data['entity_id'] ?? ''));
        if ($entityType !== '' && $entityId !== '') {
            $link = new DocumentLink($document->id(), $entityType, $entityId);
            $this->em->persist($link);
            $this->em->flush();
            $this->events->dispatch(DocumentLinked::make($document->id(), array_merge($payload, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'link_id' => $link->id(),
            ])));
        }

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $document = $this->find($id);
        if ($document === null) {
            return ApiResponse::notFound();
        }

        $links = $this->em->getRepository(DocumentLink::class)->findBy(['documentId' => $id]);
        $payload = $this->serialize($document);
        $payload['links'] = array_map(static fn (DocumentLink $l): array => [
            'id' => $l->id(),
            'entity_type' => $l->entityType(),
            'entity_id' => $l->entityId(),
            'created_at' => $l->createdAt()->format(\DateTimeInterface::ATOM),
        ], $links);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $document = $this->find($id);
        if ($document === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($document);
        $links = $this->em->getRepository(DocumentLink::class)->findBy(['documentId' => $id]);
        foreach ($links as $link) {
            $this->em->remove($link);
        }
        $this->em->remove($document);
        $this->em->flush();
        $this->events->dispatch(DocumentDeleted::make($id, $payload));

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?Document
    {
        $document = $this->em->find(Document::class, $id);

        return $document instanceof Document ? $document : null;
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
    private function serialize(Document $document): array
    {
        return [
            'id' => $document->id(),
            'title' => $document->title(),
            'path' => $document->path(),
            'original_name' => $document->originalName(),
            'mime' => $document->mime(),
            'size' => $document->size(),
            'uploaded_by' => $document->uploadedBy(),
            'created_at' => $document->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
