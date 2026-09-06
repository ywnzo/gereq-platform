<?php

declare(strict_types=1);

namespace Modules\Viewings\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Gereq\Validation\ValidationRegistry;
use Modules\Listings\Domain\Listing;
use Modules\Viewings\Application\ViewingsProvider;
use Modules\Viewings\Domain\Viewing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public storefront API for requesting a viewing. */
final class PublicViewingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ViewingsProvider $provider,
        private readonly EventBus $events,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204));
    }

    public function store(Request $request): JsonResponse
    {
        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        if (!$this->limiter->hit('viewing:request:' . $ip, 5, 600)) {
            return $this->cors(ApiResponse::error('rate_limited', 'Příliš mnoho žádostí. Zkuste to později.', [], 429));
        }

        $raw = json_decode($request->getContent(), true);
        $body = is_array($raw) ? $raw : $request->request->all();
        $data = gereq()->make(ValidationRegistry::class)->validate('viewings.public_request', $body);

        $listingId = trim((string) $data['listing_id']);
        $listing = $this->em->find(Listing::class, $listingId);
        if (!$listing instanceof Listing || $listing->status() !== 'published') {
            return $this->cors(ApiResponse::notFound());
        }

        $item = new Viewing(
            $this->provider->nextNumber(),
            $listingId,
            trim((string) $data['contact_name']),
        );
        $item->setStatus('requested');
        $item->setContactEmail(isset($data['contact_email']) ? (string) $data['contact_email'] : null);
        $item->setContactPhone(isset($data['contact_phone']) ? (string) $data['contact_phone'] : null);
        $item->setNote(isset($data['note']) ? (string) $data['note'] : null);
        $scheduled = trim((string) ($data['scheduled_at'] ?? ''));
        if ($scheduled !== '') {
            try {
                $item->setScheduledAt(new \DateTimeImmutable($scheduled));
            } catch (\Exception) {
                // ignore invalid preferred time
            }
        }
        if ($listing->brokerId() !== null) {
            $item->setBrokerId($listing->brokerId());
        }

        $this->em->persist($item);
        $this->em->flush();

        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.requested', ['type' => 'viewing', 'id' => $item->id()], $payload);
        $this->events->emit('viewing.created', ['type' => 'viewing', 'id' => $item->id()], $payload);

        return $this->cors(ApiResponse::data([
            'id' => $item->id(),
            'number' => $item->number(),
            'status' => $item->status(),
        ], 201));
    }

    private function cors(Response $response): Response
    {
        return PublicCors::apply($response, $this->request(), 'POST, OPTIONS');
    }
}
