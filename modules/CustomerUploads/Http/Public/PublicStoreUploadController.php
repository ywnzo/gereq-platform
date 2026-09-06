<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Modules\CustomerUploads\Application\CustomerUploadService;
use Modules\CustomerUploads\Domain\CustomerUpload;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public upload API for Vue storefront (session token, CORS). */
final class PublicStoreUploadController extends Controller
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

    public function options(): Response
    {
        return $this->cors(new Response('', 204), 'GET, POST, OPTIONS');
    }

    public function session(): JsonResponse
    {
        return $this->cors(ApiResponse::data($this->uploads->createSession()));
    }

    public function store(Request $request): JsonResponse
    {
        $sessionHash = $this->requireSession($request);
        if ($sessionHash === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Invalid upload session', [], 401));
        }
        $file = $request->files->get('file');
        if ($file === null) {
            return $this->cors(ApiResponse::error('validation_error', 'Missing file', [], 422));
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
            return $this->cors(ApiResponse::error('validation_error', $e->getMessage(), [], 422));
        }

        return $this->cors(ApiResponse::data($this->uploads->serialize($upload), 201), 'GET, POST, OPTIONS');
    }

    public function file(Request $request, string $id): Response
    {
        $sessionHash = $this->requireSession($request);
        if ($sessionHash === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Invalid upload session', [], 401));
        }

        $upload = $this->em->find(CustomerUpload::class, $id);
        if (!$upload instanceof CustomerUpload) {
            return $this->cors(ApiResponse::notFound());
        }
        if (!hash_equals($upload->sessionHash(), $sessionHash)) {
            return $this->cors(ApiResponse::notFound());
        }

        $path = $this->uploads->absolutePath($upload);
        if (!is_file($path)) {
            return $this->cors(ApiResponse::notFound());
        }

        return $this->cors(new BinaryFileResponse($path, 200, [
            'Content-Type' => $upload->mime(),
            'Content-Disposition' => 'inline; filename="' . addslashes($upload->originalName()) . '"',
        ]));
    }

    private function requireSession(Request $request): ?string
    {
        $token = (string) ($request->headers->get('X-Upload-Session')
            ?? $request->request->get('session_token')
            ?? $request->query->get('session_token')
            ?? '');

        return $token !== '' ? $this->uploads->verifySession($token) : null;
    }

    private function cors(Response $response, string $methods = 'GET, POST, OPTIONS'): Response
    {
        return PublicCors::apply($response, $this->request(), $methods);
    }
}
