<?php

declare(strict_types=1);

namespace Modules\Documents\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;
use Modules\Documents\Domain\Events\DocumentDeleted;
use Modules\Documents\Domain\Events\DocumentLinked;
use Modules\Documents\Domain\Events\DocumentUploaded;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DocumentController extends Controller
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
        private readonly CapabilityRegistry $capabilities,
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
            filterKeys: ['mime', 'vehicle', 'order'],
        );

        $mime = $list->filter('mime');
        $vehicleId = $list->filter('vehicle');
        $orderId = $list->filter('order');
        $serviceFilters = $this->serviceFiltersEnabled();

        $qb = $this->em->getRepository(Document::class)->createQueryBuilder('d');

        $list->applySearch($qb, 'd', ['title', 'originalName', 'mime']);
        $this->applyMimeFilter($qb, $mime);

        if ($serviceFilters && ($vehicleId !== '' || $orderId !== '')) {
            $this->applyServiceLinkFilter($qb, $vehicleId, $orderId);
        }

        $qb->orderBy('d.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $serviceOptions = $serviceFilters ? $this->serviceFilterOptions() : [
            'plateOptions' => [],
            'orderOptions' => [],
        ];

        return $this->page('@documents/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('documents.title'),
            'items' => $items,
            'mime' => $mime,
            'vehicle' => $serviceFilters ? $vehicleId : '',
            'order' => $serviceFilters ? $orderId : '',
            'serviceFilters' => $serviceFilters,
            'plateOptions' => $serviceOptions['plateOptions'],
            'orderOptions' => $serviceOptions['orderOptions'],
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@documents/form.html.twig', [
            'pageTitle' => $this->translator->trans('documents.upload'),
            'entityType' => (string) $request->query->get('entity_type', ''),
            'entityId' => (string) $request->query->get('entity_id', ''),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'documents.save');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('documents.error.file_required'));

            return $this->redirect('admin.documents.upload');
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.documents.upload');
        }

        $title = trim((string) $data['title']);
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

        $payload = $this->publicDocument($document);
        $this->events->dispatch(DocumentUploaded::make($document->id(), $payload));
        $this->auditor->log('document.uploaded', 'document', $document->id(), null, [
            'title' => $document->title(),
            'path' => $document->path(),
        ]);

        $entityType = trim((string) ($data['entity_type'] ?? ''));
        $entityId = trim((string) ($data['entity_id'] ?? ''));
        if ($entityType !== '' && $entityId !== '') {
            $this->attachLink($document, $entityType, $entityId);
        }

        $this->forgetForm('documents.save');
        $this->flash('success', $this->translator->trans('documents.flash.uploaded', ['%title%' => $document->title()]));

        return $this->redirect('admin.documents.show', ['id' => $document->id()]);
    }

    public function show(string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $links = $this->em->getRepository(DocumentLink::class)->findBy(
            ['documentId' => $document->id()],
            ['createdAt' => 'DESC'],
        );

        return $this->page('@documents/show.html.twig', [
            'pageTitle' => $document->title(),
            'item' => $document,
            'links' => $links,
        ]);
    }

    public function link(Request $request, string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $data = $this->validate($request, 'documents.link');
        $entityType = trim((string) $data['entity_type']);
        $entityId = trim((string) $data['entity_id']);

        $existing = $this->em->getRepository(DocumentLink::class)->findOneBy([
            'documentId' => $document->id(),
            'entityType' => $entityType,
            'entityId' => $entityId,
        ]);
        if ($existing === null) {
            $this->attachLink($document, $entityType, $entityId);
            $this->flash('success', $this->translator->trans('documents.flash.linked'));
        } else {
            $this->flash('success', $this->translator->trans('documents.flash.already_linked'));
        }

        $this->forgetForm('documents.link');

        return $this->redirect('admin.documents.show', ['id' => $id]);
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'documents.bulk');
        $action = (string) $data['action'];
        $ids = $this->normalizeIds($request->request->all('ids'));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('documents.bulk.error.empty'));

            return $this->redirect('admin.documents.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('documents.bulk.error.confirm'));

            return $this->redirect('admin.documents.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $document = $this->find($id);
            if ($document === null) {
                continue;
            }

            if ($action !== 'delete') {
                continue;
            }

            $payload = $this->publicDocument($document);
            $links = $this->em->getRepository(DocumentLink::class)->findBy(['documentId' => $id]);
            foreach ($links as $link) {
                $this->em->remove($link);
            }
            $this->em->remove($document);
            $this->em->flush();
            $this->events->dispatch(DocumentDeleted::make($id, $payload));
            $this->auditor->log('document.deleted', 'document', $id);
            $affected++;
        }

        $this->flash('success', $this->translator->trans('documents.bulk.flash.delete', [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.documents.index');
    }

    public function destroy(string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $title = $document->title();
        $payload = $this->publicDocument($document);

        $links = $this->em->getRepository(DocumentLink::class)->findBy(['documentId' => $id]);
        foreach ($links as $link) {
            $this->em->remove($link);
        }

        $this->em->remove($document);
        $this->em->flush();
        $this->events->dispatch(DocumentDeleted::make($id, $payload));
        $this->auditor->log('document.deleted', 'document', $id);
        $this->flash('success', $this->translator->trans('documents.flash.deleted', ['%title%' => $title]));

        return $this->redirect('admin.documents.index');
    }

    public function file(Request $request, string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $absolute = $this->files->absolutePath($document->path());
        if ($absolute === null || !is_file($absolute)) {
            $this->abort(404, $this->translator->trans('documents.error.file_missing'));
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $document->mime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        $filename = $this->safeDownloadName($document->originalName(), $document->path());
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $filename,
        );
        $response->headers->set('Content-Disposition', $disposition);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function attachLink(Document $document, string $entityType, string $entityId): void
    {
        $link = new DocumentLink($document->id(), $entityType, $entityId);
        $this->em->persist($link);
        $this->em->flush();

        $payload = array_merge($this->publicDocument($document), [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'link_id' => $link->id(),
        ]);
        $this->events->dispatch(DocumentLinked::make($document->id(), $payload));
        $this->auditor->log('document.linked', 'document', $document->id(), null, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    private function find(string $id): ?Document
    {
        $document = $this->em->find(Document::class, $id);

        return $document instanceof Document ? $document : null;
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

    /** @return array<string, mixed> */
    private function publicDocument(Document $document): array
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

    /**
     * @param list<mixed>|mixed $ids
     * @return list<string>
     */
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

    private function serviceFiltersEnabled(): bool
    {
        return $this->capabilities->has('carservice')
            && class_exists(\Modules\CarService\Domain\ServiceOrder::class)
            && class_exists(\Modules\Fleet\Domain\Vehicle::class);
    }

    private function applyMimeFilter(\Doctrine\ORM\QueryBuilder $qb, string $mime): void
    {
        if ($mime === '') {
            return;
        }

        if (str_ends_with($mime, '/*')) {
            $qb->andWhere('d.mime LIKE :mime')->setParameter('mime', substr($mime, 0, -1) . '%');

            return;
        }

        $qb->andWhere('d.mime = :mime')->setParameter('mime', $mime);
    }

    private function applyServiceLinkFilter(\Doctrine\ORM\QueryBuilder $qb, string $vehicleId, string $orderId): void
    {
        $orderEntity = \Modules\CarService\Domain\ServiceOrder::class;
        $orderIds = null;

        if ($vehicleId !== '') {
            /** @var list<string> $orderIds */
            $orderIds = $this->em->createQueryBuilder()
                ->select('o.id')
                ->from($orderEntity, 'o')
                ->where('o.vehicleId = :vid')
                ->setParameter('vid', $vehicleId)
                ->getQuery()
                ->getSingleColumnResult();
        }

        if ($orderId !== '') {
            if ($orderIds !== null && !in_array($orderId, $orderIds, true)) {
                $orderIds = [];
            } else {
                $orderIds = [$orderId];
            }
        }

        if ($orderIds === null) {
            return;
        }

        if ($orderIds === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere(
            'd.id IN (
                SELECT dl.documentId FROM ' . DocumentLink::class . ' dl
                WHERE dl.entityType = :svcEntity AND dl.entityId IN (:svcOrderIds)
            )'
        )
            ->setParameter('svcEntity', 'carservice_order')
            ->setParameter('svcOrderIds', $orderIds);
    }

    /**
     * @return array{plateOptions: list<array{value: string, label: string, description?: string, search: string}>, orderOptions: list<array{value: string, label: string, description?: string, search: string}>}
     */
    private function serviceFilterOptions(): array
    {
        $vehicleEntity = \Modules\Fleet\Domain\Vehicle::class;
        $orderEntity = \Modules\CarService\Domain\ServiceOrder::class;

        /** @var list<\Modules\Fleet\Domain\Vehicle> $vehicles */
        $vehicles = $this->em->getRepository($vehicleEntity)
            ->createQueryBuilder('v')
            ->orderBy('v.plate', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $plateOptions = [[
            'value' => '',
            'label' => $this->translator->trans('documents.filter.plate_any'),
            'search' => '',
        ]];
        foreach ($vehicles as $vehicle) {
            $desc = trim(($vehicle->brand() ?? '') . ' ' . $vehicle->displayModel());
            $plateOptions[] = [
                'value' => $vehicle->id(),
                'label' => $vehicle->plate(),
                'description' => $desc !== '' ? $desc : '',
                'search' => $vehicle->plate() . ' ' . $desc . ' ' . ($vehicle->vin() ?? ''),
            ];
        }

        /** @var list<\Modules\CarService\Domain\ServiceOrder> $orders */
        $orders = $this->em->getRepository($orderEntity)
            ->createQueryBuilder('o')
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $vehicleMap = [];
        foreach ($vehicles as $vehicle) {
            $vehicleMap[$vehicle->id()] = $vehicle;
        }

        $orderOptions = [[
            'value' => '',
            'label' => $this->translator->trans('documents.filter.order_any'),
            'search' => '',
        ]];
        foreach ($orders as $order) {
            $short = substr($order->id(), 0, 8);
            $vehicle = $vehicleMap[$order->vehicleId()] ?? null;
            $plate = $vehicle?->plate() ?? '';
            $status = $this->translator->trans('carservice.status.' . $order->status());
            $orderOptions[] = [
                'value' => $order->id(),
                'label' => '#' . $short,
                'description' => trim($plate . ($plate !== '' ? ' · ' : '') . $status),
                'search' => $short . ' ' . $order->id() . ' ' . $plate . ' ' . $status,
            ];
        }

        return [
            'plateOptions' => $plateOptions,
            'orderOptions' => $orderOptions,
        ];
    }
}
