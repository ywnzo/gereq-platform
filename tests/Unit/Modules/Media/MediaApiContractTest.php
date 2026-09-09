<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Media;

use Modules\Media\Domain\MediaAsset;
use Modules\Media\Http\Api\MediaApiController;
use Modules\Media\MediaModule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Kontrakt Media API bez HTTP: scopes, route metody, serialize tvar.
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
            self::assertContains($action, $methods, 'chybí MediaApiController::' . $action);
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

    public function testModuleRegistersApiRoutesAndCapability(): void
    {
        $module = new MediaModule();
        self::assertSame('media', $module->id());

        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/modules/Media/MediaModule.php');
        self::assertMatchesRegularExpression("/\\\$api->get\('\/'[^)]*\)->scope\('media\.read'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->post\('\/'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->get\('\/\{id\}'[^)]*\)->scope\('media\.read'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->patch\('\/\{id\}'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertMatchesRegularExpression("/\\\$api->delete\('\/\{id\}'[^)]*\)->scope\('media\.write'\)/", $source);
        self::assertStringContainsString('MediaProvider::class', $source);
    }
}
