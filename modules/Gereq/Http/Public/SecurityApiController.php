<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Public;

use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Modules\Gereq\Application\Security\BotScoreService;
use Modules\Gereq\Application\Security\Captcha;
use Modules\Gereq\Application\Security\FormPassService;
use Modules\Gereq\Application\Security\SecuritySiteService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Gereq\Http\Exception\HttpException;
use Twig\Environment;

final class SecurityApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly SecuritySiteService $sites,
        private readonly BotScoreService $bots,
        private readonly FormPassService $passes,
        private readonly Captcha $captcha,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(Request $request): Response
    {
        $origin = $this->claimedOrigin($request) ?? '';
        $response = new Response('', 204);
        if ($origin === '') {
            return $response;
        }
        $key = (string) $request->query->get('key', $request->request->get('key', ''));
        try {
            if ($key !== '') {
                $site = $this->sites->findActiveSiteByPublicKey($key);
                $this->sites->assertOrigin($site, $origin, $request);
                $response->headers->add($this->sites->corsHeadersForSite($site, $origin));
            } elseif ($this->sites->originAllowedByAnyActiveSite($origin)) {
                $response->headers->add($this->sites->corsHeaders($origin));
            }
        } catch (HttpException) {
            // empty 204
        }

        return $response;
    }

    public function challenge(Request $request): Response
    {
        if (!$this->limiter->hit('gereq-sec-challenge:' . (string) $request->getClientIp(), 30, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $key = (string) $request->query->get('key', $request->request->get('key', ''));
            $origin = $this->claimedOrigin($request);
            $site = $this->sites->findActiveSiteByPublicKey($key);
            if (!$site->formEnabled()) {
                throw new HttpException(403, 'Ochrana formulářů není aktivní.');
            }
            $this->sites->assertOrigin($site, $origin, $request);
            if (!$site->usingTestKey() && $this->bots->isBanned($site, $this->sites->visitorHash($request), (string) $request->getClientIp())) {
                throw new HttpException(403, 'Odeslání je dočasně zablokováno.');
            }
            $challenge = $this->captcha->create($site->captchaDifficulty(), $site->id(), true);
            $response = $this->rawJson([
                'kind' => $challenge['kind'],
                'token' => $challenge['token'],
                'image' => $challenge['image'],
                'badge' => $site->badgeLabel(),
                'color' => $site->primaryColor(),
                'difficulty' => $site->captchaDifficulty(),
                'key_mode' => $site->usingTestKey() ? 'test' : 'live',
            ]);
            $response->headers->add($this->sites->corsHeadersForSite($site, $origin));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function assess(Request $request): Response
    {
        if (!$this->limiter->hit('gereq-sec-assess:' . (string) $request->getClientIp(), 90, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $payload = $this->jsonBody($request);
            $key = (string) ($payload['key'] ?? $request->request->get('key', ''));
            $origin = $this->claimedOrigin($request);
            $site = $this->sites->findActiveSiteByPublicKey($key);
            if (!$site->formEnabled()) {
                throw new HttpException(403, 'Ochrana formulářů není aktivní.');
            }
            $this->sites->assertOrigin($site, $origin, $request);

            if ($site->usingTestKey()) {
                $response = $this->rawJson([
                    'ok' => true,
                    'action' => 'allow',
                    'visitor' => $this->sites->visitorHash($request),
                    'mode' => $site->protectionMode(),
                    'badge' => $site->badgeLabel(),
                    'form_captcha_always' => $site->formCaptchaAlways(),
                    'pass_token' => ($site->formCaptchaAlways() ? null : 'fp_test_' . bin2hex(random_bytes(16))),
                    'key_mode' => 'test',
                ]);
                $response->headers->add($this->sites->corsHeadersForSite($site, $origin));

                return $response;
            }

            if ($this->bots->isBanned($site, $this->sites->visitorHash($request), (string) $request->getClientIp())) {
                throw new HttpException(403, 'Odeslání je dočasně zablokováno.');
            }

            /** @var array<string, mixed> $signals */
            $signals = is_array($payload['signals'] ?? null) ? $payload['signals'] : [];
            $result = $this->bots->evaluate($site, $request, $signals, true);
            if ($site->formCaptchaAlways() && $result['action'] === 'allow') {
                $result['action'] = 'challenge';
            }
            $path = is_string($payload['path'] ?? null) ? $payload['path'] : null;
            $this->bots->logEvent($site, $request, $result, $path);

            $out = [
                'ok' => true,
                'action' => $result['action'],
                'visitor' => $result['visitor'],
                'mode' => $site->protectionMode(),
                'badge' => $site->badgeLabel(),
                'form_captcha_always' => $site->formCaptchaAlways(),
                'pass_token' => null,
                'key_mode' => 'live',
            ];
            if ($result['action'] === 'allow') {
                $pass = $this->passes->issue($site, $result['visitor'], $request);
                if ($pass === null) {
                    $out['action'] = 'challenge';
                } else {
                    $out['pass_token'] = $pass;
                }
            }
            $response = $this->rawJson($out);
            $response->headers->add($this->sites->corsHeadersForSite($site, $origin));

            return $response;
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function verify(Request $request): Response
    {
        if (!$this->limiter->hit('gereq-sec-verify:' . (string) $request->getClientIp(), 60, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $payload = $this->jsonBody($request);
            $secret = (string) ($payload['secret'] ?? '');
            $site = $this->sites->findActiveSiteBySecret($secret);
            if (!$site->formEnabled()) {
                throw new HttpException(403, 'Ochrana formulářů není aktivní.');
            }
            $visitor = is_string($payload['visitor'] ?? null) ? $payload['visitor'] : '';
            $clientIp = is_string($payload['client_ip'] ?? null) ? trim($payload['client_ip']) : '';
            if ($visitor === '') {
                return $this->rawJson(['ok' => false, 'error' => 'Chybí identifikátor návštěvníka.'], 422);
            }
            if (!$site->usingTestKey() && $this->bots->isBanned($site, $visitor, $clientIp !== '' ? $clientIp : null)) {
                return $this->rawJson(['ok' => false, 'error' => 'Odeslání se nepodařilo ověřit.'], 403);
            }
            if (!empty($payload['website_url'])) {
                return $this->rawJson(['ok' => false, 'error' => 'Odeslání se nepodařilo ověřit.'], 422);
            }
            $passToken = (string) ($payload['pass_token'] ?? $payload['gereq_pass_token'] ?? '');
            if ($passToken !== '' && $this->passes->consume($site, $visitor, $passToken, $clientIp !== '' ? $clientIp : null)) {
                return $this->rawJson(['ok' => true, 'via' => 'pass', 'key_mode' => $site->usingTestKey() ? 'test' : 'live']);
            }
            $result = $this->captcha->verify($payload['captcha_token'] ?? null, $payload['captcha_answer'] ?? null, $site->id());
            if (($result['ok'] ?? false) !== true) {
                return $this->rawJson(['ok' => false, 'error' => $result['error'] ?? 'Špatný kód.'], 422);
            }

            return $this->rawJson(['ok' => true, 'via' => 'captcha', 'key_mode' => $site->usingTestKey() ? 'test' : 'live']);
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function beacon(Request $request): Response
    {
        if (!$this->limiter->hit('gereq-sec-beacon:' . (string) $request->getClientIp(), 90, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $payload = $this->jsonBody($request);
            $key = (string) ($payload['key'] ?? '');
            $origin = $this->claimedOrigin($request);
            $site = $this->sites->findActiveSiteByPublicKey($key);
            if (!$site->monitorEnabled()) {
                throw new HttpException(403, 'Monitoring není aktivní.');
            }
            $this->sites->assertOrigin($site, $origin, $request);
            if ($site->usingTestKey()) {
                $response = $this->rawJson([
                    'ok' => true,
                    'action' => 'allow',
                    'visitor' => $this->sites->visitorHash($request),
                    'mode' => $site->protectionMode(),
                    'key_mode' => 'test',
                ]);
                $response->headers->add($this->sites->corsHeadersForSite($site, $origin));

                return $response;
            }
            /** @var array<string, mixed> $signals */
            $signals = is_array($payload['signals'] ?? null) ? $payload['signals'] : [];
            $result = $this->bots->evaluate($site, $request, $signals);
            $path = is_string($payload['path'] ?? null) ? $payload['path'] : null;
            $this->bots->logEvent($site, $request, $result, $path);
            $response = $this->rawJson([
                'ok' => true,
                'action' => $result['action'],
                'visitor' => $result['visitor'],
                'mode' => $site->protectionMode(),
                'key_mode' => 'live',
            ]);
            $response->headers->add($this->sites->corsHeadersForSite($site, $origin));

            return $response;
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    private function claimedOrigin(Request $request): ?string
    {
        $fromQuery = $request->query->get('origin');
        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }
        $payload = $this->jsonBody($request);
        if (is_string($payload['origin'] ?? null)) {
            return $payload['origin'];
        }

        return $this->sites->requestOrigin($request);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /** @param array<string, mixed> $payload */
    private function rawJson(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status);
    }

    private function rawError(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
