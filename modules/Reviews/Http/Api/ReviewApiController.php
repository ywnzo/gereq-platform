<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Domain\ProductReview;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ReviewApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly ReviewsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function forProduct(string $productId): JsonResponse
    {
        $items = $this->provider->forProduct($productId, true);
        $avg = $this->provider->averageRating($productId);

        return ApiResponse::collection(
            array_map(fn (ProductReview $r): array => $this->ser($r), $items),
            ['total' => count($items), 'average_rating' => $avg, 'product_id' => $productId],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('reviews.create', $this->body($request));
        $rating = (int) ($data['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ApiResponse::error('validation_error', 'Rating must be 1–5.', ['rating' => 'invalid'], 422);
        }

        $review = new ProductReview(
            trim((string) $data['product_id']),
            trim((string) $data['author_name']),
            $rating,
            trim((string) $data['body']),
        );
        if (!empty($data['customer_id'])) {
            $review->setCustomerId((string) $data['customer_id']);
        }
        if (!empty($data['author_email'])) {
            $review->setAuthorEmail((string) $data['author_email']);
        }
        if (!empty($data['title'])) {
            $review->setTitle((string) $data['title']);
        }
        $this->em->persist($review);
        $this->em->flush();
        $payload = $this->ser($review);
        $this->events->emit('review.created', ['type' => 'product_review', 'id' => $review->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function moderate(Request $request, string $id): JsonResponse
    {
        $review = $this->provider->find($id);
        if ($review === null) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('reviews.moderate', $this->body($request));
        $status = (string) $data['status'];
        $review->setStatus($status);
        $this->em->flush();
        $payload = $this->ser($review);
        if ($status === 'approved') {
            $this->events->emit('review.approved', ['type' => 'product_review', 'id' => $id], $payload);
        } elseif ($status === 'rejected') {
            $this->events->emit('review.rejected', ['type' => 'product_review', 'id' => $id], $payload);
        }

        return ApiResponse::data($payload);
    }

    /** @return array<string, mixed> */
    private function ser(ProductReview $r): array
    {
        return [
            'id' => $r->id(),
            'product_id' => $r->productId(),
            'customer_id' => $r->customerId(),
            'author_name' => $r->authorName(),
            'author_email' => $r->authorEmail(),
            'rating' => $r->rating(),
            'title' => $r->title(),
            'body' => $r->body(),
            'status' => $r->status(),
            'created_at' => $r->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->updatedAt()->format(\DateTimeInterface::ATOM),
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
