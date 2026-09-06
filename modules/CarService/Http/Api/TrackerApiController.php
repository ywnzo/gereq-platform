<?php

declare(strict_types=1);

namespace Modules\CarService\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CarService\Application\BranchContext;
use Modules\CarService\Application\CarServiceProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class TrackerApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EventBus $events,
        private readonly CarServiceProvider $carservice,
        private readonly Authenticator $auth,
        private readonly BranchContext $branchContext,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function resolve(Request $request): JsonResponse
    {
        $body = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $body = array_merge($body, $j);
            }
        }
        $q = trim((string) ($body['q'] ?? $body['token'] ?? $body['plate'] ?? ''));
        $order = $this->carservice->resolveByTrackerToken($q);
        if ($order === null) {
            $order = $this->carservice->resolveByPlate($q);
        }
        if ($order === null || !$order->isOpen()) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        $jobs = $this->carservice->jobsForOrder($order->id());
        $data = $this->carservice->serialize($order, $jobs);
        $data['jobs'] = array_map(fn ($j) => $this->carservice->serializeJob($j), $jobs);

        return ApiResponse::data($data);
    }

    public function startJob(Request $request, string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null || !$order->isOpen()) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        $body = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $body = array_merge($body, $j);
            }
        }
        try {
            $job = $this->carservice->addJob(
                $id,
                trim((string) ($body['type'] ?? 'work')) ?: 'work',
                trim((string) ($body['employee_user_id'] ?? '')) ?: null,
                trim((string) ($body['note'] ?? '')) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serializeJob($job);
        $this->events->emit('carservice.job_started', ['type' => 'carservice_job', 'id' => $job->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function completeJob(string $id, string $jobId): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null || !$order->isOpen()) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        try {
            $job = $this->carservice->completeJob($jobId);
            if ($job->orderId() !== $id) {
                return ApiResponse::notFound();
            }
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serializeJob($job);
        $payload['over_soft_limit'] = $this->carservice->jobExceededSoftLimit($job);
        $this->events->emit('carservice.job_finished', ['type' => 'carservice_job', 'id' => $jobId], $payload);

        return ApiResponse::data($payload);
    }

    public function uploadMedia(Request $request, string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null || !$order->isOpen()) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            return ApiResponse::error('validation_error', 'file required', [], 422);
        }
        $phase = trim((string) $request->request->get('phase', 'progress'));
        if (!in_array($phase, $this->carservice->mediaPhases(), true)) {
            $phase = $this->carservice->mediaPhases()[0] ?? 'progress';
        }
        try {
            $media = gereq()->make(\Modules\CarService\Application\OrderMediaService::class);
            $asset = $media->upload($id, $file, $phase);
        } catch (\Throwable $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'id' => $asset->id(),
            'title' => $asset->title(),
            'phase' => $phase,
            'mime' => $asset->mime(),
        ], 201);
    }
}
