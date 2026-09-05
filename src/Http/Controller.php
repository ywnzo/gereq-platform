<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Validation\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

abstract class Controller
{
    public function __construct(
        protected readonly Environment $twig,
        protected readonly Router $router,
        protected readonly RequestStack $requestStack,
    ) {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $view, array $data = [], int $status = 200): Response
    {
        return new Response($this->twig->render($view, $data), $status);
    }

    /** @param array<string, string> $headers */
    protected function abort(int $status, string $message = '', array $headers = []): never
    {
        \abort($status, $message, $headers);
    }

    protected function redirect(string $route, array $params = []): RedirectResponse
    {
        return new RedirectResponse($this->router->url($route, $params, $this->request()));
    }

    protected function redirectTo(string $url): RedirectResponse
    {
        return new RedirectResponse($url);
    }

    /**
     * Prefer Referer when present; otherwise fall back to a named route.
     *
     * @param array<string, string|int> $params
     */
    protected function redirectBack(string $fallbackRoute, array $params = []): RedirectResponse
    {
        $referer = (string) ($this->request()?->headers->get('Referer') ?? '');
        if ($referer !== '') {
            return $this->redirectTo($referer);
        }

        return $this->redirect($fallbackRoute, $params);
    }

    protected function wantsJson(?Request $request = null): bool
    {
        $request ??= $this->request();
        if ($request === null) {
            return false;
        }

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    /**
     * Response for row toggles (publish / active / featured): JSON for AJAX, redirect otherwise.
     *
     * @param array<string, string|int> $params
     */
    protected function toggleResponse(bool $on, string $fallbackRoute, array $params = []): Response
    {
        if ($this->wantsJson()) {
            return $this->json(['on' => $on]);
        }

        return $this->redirectBack($fallbackRoute, $params);
    }

    protected function flash(string $type, string $message): void
    {
        $session = $this->request()?->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    protected function request(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data['data'] ?? $data, 'meta' => $data['meta'] ?? []], $status);
    }

    /** @param array<string, mixed> $fields */
    protected function jsonError(string $code, string $message, array $fields = [], int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $fields,
            ],
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function validate(Request $request, string|array $rules, array $context = []): array
    {
        return $this->validator()->validateRequest($request, $rules, $context);
    }

    protected function forgetForm(?string $form = null): void
    {
        $this->formState()->forget($form);
    }

    protected function formState(): FormState
    {
        return gereq()->make(FormState::class);
    }

    protected function validator(): Validator
    {
        return gereq()->make(Validator::class);
    }
}
