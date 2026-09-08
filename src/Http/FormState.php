<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FormState
{
    private const BAG = '_gereq_forms';
    private const LAST = '_gereq_form_last';

    /** @var list<string> */
    private const SENSITIVE = ['password', 'password_confirmation', 'current_password', '_token', '_csrf'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function fail(ValidationException $exception): void
    {
        $form = $exception->form() !== '' ? $exception->form() : 'default';
        $message = $exception->getMessage();
        if ($message === '' || $message === 'validation.form_failed') {
            $message = $this->translator->trans('validation.form_failed');
        } elseif (str_starts_with($message, 'validation.') || str_starts_with($message, 'errors.')) {
            $message = $this->translator->trans($message);
        }
        $this->store($form, $exception->errors(), $exception->input(), $message);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $input
     */
    public function store(string $form, array $errors, array $input, string $message = ''): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $bag = $session->get(self::BAG, []);
        if (!is_array($bag)) {
            $bag = [];
        }

        $bag[$form] = [
            'errors' => $errors,
            'input' => $this->scrub($input),
            'message' => $message !== '' ? $message : $this->translator->trans('validation.form_failed'),
            'at' => time(),
        ];
        $session->set(self::BAG, $bag);
        $session->set(self::LAST, $form);
    }

    public function forget(?string $form = null): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        if ($form === null) {
            $session->remove(self::BAG);
            $session->remove(self::LAST);

            return;
        }

        $bag = $session->get(self::BAG, []);
        if (is_array($bag)) {
            unset($bag[$form]);
            $session->set(self::BAG, $bag);
        }
        if ($session->get(self::LAST) === $form) {
            $session->remove(self::LAST);
        }
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
            $session->getFlashBag()->add('form_cleared', $form);
            $fp = $this->requestFingerprint();
            if ($fp !== null) {
                $session->getFlashBag()->add('form_fp_cleared', $fp);
            }
        }
    }

    /** Client-side submit fingerprint (`_gereq_fp`) for clearing matching sessionStorage draft. */
    private function requestFingerprint(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $raw = $request->request->get('_gereq_fp');
        if (!is_string($raw)) {
            return null;
        }

        $fp = trim($raw);
        if ($fp === '' || strlen($fp) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $fp)) {
            return null;
        }

        return $fp;
    }

    public function old(string $key, mixed $default = null, ?string $form = null): mixed
    {
        $state = $this->state($form);
        if ($state === null) {
            return $default;
        }

        $input = $state['input'] ?? [];
        if (!is_array($input) || !array_key_exists($key, $input)) {
            return $default;
        }

        return $input[$key];
    }

    public function error(string $key, ?string $form = null): ?string
    {
        $state = $this->state($form);
        $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];

        $message = $errors[$key] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /** @return array<string, string> */
    public function errors(?string $form = null): array
    {
        $state = $this->state($form);
        $errors = $state['errors'] ?? [];
        if (!is_array($errors)) {
            return [];
        }

        $normalized = [];
        foreach ($errors as $field => $message) {
            if (is_string($message) && $message !== '') {
                $normalized[(string) $field] = $message;
            }
        }

        return $normalized;
    }

    public function hasErrors(?string $form = null): bool
    {
        return $this->errors($form) !== [];
    }

    public function message(?string $form = null): ?string
    {
        $state = $this->state($form);
        $message = $state['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    public function respond(Request $request, ValidationException $exception): Response
    {
        $this->fail($exception);

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $exception->getMessage(),
                    'fields' => $exception->errors(),
                ],
            ], 422);
        }

        return new RedirectResponse($this->target($request, $exception), 303);
    }

    /** @return array{errors?: mixed, input?: mixed, message?: mixed, at?: mixed}|null */
    private function state(?string $form): ?array
    {
        $session = $this->session();
        if ($session === null) {
            return null;
        }

        $bag = $session->get(self::BAG, []);
        if (!is_array($bag)) {
            return null;
        }

        $key = $form ?? $session->get(self::LAST);
        if (!is_string($key) || $key === '' || !isset($bag[$key]) || !is_array($bag[$key])) {
            return null;
        }

        $at = (int) ($bag[$key]['at'] ?? 0);
        if ($at > 0 && (time() - $at) > 7200) {
            unset($bag[$key]);
            $session->set(self::BAG, $bag);

            return null;
        }

        return $bag[$key];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function scrub(array $input): array
    {
        foreach (self::SENSITIVE as $key) {
            unset($input[$key]);
        }

        foreach ($input as $key => $_value) {
            if (str_contains(strtolower((string) $key), 'password')) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    private function target(Request $request, ValidationException $exception): string
    {
        $candidates = [
            $exception->redirectTo(),
            $request->headers->get('Referer'),
            $request->getRequestUri(),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $path = $this->safePath($request, $candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return '/';
    }

    private function safePath(Request $request, string $url): ?string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['path'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $expected = strtolower($request->getHost());
        if ($host !== '' && $host !== $expected) {
            return null;
        }

        $path = (string) $parts['path'];
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return $path !== '' ? $path : null;
    }

    private function wantsJson(Request $request): bool
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
