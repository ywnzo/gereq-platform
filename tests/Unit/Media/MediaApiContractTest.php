<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Media;

use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Http\Api\MediaApiController;
use Gereq\Media\MediaRegistrar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Media API contract without HTTP: scopes, route methods, serialize shape.
 */
final class MediaApiContractTest extends TestCase
{
    public function testApiControllerPublicActionsExist(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(MediaApiController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            self::assertContains($action, $methods, 'missing MediaApiController::' . $action);
        }
    }

    public function testSerializeShapeMatchesApiContract(): void
    {
        $asset = new MediaAsset('Hero', 'seed/a.avif', 'image/avif', 100, 'u1', 'Alt', 10, 20);
        $controller = (new ReflectionClass(MediaApiController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MediaApiController::class, 'serialize');
        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($controller, $asset);

        foreach (['id', 'title', 'path', 'mime', 'size', 'alt', 'width', 'height', 'folder_id', 'uploaded_by', 'url', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $payload, $key);
        }
        self::assertSame('Hero', $payload['title']);
        self::assertSame('image/avif', $payload['mime']);
        self::assertSame('/media/' . $asset->id() . '/file', $payload['url']);
        self::assertSame(10, $payload['width']);
        self::assertNull($payload['folder_id']);
    }

    public function testRegistrarRegistersApiRoutesAndCapability(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Media/MediaRegistrar.php');
        self::assertSame(MediaRegistrar::class, MediaRegistrar::class);
        self::assertMatchesRegularExpression("/\\\$api->get\('\/'[^)]*\)->scope\('media\.read'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->post\('\/'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->get\('\/\{id\}'[^)]*\)->scope\('media\.read'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->patch\('\/\{id\}'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->delete\('\/\{id\}'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertStringContainsString('private readonly MediaProvider $provider', $source);
        self::assertStringContainsString("capabilities->register('media'", $source);
    }
}
