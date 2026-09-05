<?php

declare(strict_types=1);

namespace Modules\Loyalty\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Loyalty\Application\LoyaltyProvider;
use Modules\Loyalty\Domain\LoyaltyAccount;
use Modules\Loyalty\Domain\LoyaltyTier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class LoyaltyApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly LoyaltyProvider $loyalty,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function tiers(): JsonResponse
    {
        /** @var list<LoyaltyTier> $tiers */
        $tiers = $this->em->getRepository(LoyaltyTier::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'minSpend' => 'ASC'],
        );

        return ApiResponse::collection(array_map(fn (LoyaltyTier $t): array => $this->serializeTier($t), $tiers));
    }

    public function account(string $customerId): JsonResponse
    {
        $account = $this->loyalty->accountFor($customerId);

        return ApiResponse::data($this->serializeAccount($account));
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'loyalty.account.adjust');
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        $accountId = trim((string) ($data['account_id'] ?? ''));
        $delta = (int) ($data['delta'] ?? 0);

        if ($delta === 0) {
            return ApiResponse::error('validation_error', 'Delta required', ['delta' => 'required'], 422);
        }

        if ($accountId !== '') {
            $account = $this->em->find(LoyaltyAccount::class, $accountId);
            if (!$account instanceof LoyaltyAccount) {
                return ApiResponse::notFound();
            }
        } elseif ($customerId !== '') {
            $account = $this->loyalty->accountFor($customerId);
        } else {
            return ApiResponse::error('validation_error', 'customer_id or account_id required', [
                'customer_id' => 'required',
            ], 422);
        }

        $before = $account->points();
        $account->adjustPoints($delta);
        $this->em->flush();

        $payload = $this->serializeAccount($account);
        $payload['delta'] = $delta;
        $payload['points_before'] = $before;
        $this->events->emit('loyalty.points_adjusted', ['type' => 'loyalty_account', 'id' => $account->id()], $payload);

        return ApiResponse::data($payload);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeTier(LoyaltyTier $tier): array
    {
        return [
            'id' => $tier->id(),
            'code' => $tier->code(),
            'name' => $tier->name(),
            'min_spend' => $tier->minSpend(),
            'discount_percent' => $tier->discountPercent(),
            'sort_order' => $tier->sortOrder(),
            'active' => $tier->isActive(),
            'created_at' => $tier->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAccount(LoyaltyAccount $account): array
    {
        $tier = null;
        if ($account->tierId() !== null) {
            $found = $this->em->find(LoyaltyTier::class, $account->tierId());
            if ($found instanceof LoyaltyTier) {
                $tier = $this->serializeTier($found);
            }
        }

        return [
            'id' => $account->id(),
            'customer_id' => $account->customerId(),
            'tier_id' => $account->tierId(),
            'tier' => $tier,
            'points' => $account->points(),
            'lifetime_spend' => $account->lifetimeSpend(),
            'created_at' => $account->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $account->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
