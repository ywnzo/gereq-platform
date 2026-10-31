<?php

declare(strict_types=1);

namespace Modules\Reviews\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Testing\TestCase;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Domain\ProductReview;

final class ReviewsCmsTest extends TestCase
{
    public function testCmsInitialsSupportCzechNamesWithoutExposingPrivateFields(): void
    {
        $provider = new ReviewsProvider($this->createMock(EntityManagerInterface::class));
        $review = new ProductReview('site', 'Jiří Grygerek', 5, 'Skvělá práce.');
        $data = $provider->serializeCms($review);
        self::assertSame('JG', $data['author_initials']);
        self::assertSame('Jiří Grygerek', $data['author_name']);
        self::assertArrayNotHasKey('author_email', $data);
        self::assertSame('M', $provider->serializeCms(new ProductReview('site', 'Market', 5, 'Výborné.'))['author_initials']);
    }
}
