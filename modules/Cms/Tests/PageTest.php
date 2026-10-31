<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Modules\Cms\Domain\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testSlugNormalized(): void
    {
        $page = new Page('Hello World!!', 'Hello');
        self::assertSame('hello-world', $page->slug());
        $page->setSlug('@@@');
        self::assertSame('page', $page->slug());
    }

    public function testPublishSetsPublishedAt(): void
    {
        $page = new Page('about', 'About');
        self::assertFalse($page->isPublished());
        self::assertNull($page->publishedAt());

        $page->setStatus('published');
        self::assertTrue($page->isPublished());
        self::assertInstanceOf(\DateTimeImmutable::class, $page->publishedAt());
    }

    public function testInvalidStatusThrows(): void
    {
        $page = new Page('about', 'About');
        $this->expectException(\InvalidArgumentException::class);
        $page->setStatus('nope');
    }

    public function testInvalidTemplateThrows(): void
    {
        $page = new Page('about', 'About');
        $this->expectException(\InvalidArgumentException::class);
        $page->setTemplate('wizard');
    }
}
