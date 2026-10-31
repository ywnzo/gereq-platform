<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Gereq\Security\RateLimiter;
use Gereq\Setting\Settings;
use Gereq\Validation\ValidationRegistry;
use Gereq\Validation\ValidationException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Modules\Gereq\Application\GereqFormGuard;
use Modules\SiteInquiries\Application\InquiryIntake;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicInquiryController
{
    public function __construct(
        private readonly InquiryIntake $intake,
        private readonly RateLimiter $limiter,
        private readonly Settings $settings,
        private readonly ValidationRegistry $validation,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, POST, OPTIONS');
    }

    public function site(Request $request): Response
    {
        $payload = [
            'brand' => (string) $this->settings->getLive('brand.site_name', 'GEREQ'),
            'phone' => (string) $this->settings->getLive('site_inquiries.contact_phone', ''),
            'phone_tel' => (string) $this->settings->getLive('site_inquiries.contact_phone_tel', ''),
            'email' => (string) $this->settings->getLive('site_inquiries.contact_email', ''),
            'person' => (string) $this->settings->getLive('site_inquiries.contact_person', ''),
            'ico' => (string) $this->settings->getLive('site_inquiries.contact_ico', ''),
            'dic' => (string) $this->settings->getLive('site_inquiries.contact_dic', ''),
            'address' => (string) $this->settings->getLive(
                'site_inquiries.contact_address',
                '',
            ),
            'tagline' => (string) $this->settings->getLive(
                'site_inquiries.tagline',
                '',
            ),
        ];

        return PublicCors::apply(ApiResponse::data($payload), $request, 'GET, OPTIONS');
    }

    public function store(Request $request): Response
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        if (!$this->limiter->hit('site:inquiry:' . $ip, 5, 600)) {
            return $this->error($request, 'rate_limited', 429);
        }
        if (strlen($request->getContent()) > 65536) {
            return $this->error($request, 'invalid_request', 413);
        }
        $body = $this->body($request);
        if (count($body) > 50) {
            return $this->error($request, 'invalid_request', 400);
        }
        foreach ($body as $value) {
            if ($value !== null && !is_string($value)) {
                return $this->error($request, 'invalid_request', 400);
            }
        }

        if (class_exists(GereqFormGuard::class)) {
            $denied = GereqFormGuard::denyIfInvalid($request);
            if ($denied instanceof Response) {
                return $denied;
            }
        }

        // Honeypot — filled field means bot traffic.
        if (trim((string) ($body['website_url'] ?? $body['website'] ?? '')) !== '') {
            return PublicCors::apply(
                ApiResponse::data(['id' => 'ok', 'status' => 'received'], 201),
                $request,
                'GET, POST, OPTIONS',
            );
        }

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $phone = trim((string) ($body['phone'] ?? ''));
        $details = trim((string) ($body['details'] ?? $body['message'] ?? ''));
        $preferredDate = trim((string) ($body['date'] ?? $body['preferred_date'] ?? ''));
        $routeFrom = trim((string) ($body['from'] ?? $body['route_from'] ?? ''));
        $routeTo = trim((string) ($body['to'] ?? $body['route_to'] ?? ''));

        if ($email !== '' && !$this->limiter->hit('site:inquiry:email:' . $email, 3, 3600)) {
            return $this->error($request, 'rate_limited', 429);
        }
        try {
            $this->validation->validate('site_inquiries.public', [
                'name' => $name, 'email' => $email, 'phone' => $phone, 'details' => $details,
                'date' => $preferredDate, 'from' => $routeFrom, 'to' => $routeTo,
            ]);
        } catch (ValidationException $exception) {
            return PublicCors::apply(ApiResponse::error('validation_error', $this->translator->trans('site_inquiries.public.validation_error'), $exception->errors(), 422), $request, 'POST, OPTIONS');
        }

        $result = $this->intake->handle(
            $name,
            $email,
            $phone,
            $details,
            $preferredDate !== '' ? $preferredDate : null,
            $routeFrom !== '' ? $routeFrom : null,
            $routeTo !== '' ? $routeTo : null,
        );

        return PublicCors::apply(ApiResponse::data(['id' => $result['id'], 'status' => $result['status']], 201), $request, 'GET, POST, OPTIONS');
    }

    private function error(Request $request, string $code, int $status): Response
    {
        return PublicCors::apply(ApiResponse::error($code, $this->translator->trans('site_inquiries.public.' . $code), [], $status), $request, 'POST, OPTIONS');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $json = json_decode($request->getContent(), true);

        return is_array($json) ? $json : $request->request->all();
    }
}
