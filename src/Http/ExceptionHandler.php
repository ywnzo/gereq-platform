<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Config\Repository;
use Gereq\Debug\DebugBar;
use Gereq\Hook\HookManager;
use Gereq\Http\Exception\HttpException;
use Gereq\Http\Exception\HttpExceptionInterface;
use Gereq\Http\Exception\MethodNotAllowedHttpException;
use Gereq\Http\Exception\NotFoundHttpException;
use Gereq\Security\ContentSecurityPolicy;
use Gereq\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ExceptionHandler
{
    /** @var array<int, array{title: string, heading: string, message: string}> */
    private const PAGES = [
        401 => ['title' => 'errors.401.title', 'heading' => 'errors.401.heading', 'message' => 'errors.401.message'],
        403 => ['title' => 'errors.403.title', 'heading' => 'errors.403.heading', 'message' => 'errors.403.message'],
        404 => ['title' => 'errors.404.title', 'heading' => 'errors.404.heading', 'message' => 'errors.404.message'],
        405 => ['title' => 'errors.405.title', 'heading' => 'errors.405.heading', 'message' => 'errors.405.message'],
        419 => ['title' => 'errors.419.title', 'heading' => 'errors.419.heading', 'message' => 'errors.419.message'],
        422 => ['title' => 'errors.422.title', 'heading' => 'errors.422.heading', 'message' => 'errors.422.message'],
        429 => ['title' => 'errors.429.title', 'heading' => 'errors.429.heading', 'message' => 'errors.429.message'],
        500 => ['title' => 'errors.500.title', 'heading' => 'errors.500.heading', 'message' => 'errors.500.message'],
        503 => ['title' => 'errors.503.title', 'heading' => 'errors.503.heading', 'message' => 'errors.503.message'],
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly HookManager $hooks,
        private readonly ContentSecurityPolicy $csp,
        private readonly Repository $config,
        private readonly Router $router,
        private readonly TranslatorInterface $translator,
        private readonly string $basePath,
        private readonly ?DebugBar $debugBar = null,
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        if ($this->isIgnorable($severity)) {
            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(\Throwable $exception): void
    {
        $this->report($exception);

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
            fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
            exit(1);
        }

        $this->render(Request::createFromGlobals(), $exception)->send();
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        $this->handleException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        ));
    }

    public function report(\Throwable $exception): void
    {
        $exception = $this->normalize($exception);

        if (!$this->shouldReport($exception)) {
            return;
        }

        try {
            $this->hooks->doAction('http.error.report', $exception);
        } catch (\Throwable) {
            // reporting must not break the request
        }

        $this->writeLog($exception);
    }

    public function render(Request $request, \Throwable $exception): Response
    {
        $exception = $this->hooks->filter('http.error.prepare', $exception, $request);
        if (!$exception instanceof \Throwable) {
            $exception = new HttpException(500, 'errors.500.message');
        }

        $exception = $this->normalize($exception);
        $status = $this->status($exception);

        if ($status === 404) {
            $spa = Storefront::html($request);
            if ($spa !== null) {
                return $spa;
            }
        }

        $response = $this->wantsJson($request)
            ? $this->toJson($exception, $status)
            : $this->toHtml($request, $exception, $status);

        foreach ($this->headers($exception) as $name => $value) {
            $response->headers->set($name, $value);
        }

        $this->applySecurityHeaders($request, $response);

        if (PublicCors::isPublicApiPath($request->getPathInfo())) {
            PublicCors::apply($response, $request, 'GET, POST, OPTIONS');
        }

        $filtered = $this->hooks->filter('http.error.response', $response, $request, $exception);
        $response = $filtered instanceof Response ? $filtered : $response;

        if ($this->debugBar !== null && $this->debugBar->enabled()) {
            $this->debugBar->addException($exception);
            $response = $this->debugBar->inject($request, $response);
        }

        return $response;
    }

    public function decorate(Request $request, Response $response): Response
    {
        $this->applySecurityHeaders($request, $response);

        return $response;
    }

    public static function fallback(\Throwable $exception, bool $debug = false): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Connection: close');
        }

        echo self::fallbackHtml($exception, $debug);
    }

    private function normalize(\Throwable $exception): \Throwable
    {
        if ($exception instanceof ResourceNotFoundException) {
            return new NotFoundHttpException('', $exception);
        }

        if ($exception instanceof MethodNotAllowedException) {
            return new MethodNotAllowedHttpException($exception->getAllowedMethods(), '', $exception);
        }

        return $exception;
    }

    private function status(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->status();
        }

        return 500;
    }

    /** @return array<string, string> */
    private function headers(\Throwable $exception): array
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->headers();
        }

        return [];
    }

    private function shouldReport(\Throwable $exception): bool
    {
        return $this->status($exception) >= 500;
    }

    private function isIgnorable(int $severity): bool
    {
        return ($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) !== 0;
    }

    private function wantsJson(Request $request): bool
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function toJson(\Throwable $exception, int $status): JsonResponse
    {
        $fields = $exception instanceof ValidationException ? $exception->errors() : [];
        $payload = [
            'error' => [
                'code' => $this->errorCode($status),
                'message' => $this->publicMessage($exception, $status),
                'fields' => $fields,
            ],
        ];

        if ($this->debug() && $status >= 500) {
            $payload['error']['exception'] = $exception::class;
            $payload['error']['file'] = $exception->getFile() . ':' . $exception->getLine();
            $payload['error']['trace'] = explode("\n", $exception->getTraceAsString());
        }

        return new JsonResponse($payload, $status);
    }

    private function toHtml(Request $request, \Throwable $exception, int $status): Response
    {
        $page = self::PAGES[$status] ?? [
            'title' => 'errors.generic.title',
            'heading' => 'errors.generic.heading',
            'message' => 'errors.generic.message',
        ];
        $context = [
            'pageTitle' => $this->t($page['title']),
            'heading' => $this->t($page['heading']),
            'status' => $status,
            'message' => $this->publicMessage($exception, $status),
            'homeUrl' => $this->homeUrl($request),
            'debug' => $this->debug() && $status >= 500,
            'exception' => $exception,
        ];

        $templates = [
            'errors/' . $status . '.html.twig',
            'errors/generic.html.twig',
        ];

        foreach ($templates as $template) {
            try {
                if (!$this->twig->getLoader()->exists($template)) {
                    continue;
                }

                return new Response($this->twig->render($template, $context), $status);
            } catch (\Throwable) {
                continue;
            }
        }

        return new Response(self::fallbackHtml($exception, $this->debug()), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function publicMessage(\Throwable $exception, int $status): string
    {
        if ($exception instanceof HttpExceptionInterface && $exception->getMessage() !== '') {
            return $this->translateMaybe($exception->getMessage());
        }

        if ($this->debug() && $status >= 500 && $exception->getMessage() !== '') {
            return $exception->getMessage();
        }

        $page = self::PAGES[$status] ?? null;
        if ($page !== null) {
            return $this->t($page['message']);
        }

        return $this->t('errors.generic.message');
    }

    private function translateMaybe(string $message): string
    {
        if (
            str_starts_with($message, 'errors.')
            || str_starts_with($message, 'validation.')
            || str_starts_with($message, 'http.')
            || str_starts_with($message, 'admin.')
            || str_starts_with($message, 'auth.')
        ) {
            return $this->t($message);
        }

        return $message;
    }

    private function t(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_mismatch',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            503 => 'unavailable',
            default => 'server_error',
        };
    }

    private function homeUrl(Request $request): string
    {
        try {
            if ($this->router->has('admin.dashboard')) {
                return $this->router->url('admin.dashboard', [], $request);
            }
        } catch (\Throwable) {
        }

        return \Gereq\Support\UrlPrefix::path('/admin');
    }

    private function applySecurityHeaders(Request $request, Response $response): void
    {
        $nonce = (string) $request->attributes->get('csp_nonce', '');
        if ($nonce === '') {
            $nonce = $this->csp->nonce();
            $request->attributes->set('csp_nonce', $nonce);
        }

        $customCsp = $response->headers->get(ContentSecurityPolicy::HEADER_OVERRIDE);
        $response->headers->remove(ContentSecurityPolicy::HEADER_OVERRIDE);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            is_string($customCsp) && $customCsp !== '' ? $customCsp : $this->csp->header($nonce),
        );
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('Connection', 'close');
        $response->setProtocolVersion('1.1');
    }

    private function writeLog(\Throwable $exception): void
    {
        $dir = $this->basePath . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log($exception->getMessage());

            return;
        }

        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'),
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );

        @file_put_contents($dir . '/gereq.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    private static function fallbackHtml(\Throwable $exception, bool $debug): string
    {
        // Static fallback — translator may be unavailable during bootstrap failure.
        $message = $debug
            ? e($exception::class . ': ' . $exception->getMessage())
            : 'Unexpected error.';
        $trace = $debug ? '<pre>' . e($exception->getTraceAsString()) . '</pre>' : '';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error · GEREQ</title></head>'
            . '<body><h1>Something went wrong</h1><p>' . $message . '</p>' . $trace . '</body></html>';
    }
}
