<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Newsletter\Application\NewsletterProvider;
use Modules\Newsletter\Domain\Subscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class NewsletterApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EventBus $events,
        private readonly NewsletterProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('newsletter.subscribe', $this->body($request));
        $subscriber = $this->provider->subscribe(
            trim((string) $data['email']),
            isset($data['name']) ? trim((string) $data['name']) ?: null : null,
            isset($data['source']) ? trim((string) $data['source']) ?: null : 'api',
        );
        $payload = $this->ser($subscriber);
        $this->events->emit('newsletter.subscribed', ['type' => 'newsletter_subscriber', 'id' => $subscriber->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('newsletter.unsubscribe', $this->body($request));
        $subscriber = $this->provider->unsubscribe(trim((string) $data['email']));
        if ($subscriber === null) {
            return ApiResponse::notFound();
        }
        $payload = $this->ser($subscriber);
        $this->events->emit('newsletter.unsubscribed', ['type' => 'newsletter_subscriber', 'id' => $subscriber->id()], $payload);

        return ApiResponse::data($payload);
    }

    /** @return array<string, mixed> */
    private function ser(Subscriber $s): array
    {
        return [
            'id' => $s->id(),
            'email' => $s->email(),
            'name' => $s->name(),
            'status' => $s->status(),
            'source' => $s->source(),
            'subscribed_at' => $s->subscribedAt()->format(\DateTimeInterface::ATOM),
            'unsubscribed_at' => $s->unsubscribedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $s->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function body(Request $r): array
    {
        $d = $r->request->all();
        $raw = $r->getContent();
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return array_merge($d, $j);
            }
        }

        return $d;
    }
}
