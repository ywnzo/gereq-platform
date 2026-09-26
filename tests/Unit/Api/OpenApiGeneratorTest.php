<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\ApiRegistry;
use Gereq\Api\OpenApi\OpenApiGenerator;
use Gereq\Api\OpenApi\ValidationRulesToSchema;
use Gereq\Config\Repository;
use Gereq\Hook\HookManager;
use Gereq\Validation\ValidationRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpenApiGeneratorTest extends TestCase
{
    public function testInfersSummariesAndUsesRequestHost(): void
    {
        $api = new ApiRegistry(new Repository(['api' => ['prefix' => '/api/v1']]));
        $api->module('crm', static function ($module): void {
            $module->get('/deals', static fn () => null)->scope('crm.deals.read')->summary('List deals');
            $module->post('/deals', static fn () => null)->scope('crm.deals.write')->ruleset('crm.deal.save');
            $module->get('/deals/{id}', static fn () => null)->scope('crm.deals.read');
        });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $validation = new ValidationRegistry(new HookManager(), $translator);
        $validation->ruleset('crm.deal.save', [
            'title' => 'required|min:2',
            'stage' => 'required|in:lead,won',
        ]);

        $stack = new RequestStack();
        $stack->push(Request::create('https://realestates.local/api/openapi.json'));

        $doc = (new OpenApiGenerator(
            $api,
            new Repository(['api' => ['prefix' => '/api/v1'], 'app' => ['version' => '1.2.3', 'url' => '0.0.0.0:8083']]),
            $validation,
            $stack,
            new ValidationRulesToSchema(),
        ))->generate();

        self::assertSame('https://realestates.local', $doc['servers'][0]['url']);
        self::assertSame('List deals', $doc['paths']['/api/v1/crm/deals']['get']['summary']);
        self::assertSame('Create deal', $doc['paths']['/api/v1/crm/deals']['post']['summary']);
        self::assertSame('Get deal', $doc['paths']['/api/v1/crm/deals/{id}']['get']['summary']);
        self::assertArrayHasKey('requestBody', $doc['paths']['/api/v1/crm/deals']['post']);
        self::assertArrayHasKey('Crm_Deal_Save', (array) $doc['components']['schemas']);
        self::assertNotEmpty($doc['paths']['/api/v1/crm/deals']['get']['parameters']);
        self::assertSame(
            [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
            $doc['paths']['/api/v1/crm/deals/{id}']['get']['parameters'],
        );
    }
}
