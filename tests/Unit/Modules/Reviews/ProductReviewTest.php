<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Reviews;

use Modules\Reviews\Domain\ProductReview;
use PHPUnit\Framework\TestCase;

final class ProductReviewTest extends TestCase
{
    public function testApproveRejectAndHelpful(): void
    {
        $review = new ProductReview('prod-1', 'Jan', 5, 'Skvělé');
        self::assertSame('pending', $review->status());

        $review->approve();
        self::assertSame('approved', $review->status());

        $review->reject();
        self::assertSame('rejected', $review->status());

        $review->markHelpful();
        $review->markHelpful();
        self::assertSame(2, $review->helpfulCount());
    }

    public function testInvalidRatingRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProductReview('prod-1', 'Jan', 6, 'Nope');
    }
}
