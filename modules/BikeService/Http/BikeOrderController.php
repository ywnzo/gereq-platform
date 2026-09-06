<?php

declare(strict_types=1);

namespace Modules\BikeService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Debug\FullServiceLogger;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\User\Entity\User;
use Modules\BikeService\Application\BikeServiceProvider;
use Modules\BikeService\Application\FinishOrderService;
use Modules\BikeService\Application\OrderEsignService;
use Modules\BikeService\Application\OrderMediaService;
use Modules\BikeService\Application\PdfTemplateCatalog;
use Modules\BikeService\Application\QrCodeService;
use Modules\BikeService\Application\ServiceDocumentService;
use Modules\BikeService\Domain\Bike;
use Modules\BikeService\Domain\BikeJob;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Application\DocumentsProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BikeOrderController extends Controller
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
        private readonly BikeServiceProvider $bikeservice,
        private readonly OrderMediaService $orderMedia,
        private readonly ServiceDocumentService $serviceDocuments,
        private readonly FinishOrderService $finishOrder,
        private readonly QrCodeService $qr,
        private readonly CapabilityRegistry $capabilities,
        private readonly OrderEsignService $orderEsign,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['status', 'team', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'team', 'bike'],
        );
        $status = $list->filter('status');
        $team = $list->filter('team');
        $bikeId = $list->filter('bike');

        $qb = $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o');
        if (in_array($status, BikeOrder::STATUSES, true)) {
            $qb->andWhere('o.status = :s')->setParameter('s', $status);
        }
        if (in_array($team, BikeOrder::TEAMS, true)) {
            $qb->andWhere('o.team = :t')->setParameter('t', $team);
        }
        if ($bikeId !== '') {
            $qb->andWhere('o.bikeId = :b')->setParameter('b', $bikeId);
        }
        $qb->orderBy('o.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@bikeservice/orders/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('bikeservice.orders.title'),
            'items' => $items,
            'bikes' => $this->bikeMap($items),
            'customers' => $this->customerMap($items),
            'status' => $status,
            'team' => $team,
            'statuses' => BikeOrder::STATUSES,
            'teams' => BikeOrder::TEAMS,
            'list_query' => $list,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@bikeservice/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.orders.new'),
            'item' => null,
            'bikeId' => (string) $request->query->get('bike', ''),
            'customerId' => (string) $request->query->get('customer', ''),
            'bikeOptions' => $this->bikeOptions(),
            'customerOptions' => $this->customerOptions(),
            'teams' => BikeOrder::TEAMS,
            'statuses' => BikeOrder::STATUSES,
            'paymentTypes' => BikeOrder::PAYMENT_TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        try {
            $order = $this->bikeservice->createOrder($data['bike_id'], $data['customer_id'], $data);
            if ($data['status'] !== 'intake') {
                $order->setStatus($data['status']);
                $this->em->flush();
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectBack('admin.bikeservice.orders.create');
        }
        $payload = $this->bikeservice->serializeOrder($order);
        $this->auditor->log('bikeservice.order_created', 'bikeservice_order', $order->id());
        $this->events->emit('bikeservice.order_created', ['type' => 'bikeservice_order', 'id' => $order->id()], $payload);
        $this->flash('success', $this->translator->trans('bikeservice.flash.order_created'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $order->id()]);
    }

    public function show(string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $bike = $this->bikeservice->getBike($order->bikeId());
        $customer = $order->customerId() !== null
            ? $this->em->find(Customer::class, $order->customerId())
            : null;
        $jobs = $this->em->getRepository(BikeJob::class)->findBy(['orderId' => $order->id()], ['startedAt' => 'DESC']);
        $jobSeconds = 0;
        foreach ($jobs as $job) {
            if ($job instanceof BikeJob && $job->durationMinutes() !== null) {
                $jobSeconds += $job->durationMinutes() * 60;
            }
        }

        $mediaPhases = $this->bikeservice->mediaPhases();
        $mediaByPhase = $this->orderMedia->forOrderByPhases($id, $mediaPhases);

        $documents = [];
        if ($this->capabilities->has('documents') && class_exists(DocumentsProvider::class)) {
            $cap = $this->capabilities->get('documents');
            if ($cap instanceof DocumentsProvider) {
                $documents = $cap->forEntity(ServiceDocumentService::DOCUMENT_ENTITY, $id);
            }
        }

        return $this->page('@bikeservice/orders/show.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.orders.one', ['%id%' => substr($order->id(), 0, 8)]),
            'item' => $order,
            'bike' => $bike,
            'customer' => $customer instanceof Customer ? $customer : null,
            'jobs' => $jobs,
            'jobSeconds' => $jobSeconds,
            'statuses' => BikeOrder::STATUSES,
            'teams' => BikeOrder::TEAMS,
            'userOptions' => $this->userOptions(),
            'mediaAvailable' => $this->orderMedia->available(),
            'mediaPhases' => $mediaPhases,
            'mediaByPhase' => $mediaByPhase,
            'documentsAvailable' => $this->serviceDocuments->available(),
            'documents' => $documents,
            'pdfTypes' => PdfTemplateCatalog::typeMeta(),
            'qrDataUri' => $this->qr->dataUriForOrder($order->id()),
            'orderUrl' => $this->qr->orderUrl($order->id()),
            'esignAvailable' => $this->orderEsign->available(),
            'esignDocuments' => $this->orderEsign->available()
                ? ($this->orderEsign->esign()?->documentsForSource(
                    \Modules\ESign\Application\ESignProvider::SOURCE_BIKESERVICE_ORDER,
                    $id,
                ) ?? [])
                : [],
        ]);
    }

    public function edit(string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }

        return $this->page('@bikeservice/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('bikeservice.orders.edit'),
            'item' => $order,
            'bikeId' => $order->bikeId(),
            'customerId' => $order->customerId() ?? '',
            'bikeOptions' => $this->bikeOptions(),
            'customerOptions' => $this->customerOptions(),
            'teams' => BikeOrder::TEAMS,
            'statuses' => BikeOrder::STATUSES,
            'paymentTypes' => BikeOrder::PAYMENT_TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $data = $this->validated($request);
        try {
            $this->bikeservice->updateOrder($order, $data);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.bikeservice.orders.edit', ['id' => $id]);
        }
        $payload = $this->bikeservice->serializeOrder($order);
        $this->auditor->log('bikeservice.order_updated', 'bikeservice_order', $order->id());
        $this->events->emit('bikeservice.order_updated', ['type' => 'bikeservice_order', 'id' => $order->id()], $payload);
        $this->flash('success', $this->translator->trans('bikeservice.flash.order_updated'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $order->id()]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $status = (string) $request->request->get('status', '');
        if (!in_array($status, BikeOrder::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('bikeservice.error.invalid_status'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        if ($status === 'done') {
            $result = $this->finishOrder->finish($id);
            $order = $result['order'];
        } else {
            $prev = $order->status();
            $order->setStatus($status);
            $this->em->flush();
            FullServiceLogger::log('bikeservice.order.status_changed', [
                'order_id' => $id,
                'from' => $prev,
                'to' => $status,
                'source' => 'updateStatus',
            ]);
        }
        $this->events->emit('bikeservice.order_updated', ['type' => 'bikeservice_order', 'id' => $order->id()], $this->bikeservice->serializeOrder($order));
        $this->flash('success', $this->translator->trans('bikeservice.flash.status_updated'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function switchTeam(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $team = (string) $request->request->get('team', '');
        if (!in_array($team, BikeOrder::TEAMS, true)) {
            $this->flash('error', $this->translator->trans('bikeservice.error.invalid_team'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $order->setTeam($team);
        $this->em->flush();
        $this->events->emit('bikeservice.team_switched', ['type' => 'bikeservice_order', 'id' => $order->id()], $this->bikeservice->serializeOrder($order));
        $this->flash('success', $this->translator->trans('bikeservice.flash.team_switched'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function storeJob(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $data = $this->validate($request, 'bikeservice.job');
        $job = $this->bikeservice->startJob(
            $order,
            trim((string) ($data['type'] ?? 'work')) ?: 'work',
            trim((string) ($data['employee_user_id'] ?? '')) ?: null,
            trim((string) ($data['note'] ?? '')) ?: null,
        );
        $this->events->emit('bikeservice.job_started', ['type' => 'bikeservice_job', 'id' => $job->id()], [
            'order_id' => $order->id(),
            'job_id' => $job->id(),
            'type' => $job->type(),
        ]);
        $this->flash('success', $this->translator->trans('bikeservice.flash.job_started'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function completeJob(string $id, string $jobId): Response
    {
        $order = $this->bikeservice->getOrder($id);
        $job = $this->em->find(BikeJob::class, $jobId);
        if ($order === null || !$job instanceof BikeJob || $job->orderId() !== $order->id()) {
            $this->abort(404);
        }
        $this->bikeservice->completeJob($job);
        $this->events->emit('bikeservice.job_finished', ['type' => 'bikeservice_job', 'id' => $job->id()], [
            'order_id' => $order->id(),
            'job_id' => $job->id(),
        ]);
        $this->flash('success', $this->translator->trans('bikeservice.flash.job_finished'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function finish(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        $data = $this->validate($request, 'bikeservice.finish');
        $meta = [
            'service_info' => trim((string) ($data['service_info'] ?? '')) ?: null,
            'payment_type' => trim((string) ($data['payment_type'] ?? '')) ?: null,
            'labor_cost' => trim((string) ($data['labor_cost'] ?? '')) !== '' ? $data['labor_cost'] : null,
            'parts_cost' => trim((string) ($data['parts_cost'] ?? '')) !== '' ? $data['parts_cost'] : null,
            'intake_odometer_km' => trim((string) ($data['intake_odometer_km'] ?? '')) !== '' ? (int) $data['intake_odometer_km'] : null,
            'intake_battery_pct' => trim((string) ($data['intake_battery_pct'] ?? '')) !== '' ? (int) $data['intake_battery_pct'] : null,
            'signature' => trim((string) ($data['signature'] ?? '')),
            'sign_mode' => trim((string) ($data['sign_mode'] ?? 'manual')),
            'signature_provider_png' => trim((string) ($data['signature_provider_png'] ?? '')),
            'signature_customer_png' => trim((string) ($data['signature_customer_png'] ?? '')),
            'esign_email' => trim((string) ($data['esign_email'] ?? '')),
        ];
        try {
            $result = $this->finishOrder->finish($id, $meta, $request);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $payload = $this->bikeservice->serializeOrder($result['order']);
        $this->auditor->log('bikeservice.order_finished', 'bikeservice_order', $result['order']->id());
        $this->events->emit('bikeservice.order_finished', ['type' => 'bikeservice_order', 'id' => $result['order']->id()], $payload);
        if (!empty($result['esign']['message'])) {
            $params = [];
            if (!empty($result['esign']['signing_url'])) {
                $params['%url%'] = (string) $result['esign']['signing_url'];
            }
            $this->flash('success', $this->translator->trans((string) $result['esign']['message'], $params));
        }
        $this->flash('success', $this->translator->trans(
            $result['document'] !== null
                ? 'bikeservice.flash.order_finished_doc'
                : 'bikeservice.flash.order_finished',
        ));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function uploadMedia(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        if (!$this->orderMedia->available()) {
            $this->flash('error', $this->translator->trans('bikeservice.error.media_unavailable'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $phase = trim((string) $request->request->get('phase', ''));
        if (!in_array($phase, $this->bikeservice->mediaPhases(), true)) {
            $this->flash('error', $this->translator->trans('bikeservice.error.invalid_media_phase'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->flash('error', $this->translator->trans('bikeservice.error.media_file'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        try {
            $this->orderMedia->upload($id, $file, $phase);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $this->flash('success', $this->translator->trans('bikeservice.flash.media_uploaded'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function generateDocument(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        if (!$this->serviceDocuments->available()) {
            $this->flash('error', $this->translator->trans('bikeservice.error.documents_unavailable'));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $data = $this->validate($request, 'bikeservice.document');
        $type = (string) $data['type'];
        try {
            $this->serviceDocuments->generate($order, $type, [
                'signature' => trim((string) ($data['signature'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
        }
        $this->auditor->log('bikeservice.document_generated', 'bikeservice_order', $id, null, ['type' => $type]);
        $this->flash('success', $this->translator->trans('bikeservice.flash.document_generated'));

        return $this->redirect('admin.bikeservice.orders.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            $this->abort(404);
        }
        foreach ($this->em->getRepository(BikeJob::class)->findBy(['orderId' => $id]) as $job) {
            $this->em->remove($job);
        }
        $this->em->remove($order);
        $this->em->flush();
        $this->auditor->log('bikeservice.order_deleted', 'bikeservice_order', $id);
        $this->flash('success', $this->translator->trans('bikeservice.flash.order_deleted'));

        return $this->redirect('admin.bikeservice.orders');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'bikeservice.bulk');
        if (($data['action'] ?? '') !== 'delete' || empty($data['confirmed'])) {
            $this->flash('error', $this->translator->trans('bikeservice.bulk.error.confirm'));

            return $this->redirect('admin.bikeservice.orders');
        }
        $ids = $request->request->all('ids');
        if (!is_array($ids) || $ids === []) {
            $this->flash('error', $this->translator->trans('bikeservice.bulk.error.empty'));

            return $this->redirect('admin.bikeservice.orders');
        }
        $count = 0;
        foreach ($ids as $id) {
            $order = $this->bikeservice->getOrder((string) $id);
            if ($order === null) {
                continue;
            }
            foreach ($this->em->getRepository(BikeJob::class)->findBy(['orderId' => $order->id()]) as $job) {
                $this->em->remove($job);
            }
            $this->em->remove($order);
            ++$count;
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('bikeservice.bulk.flash.delete', ['%count%' => (string) $count]));

        return $this->redirect('admin.bikeservice.orders');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'bikeservice.save');
        $bikeId = trim((string) $data['bike_id']);
        if (!$this->em->find(Bike::class, $bikeId) instanceof Bike) {
            $this->abort(422, $this->translator->trans('bikeservice.error.invalid_bike'));
        }
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if ($customerId !== '' && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('bikeservice.error.invalid_customer'));
        }
        $intake = trim((string) ($data['intake_at'] ?? ''));
        $handover = trim((string) ($data['handover_at'] ?? ''));
        $odo = trim((string) ($data['intake_odometer_km'] ?? ''));
        $battery = trim((string) ($data['intake_battery_pct'] ?? ''));
        $labor = trim((string) ($data['labor_cost'] ?? ''));
        $parts = trim((string) ($data['parts_cost'] ?? ''));
        $payment = trim((string) ($data['payment_type'] ?? ''));

        return [
            'bike_id' => $bikeId,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'status' => in_array(($data['status'] ?? 'intake'), BikeOrder::STATUSES, true) ? (string) $data['status'] : 'intake',
            'team' => in_array(($data['team'] ?? 'mechanics'), BikeOrder::TEAMS, true) ? (string) $data['team'] : 'mechanics',
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'service_info' => trim((string) ($data['service_info'] ?? '')) ?: null,
            'payment_type' => $payment !== '' && in_array($payment, BikeOrder::PAYMENT_TYPES, true) ? $payment : null,
            'labor_cost' => $labor !== '' ? $labor : null,
            'parts_cost' => $parts !== '' ? $parts : null,
            'intake_odometer_km' => $odo !== '' ? (int) $odo : null,
            'intake_battery_pct' => $battery !== '' ? (int) $battery : null,
            'intake_at' => $intake !== '' ? new \DateTimeImmutable($intake) : null,
            'handover_at' => $handover !== '' ? new \DateTimeImmutable($handover) : null,
        ];
    }

    /** @param list<BikeOrder> $items @return array<string, Bike> */
    private function bikeMap(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (BikeOrder $o): string => $o->bikeId(), $items)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Bike::class)->createQueryBuilder('b')->where('b.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $b) {
            if ($b instanceof Bike) {
                $map[$b->id()] = $b;
            }
        }

        return $map;
    }

    /** @param list<BikeOrder> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (BikeOrder $o): ?string => $o->customerId(), $items))));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->where('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $map[$c->id()] = $c;
            }
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function bikeOptions(): array
    {
        return array_map(
            static fn (Bike $b): array => ['value' => $b->id(), 'label' => $b->label()],
            $this->em->getRepository(Bike::class)->createQueryBuilder('b')->orderBy('b.brand', 'ASC')->setMaxResults(500)->getQuery()->getResult(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        $opts = [['value' => '', 'label' => '—']];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $opts[] = ['value' => $c->id(), 'label' => $c->name()];
            }
        }

        return $opts;
    }

    /** @return list<array{value: string, label: string}> */
    private function userOptions(): array
    {
        $opts = [['value' => '', 'label' => '—']];
        foreach ($this->em->getRepository(User::class)->findBy(['status' => 'active'], ['name' => 'ASC']) as $u) {
            if ($u instanceof User) {
                $opts[] = ['value' => $u->id(), 'label' => $u->name() ?: $u->email()];
            }
        }

        return $opts;
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
