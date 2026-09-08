<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Modules\CRM\Domain\Deal;
use Modules\Customers\Domain\Customer;
use Modules\Listings\Application\ListingsProvider;
use Modules\Listings\Domain\Inquiry;
use Modules\Listings\Domain\Listing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public storefront API (no Bearer token) for the RealEstates FE. */
final class PublicListingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ListingsProvider $provider,
        private readonly EventBus $events,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'city' => trim((string) $request->query->get('city', '')),
            'offer_type' => trim((string) $request->query->get('offer_type', '')),
            'property_type' => trim((string) $request->query->get('property_type', '')),
            'market_status' => trim((string) $request->query->get('market_status', '')),
            'featured' => $request->query->has('featured') ? filter_var($request->query->get('featured'), FILTER_VALIDATE_BOOLEAN) : null,
            'min_price' => $request->query->get('min_price') !== null && $request->query->get('min_price') !== ''
                ? (float) $request->query->get('min_price') : null,
            'max_price' => $request->query->get('max_price') !== null && $request->query->get('max_price') !== ''
                ? (float) $request->query->get('max_price') : null,
            'min_rooms' => $request->query->get('min_rooms') !== null && $request->query->get('min_rooms') !== ''
                ? (int) $request->query->get('min_rooms') : null,
            'min_area' => $request->query->get('min_area') !== null && $request->query->get('min_area') !== ''
                ? (float) $request->query->get('min_area') : null,
        ];
        $limit = (int) $request->query->get('limit', 24);
        $items = $this->provider->published($filters, $limit);

        return $this->cors(ApiResponse::collection(
            array_map(fn (Listing $l): array => $this->provider->serialize($l), $items),
            ['total' => count($items)],
        ));
    }

    public function show(string $slug): JsonResponse
    {
        $listing = $this->provider->findBySlug($slug);
        if ($listing === null) {
            return $this->cors(ApiResponse::notFound());
        }

        $data = $this->provider->serialize($listing, true);
        if (($data['status'] ?? '') !== 'published') {
            return $this->cors(ApiResponse::notFound());
        }
        $data['similar'] = array_map(
            fn (Listing $l): array => $this->provider->serialize($l),
            $this->provider->similar($listing, 3),
        );

        return $this->cors(ApiResponse::data($data));
    }

    public function inquire(Request $request): JsonResponse
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        if (!$this->limiter->hit('listing:inquiry:' . $ip, 5, 600)) {
            return $this->cors(ApiResponse::error('rate_limited', 'Příliš mnoho poptávek. Zkuste to později.', [], 429));
        }

        $body = $this->body($request);
        $slug = trim((string) ($body['listing_slug'] ?? $body['slug'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $message = trim((string) ($body['message'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));

        if ($slug === '' || $name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            return $this->cors(ApiResponse::error('validation_error', 'Vyplňte jméno, e-mail, zprávu a nabídku.', [], 422));
        }

        $listing = $this->provider->findBySlug($slug);
        if ($listing === null || $listing->status() !== 'published') {
            return $this->cors(ApiResponse::notFound('listing_not_found', 'Nabídka neexistuje.'));
        }

        $inquiry = new Inquiry($listing->id(), $name, $email, $message);
        if ($phone !== '') {
            $inquiry->setPhone($phone);
        }
        $this->em->persist($inquiry);

        $customerId = $this->ensureCustomer($name, $email, $phone !== '' ? $phone : null, $listing);
        $this->ensureDeal($customerId, $listing, $message);

        $this->em->flush();

        $payload = [
            'id' => $inquiry->id(),
            'listing_id' => $listing->id(),
            'listing_slug' => $listing->slug(),
        ];
        $this->events->emit('listing.inquiry', ['type' => 'listing_inquiry', 'id' => $inquiry->id()], $payload);

        return $this->cors(ApiResponse::data($payload, 201));
    }

    private function ensureCustomer(string $name, string $email, ?string $phone, Listing $listing): string
    {
        if (!class_exists(Customer::class)) {
            return '';
        }

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if (!$customer instanceof Customer) {
            $customer = new Customer($name, $email);
            if ($phone !== null) {
                $customer->setPhone($phone);
            }
            $customer->setNote('Poptávka z webu: ' . $listing->title());
            $this->em->persist($customer);
        }

        return $customer->id();
    }

    private function ensureDeal(string $customerId, Listing $listing, string $message): void
    {
        if ($customerId === '' || !class_exists(Deal::class)) {
            return;
        }

        $deal = new Deal($customerId, 'Poptávka: ' . $listing->title());
        $deal->setAmount($listing->price());
        $deal->setCurrency($listing->currency());
        $deal->setNote($message);
        $this->em->persist($deal);
    }

    private function cors(Response $response): Response
    {
        return PublicCors::apply($response, $this->request(), 'GET, POST, OPTIONS');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $json = json_decode($request->getContent(), true);

        return is_array($json) ? $json : $request->request->all();
    }
}
