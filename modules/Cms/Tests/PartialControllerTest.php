<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Security\HtmlSanitizer;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Testing\TestCase;
use Modules\Cms\Domain\CmsPartial;
use Modules\Cms\Http\PartialController;
use Symfony\Component\HttpFoundation\Request;

final class PartialControllerTest extends TestCase
{
    private function controller(EntityManagerInterface $em): PartialController
    {
        $reflection = new \ReflectionClass(PartialController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('em')->setValue($controller, $em);
        $reflection->getProperty('html')->setValue($controller, new HtmlSanitizer());
        $reflection->getProperty('translator')->setValue($controller, new PassthroughTranslator());
        return $controller;
    }

    public function testUpdateChangesExistingLibraryRecordWithoutCreatingAnother(): void
    {
        $partial = new CmsPartial('Original', [['type' => 'text', 'payload' => ['body' => 'Before']]]);
        $id = $partial->id();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(CmsPartial::class, $id)->willReturn($partial);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');
        $request = new Request(content: json_encode([
            'name' => 'Updated',
            'description' => 'Reusable',
            'blocks' => [['type' => 'text', 'payload' => ['body' => '<p>After</p><script>bad</script>']]],
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller($em)->update($request, $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($id, $partial->id());
        self::assertSame('Updated', $partial->name());
        self::assertSame('Reusable', $partial->description());
        self::assertStringContainsString('<p>After</p>', $partial->blocks()[0]['payload']['body']);
        self::assertStringNotContainsString('<script>', $partial->blocks()[0]['payload']['body']);
    }

    public function testMissingRecordReturnsNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::never())->method('flush');
        self::assertSame(404, $this->controller($em)->update(new Request(), 'missing')->getStatusCode());
    }

    public function testInvalidUpdateLeavesSavedTemplateIntact(): void
    {
        $partial = new CmsPartial('Original', [['type' => 'text', 'payload' => ['body' => 'Before']]]);
        $before = $partial->toArray();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($partial);
        $em->expects(self::never())->method('flush');
        $request = new Request(content: '{"name":"Updated","blocks":[]}');
        self::assertSame(422, $this->controller($em)->update($request, $partial->id())->getStatusCode());
        self::assertSame($before, $partial->toArray());
    }
}
