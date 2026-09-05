<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CustomerUploads\Application\CustomerUploadService;
use Modules\CustomerUploads\Domain\CustomerUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class UploadApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CustomerUploadService $uploads,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function session(): JsonResponse
    {
        return ApiResponse::data($this->uploads->createSession());
    }

    public function store(Request $request): JsonResponse
    {
        $sessionHash = $this->requireSession($request);
        if ($sessionHash === null) {
            return ApiResponse::error('unauthorized', 'Invalid upload session', [], 401);
        }
        $file = $request->files->get('file');
        if ($file === null) {
            return ApiResponse::error('validation_error', 'Missing file', [], 422);
        }
        try {
            $upload = $this->uploads->store(
                $sessionHash,
                $file->getPathname(),
                (string) $file->getClientOriginalName(),
                (string) ($file->getMimeType() ?: 'application/octet-stream'),
                (int) $file->getSize(),
                $request->request->get('product_id') ? (string) $request->request->get('product_id') : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($this->uploads->serialize($upload), 201);
    }

    public function consent(Request $request): JsonResponse
    {
        $sessionHash = $this->requireSession($request);
        if ($sessionHash === null) {
            return ApiResponse::error('unauthorized', 'Invalid upload session', [], 401);
        }
        $id = (string) $request->request->get('id', '');
        $upload = $this->em->find(CustomerUpload::class, $id);
        if (!$upload instanceof CustomerUpload || !hash_equals($upload->sessionHash(), $sessionHash)) {
            return ApiResponse::notFound();
        }
        $this->uploads->setConsent($upload, (bool) $request->request->get('consent', true));

        return ApiResponse::data($this->uploads->serialize($upload));
    }

    public function link(Request $request): JsonResponse
    {
        $sessionHash = $this->requireSession($request);
        if ($sessionHash === null) {
            return ApiResponse::error('unauthorized', 'Invalid upload session', [], 401);
        }
        $orderNumber = trim((string) $request->request->get('order_number', ''));
        $ids = $request->request->all('upload_ids');
        if ($orderNumber === '' || !is_array($ids)) {
            return ApiResponse::error('validation_error', 'order_number and upload_ids required', [], 422);
        }
        $count = $this->uploads->linkToOrder($orderNumber, array_map('strval', $ids), $sessionHash);

        return ApiResponse::data(['linked' => $count, 'order_number' => $orderNumber]);
    }

    public function file(Request $request, string $id): Response
    {
        $upload = $this->em->find(CustomerUpload::class, $id);
        if (!$upload instanceof CustomerUpload) {
            return ApiResponse::notFound();
        }
        $path = $this->uploads->absolutePath($upload);
        if (!is_file($path)) {
            return ApiResponse::notFound();
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => $upload->mime(),
            'Content-Disposition' => 'inline; filename="' . addslashes($upload->originalName()) . '"',
        ]);
    }

    private function requireSession(Request $request): ?string
    {
        $token = (string) ($request->headers->get('X-Upload-Session')
            ?? $request->request->get('session_token')
            ?? $request->query->get('session_token')
            ?? '');

        return $token !== '' ? $this->uploads->verifySession($token) : null;
    }
}
