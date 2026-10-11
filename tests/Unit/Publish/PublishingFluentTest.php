<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Publish;

use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\Publishing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublishingFluentTest extends TestCase
{
    public function testFluentRegistrationResolvesAdapterFromContainer(): void
    {
        $registry = new PublishableRegistry();
        $adapter = new class implements PublishableAdapterInterface {
            public function settingsKeys(): array
            {
                return [];
            }

            public function snapshot(array $resourceKeys): array
            {
                return [];
            }

            public function apply(ContentStagingEntry $entry): void
            {
            }

            public function discard(ContentStagingEntry $entry): void
            {
            }

            public function restore(string $resourceKey, ?array $snapshot): void
            {
            }

            public function describe(ContentStagingEntry $entry): ChangeCard
            {
                return new ChangeCard('x');
            }
        };

        $container = new class ($adapter) implements ContainerInterface {
            public function __construct(private readonly object $adapter)
            {
            }

            public function get(string $id): mixed
            {
                return $this->adapter;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'L:' . $id);

        $publishing = new Publishing($registry, $container, $translator, 'listings');
        $publishing
            ->category('listings')
            ->labelKey('publish.category.listings')
            ->sort(40)
            ->adapter($adapter::class);

        self::assertTrue($registry->has('listings'));
        $definition = $registry->get('listings');
        self::assertSame('L:publish.category.listings', $definition->label);
        self::assertSame('listings', $definition->module);
        self::assertSame(40, $definition->sort);
        self::assertSame($adapter, $definition->adapter);
    }
}
