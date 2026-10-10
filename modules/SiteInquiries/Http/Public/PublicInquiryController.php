<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Gereq\Security\RateLimiter;
use Gereq\Setting\Settings;
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
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, POST, OPTIONS');
    }

    public function site(Request $request): Response
    {
        $payload = [
            'brand' => 'Doprovody Vohralík',
            'phone' => (string) $this->settings->get('site_inquiries.contact_phone', '+420 704 797 610'),
            'phone_tel' => (string) $this->settings->get('site_inquiries.contact_phone_tel', '+420704797610'),
            'email' => (string) $this->settings->get('site_inquiries.contact_email', 'info@doprovodyvohralik.cz'),
            'person' => (string) $this->settings->get('site_inquiries.contact_person', 'Adam Vohralík'),
            'ico' => (string) $this->settings->get('site_inquiries.contact_ico', '22174915'),
            'dic' => (string) $this->settings->get('site_inquiries.contact_dic', ''),
            'address' => (string) $this->settings->get(
                'site_inquiries.contact_address',
                'Topolová 963, Mladá, 289 24 Milovice',
            ),
            'tagline' => (string) $this->settings->get(
                'site_inquiries.tagline',
                'Bezpečný partner pro nadrozměrné přepravy.',
            ),
        ];

        return PublicCors::apply(ApiResponse::data($payload), $request, 'GET, OPTIONS');
    }

    public function store(Request $request): Response
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $body = $this->body($request);

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

        if (!$this->limiter->hit('site:inquiry:' . $ip, 5, 600)) {
            return PublicCors::apply(
                ApiResponse::error('rate_limited', 'Příliš mnoho poptávek. Zkuste to později.', [], 429),
                $request,
                'GET, POST, OPTIONS',
            );
        }
        if ($email !== '' && !$this->limiter->hit('site:inquiry:email:' . $email, 3, 3600)) {
            return PublicCors::apply(
                ApiResponse::error('rate_limited', 'Příliš mnoho poptávek. Zkuste to později.', [], 429),
                $request,
                'GET, POST, OPTIONS',
            );
        }

        if (
            $name === ''
            || $email === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $phone === ''
            || $details === ''
        ) {
            return PublicCors::apply(
                ApiResponse::error('validation_error', 'Vyplňte jméno, telefon, e-mail a informace o přepravě.', [], 422),
                $request,
                'GET, POST, OPTIONS',
            );
        }

        if (
            mb_strlen($name) > 190
            || mb_strlen($phone) > 40
            || mb_strlen($details) > 5000
            || mb_strlen($routeFrom) > 190
            || mb_strlen($routeTo) > 190
            || mb_strlen($preferredDate) > 32
        ) {
            return PublicCors::apply(
                ApiResponse::error('validation_error', 'Neplatná délka polí.', [], 422),
                $request,
                'GET, POST, OPTIONS',
            );
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

        return PublicCors::apply(ApiResponse::data($result, 201), $request, 'GET, POST, OPTIONS');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $json = json_decode($request->getContent(), true);

        return is_array($json) ? $json : $request->request->all();
    }
}
