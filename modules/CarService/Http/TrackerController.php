<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Application\OrderMediaService;
use Modules\CarService\Application\QrCodeService;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Fleet\Application\VehicleMediaService;
use Modules\Fleet\Domain\Vehicle;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TrackerController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly CarServiceProvider $carservice,
        private readonly OrderMediaService $orderMedia,
        private readonly QrCodeService $qr,
        private readonly VehicleMediaService $vehicleMedia,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $token = trim((string) $request->query->get('token', ''));
        if ($token !== '') {
            $order = $this->carservice->resolveByTrackerToken($token);
            if ($order !== null && $order->isOpen()) {
                return $this->redirect('admin.carservice.tracker.show', ['id' => $order->id()]);
            }
            $this->flash('error', $this->translator->trans('carservice.tracker.not_found'));
        }

        $openOrders = $this->carservice->listOpen(['open_only' => true], 30);

        return $this->page('@carservice/tracker/index.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.tracker.title'),
            'openOrders' => $openOrders,
            'orderCards' => $this->buildOrderCards($openOrders),
        ]);
    }

    public function resolve(Request $request): Response
    {
        $query = trim((string) $request->request->get('q', ''));
        if ($query === '') {
            $this->flash('error', $this->translator->trans('carservice.tracker.empty_query'));

            return $this->redirect('admin.carservice.tracker');
        }
        $order = $this->carservice->resolveByTrackerToken($query);
        if ($order === null) {
            $order = $this->carservice->resolveByPlate($query);
        }
        if ($order === null || !$order->isOpen()) {
            $this->flash('error', $this->translator->trans('carservice.tracker.not_found'));

            return $this->redirect('admin.carservice.tracker');
        }

        return $this->redirect('admin.carservice.tracker.show', ['id' => $order->id()]);
    }

    public function show(string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null || !$order->isOpen()) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $jobs = $this->carservice->jobsForOrder($id);
        $running = null;
        foreach ($jobs as $job) {
            if ($job->isRunning()) {
                $running = $job;
                break;
            }
        }

        return $this->page('@carservice/tracker/show.html.twig', [
            'pageTitle' => $vehicle instanceof Vehicle ? $vehicle->plate() : $this->translator->trans('carservice.tracker.title'),
            'item' => $order,
            'vehicle' => $vehicle instanceof Vehicle ? $vehicle : null,
            'jobs' => $jobs,
            'running' => $running,
            'teams' => ServiceOrder::TEAMS,
            'teamQueue' => $this->carservice->teamQueue($order->team(), $id),
            'mediaPhases' => $this->carservice->mediaPhases(),
            'mediaByPhase' => array_combine(
                $this->carservice->mediaPhases(),
                array_map(fn (string $p) => $this->orderMedia->forOrder($id, $p), $this->carservice->mediaPhases()),
            ) ?: [],
            'mediaAvailable' => $this->orderMedia->available(),
            'qrDataUri' => $this->qr->dataUriForOrder($order->trackerToken()),
            'trackerUrl' => $this->qr->trackerUrl($order->trackerToken()),
        ]);
    }

    public function startJob(Request $request, string $id): Response
    {
        $type = trim((string) $request->request->get('type', 'work')) ?: 'work';
        $note = trim((string) $request->request->get('note', '')) ?: null;
        try {
            $job = $this->carservice->addJob($id, $type, $this->auth->user()?->id(), $note);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
        }
        $this->events->emit('carservice.job_started', ['type' => 'carservice_job', 'id' => $job->id()], $this->carservice->serializeJob($job));
        $this->flash('success', $this->translator->trans('carservice.flash.job_started'));

        return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
    }

    public function stopJob(string $id, string $jobId): Response
    {
        try {
            $job = $this->carservice->completeJob($jobId);
            if ($job->orderId() !== $id) {
                $this->abort(404, $this->translator->trans('carservice.error.job_not_found'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
        }
        $this->events->emit('carservice.job_finished', ['type' => 'carservice_job', 'id' => $jobId], $this->carservice->serializeJob($job));
        if ($this->carservice->jobExceededSoftLimit($job)) {
            $this->flash('warning', $this->translator->trans('carservice.flash.job_over_limit', [
                '%hours%' => (string) $this->carservice->jobSoftLimitHours(),
            ]));
        } else {
            $this->flash('success', $this->translator->trans('carservice.flash.job_finished'));
        }

        return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
    }

    public function uploadMedia(Request $request, string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null || !$order->isOpen()) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $phase = trim((string) $request->request->get('phase', 'progress'));
        if (!in_array($phase, $this->carservice->mediaPhases(), true)) {
            $phase = $this->carservice->mediaPhases()[0] ?? 'progress';
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->flash('error', $this->translator->trans('carservice.error.file_required'));

            return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
        }
        try {
            $this->orderMedia->upload($id, $file, $phase);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
        }
        $this->flash('success', $this->translator->trans('carservice.flash.media_uploaded'));

        return $this->redirect('admin.carservice.tracker.show', ['id' => $id]);
    }

    /**
     * @param list<ServiceOrder> $orders
     * @return list<array{
     *   order: ServiceOrder,
     *   plate: string,
     *   label: string,
     *   subject: string,
     *   photo_url: ?string,
     *   href: string
     * }>
     */
    private function buildOrderCards(array $orders): array
    {
        $cards = [];
        foreach ($orders as $order) {
            $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
            $plate = $vehicle instanceof Vehicle ? $vehicle->plate() : $order->vehicleId();
            $label = '';
            if ($vehicle instanceof Vehicle) {
                $label = trim(($vehicle->brand() ?? '') . ' ' . ($vehicle->model() ?? ''));
                if ($vehicle->modelYear()) {
                    $label = trim($label . ' · ' . $vehicle->modelYear());
                }
            }
            $subject = trim((string) ($order->serviceInfo() ?? $order->note() ?? ''));
            $cards[] = [
                'order' => $order,
                'plate' => $plate,
                'label' => $label,
                'subject' => $subject,
                'photo_url' => $vehicle instanceof Vehicle ? $this->vehiclePhotoUrl($vehicle->id()) : null,
                'href' => $this->router->url('admin.carservice.tracker.show', ['id' => $order->id()]),
            ];
        }

        return $cards;
    }

    private function vehiclePhotoUrl(string $vehicleId): ?string
    {
        if ($vehicleId === '' || !$this->vehicleMedia->available()) {
            return null;
        }
        try {
            $items = $this->vehicleMedia->forVehicle($vehicleId);
        } catch (\Throwable) {
            return null;
        }
        foreach ($items as $row) {
            $asset = $row['asset'] ?? null;
            if ($asset instanceof MediaAsset && $asset->isImage()) {
                try {
                    return $this->router->url('admin.media.file', ['id' => $asset->id()]);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
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
