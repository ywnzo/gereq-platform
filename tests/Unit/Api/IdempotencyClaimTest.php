<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Api\Entity\IdempotencyRecord;
use Gereq\Api\IdempotencyService;
use Gereq\Testing\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class IdempotencyClaimTest extends TestCase
{
    public function testDatabaseClaimRejectsAnAlreadyCommittedKeyWithoutAPreflightRead(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Api/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(IdempotencyRecord::class)]);
        $service = new IdempotencyService($em);
        $request = Request::create('/api/public/pages/contact/forms/form', 'POST', [], [], [], [], '{}');
        $response = new JsonResponse(['data' => ['status' => 'received']], 201);
        $em->getConnection()->transactional(static fn () => $service->claim('same-key', $request, $response));
        try {
            $em->getConnection()->transactional(static fn () => $service->claim('same-key', $request, $response));
            self::fail('A second worker must not pass the database claim.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $em->getRepository(IdempotencyRecord::class)->count([]));
        }
        $this->expectException(\LogicException::class);
        $service->claim('outside-transaction', $request, $response);
    }
}
