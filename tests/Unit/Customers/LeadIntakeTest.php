<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Customers;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Customers\Application\LeadIntake;
use Gereq\Customers\Domain\Customer;
use Gereq\Testing\TestCase;

final class LeadIntakeTest extends TestCase
{
    public function testPublicIntakeLinksExistingCustomersWithoutChangingTheirData(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Customers/Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Customer::class)]);
        $existing = new Customer('Existing customer', 'existing@example.test');
        $existing->setStatus('inactive');
        $existing->setPhone('original-phone');
        $existing->setNote('original-note');
        $em->persist($existing);
        $em->flush();
        $service = new LeadIntake($em);
        self::assertSame($existing->id(), $service->findOrCreate('Unverified sender', 'EXISTING@example.test', 'changed-phone', 'changed-note'));
        $em->refresh($existing);
        self::assertSame('inactive', $existing->status());
        self::assertSame('original-phone', $existing->phone());
        self::assertSame('original-note', $existing->note());
        $newId = $service->findOrCreate('New sender', 'new@example.test', 'new-phone', 'New inquiry');
        self::assertSame('lead', $em->find(Customer::class, $newId)->status());
        self::assertSame(2, $em->getRepository(Customer::class)->count([]));
    }
}
