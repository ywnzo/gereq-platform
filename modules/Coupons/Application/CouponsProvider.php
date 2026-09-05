<?php

declare(strict_types=1);

namespace Modules\Coupons\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Coupons\Domain\Coupon;

final class CouponsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?Coupon
    {
        $coupon = $this->em->find(Coupon::class, $id);

        return $coupon instanceof Coupon ? $coupon : null;
    }

    public function findByCode(string $code): ?Coupon
    {
        $coupon = $this->em->getRepository(Coupon::class)->findOneBy([
            'code' => strtoupper(trim($code)),
        ]);

        return $coupon instanceof Coupon ? $coupon : null;
    }

    /**
     * @return array{ok: bool, discount: string, message: string}
     */
    public function validateForCart(string $code, string|float|int $subtotal): array
    {
        $coupon = $this->findByCode($code);
        if ($coupon === null) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Coupon not found'];
        }

        if (!$coupon->isActive()) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Coupon is inactive'];
        }

        $now = new \DateTimeImmutable();
        if ($coupon->startsAt() !== null && $coupon->startsAt() > $now) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Coupon is not active yet'];
        }
        if ($coupon->endsAt() !== null && $coupon->endsAt() < $now) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Coupon has expired'];
        }

        if ($coupon->maxUses() !== null && $coupon->usedCount() >= $coupon->maxUses()) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Coupon usage limit reached'];
        }

        $subtotalAmount = (float) $subtotal;
        if ($coupon->minOrder() !== null && $subtotalAmount < (float) $coupon->minOrder()) {
            return ['ok' => false, 'discount' => '0.00', 'message' => 'Order subtotal below minimum'];
        }

        if ($coupon->type() === 'percent') {
            $discount = $subtotalAmount * ((float) $coupon->value() / 100);
        } else {
            $discount = (float) $coupon->value();
        }

        $discount = min($discount, max(0, $subtotalAmount));
        $discountFormatted = number_format($discount, 2, '.', '');

        return [
            'ok' => true,
            'discount' => $discountFormatted,
            'message' => 'Coupon applied',
        ];
    }
}
