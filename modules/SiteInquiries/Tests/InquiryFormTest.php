<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Tests;

use Gereq\Testing\TestCase;
use Modules\SiteInquiries\Application\InquiryIntake;

final class InquiryFormTest extends TestCase
{
    public function testRemovingAnInquiryCleansItsAttachmentsInsideTheDeleteTransaction(): void
    {
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(\Modules\SiteInquiries\Domain\Inquiry::class)]);
        $cms = new \Gereq\Cms\Cms(self::createStub(\Gereq\Module\EnabledModulesProvider::class));
        $storage = self::createMock(\Gereq\Cms\FormAttachmentStorage::class);
        $cms->registerAttachmentStorage($storage);
        $inquiry = new \Modules\SiteInquiries\Domain\Inquiry('Test', 'test@example.test', '123', 'Details');
        $em->persist($inquiry);
        $em->flush();
        $storage->expects(self::once())->method('removeForRecord')->with('site_inquiries.create', $inquiry->id())->willReturnCallback(static function () use ($em): void {
            self::assertTrue($em->getConnection()->isTransactionActive());
        });
        $em->getEventManager()->addEventListener(['postRemove'], new \Modules\SiteInquiries\Application\InquiryAttachmentCleanup($cms));
        $em->remove($inquiry);
        $em->flush();
        self::assertSame(0, $em->getRepository(\Modules\SiteInquiries\Domain\Inquiry::class)->count([]));
    }

    public function testCustomFormFieldsArePreservedInInquiryDetails(): void
    {
        self::assertSame("Please call me.\n\nservice: Delivery\n\ncompany: Example", InquiryIntake::formDetails([
            'name' => 'Alice', 'email' => 'a@example.test', 'phone' => '123', 'details' => 'Please call me.',
            'date' => '2026-10-25', 'from' => 'Prague', 'to' => 'Brno', 'service' => 'Delivery', 'company' => 'Example', 'optional' => '',
        ]));
    }

    public function testAnExistingInquiryPayloadIsUnchanged(): void
    {
        self::assertSame('Existing details', InquiryIntake::formDetails(['details' => 'Existing details', 'name' => 'Alice']));
    }
    /** @return iterable<string, array{string}> */
    public static function deliveryScenarios(): iterable
    {
        foreach (['not_configured', 'sent', 'failed'] as $status) {
            yield $status => [$status];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryScenarios')]
    public function testInquiryIntakeStoresCustomDetailsAndReportsNotificationStatus(string $delivery): void
    {
        $root = dirname(__DIR__, 3);
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
            __DIR__ . '/../Domain', $root . '/src/Customers/Domain', $root . '/src/Setting',
        ], true);
        $config->enableNativeLazyObjects(true);
        $em = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema(array_map(fn (string $class) => $em->getClassMetadata($class), [
            \Modules\SiteInquiries\Domain\Inquiry::class, \Gereq\Customers\Domain\Customer::class, \Gereq\Setting\SettingRecord::class,
        ]));
        $store = new \Gereq\Publish\StagingStore($em);
        $registry = new \Gereq\Publish\PublishableRegistry();
        $settings = new \Gereq\Setting\Settings($em, new \Gereq\Config\Repository(), new \Gereq\Publish\ContentReadContext(), $registry, new \Gereq\Publish\StagingWriter($store, $registry));
        $mailer = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $mailTransactions = [];
        $eventTransactions = [];
        $hooks = new \Gereq\Hook\HookManager();
        $hooks->addAction('platform.event.dispatched', static function () use ($em, &$eventTransactions): void {
            $eventTransactions[] = $em->getConnection()->isTransactionActive();
        });
        if ($delivery !== 'not_configured') {
            $em->persist(new \Gereq\Setting\SettingRecord('site_inquiries.notify_email', 'notify@example.test', \Gereq\Setting\SettingRecord::TYPE_STRING));
            $em->flush();
            $send = $mailer->expects(self::once())->method('send');
            $send->willReturnCallback(static function () use ($em, $delivery, &$mailTransactions): void {
                $mailTransactions[] = $em->getConnection()->isTransactionActive();
                if ($delivery === 'failed') {
                    throw new \RuntimeException('Mail transport unavailable');
                }
            });
        } else {
            $mailer->expects(self::never())->method('send');
        }
        $sender = new \Modules\SiteInquiries\Application\InquiryNotificationSender($settings, $mailer, new \Gereq\Config\Repository(), new \Gereq\Tests\Support\PassthroughTranslator(), new \Gereq\Cms\Cms(self::createStub(\Gereq\Module\EnabledModulesProvider::class)));
        $outbox = new \Modules\SiteInquiries\Application\InquiryNotificationOutbox($em, $sender, new \Gereq\Cms\Cms(self::createStub(\Gereq\Module\EnabledModulesProvider::class)));
        $intake = new InquiryIntake($em, new \Gereq\Event\EventBus($hooks), $settings, new \Gereq\Tests\Support\PassthroughTranslator(), new \Gereq\Customers\Application\LeadIntake($em), $outbox);
        $result = $intake->handle('Alice Example', 'a@example.test', '+420123456789', InquiryIntake::formDetails(['details' => 'Contact me.', 'service' => 'Delivery']));
        $inquiry = $em->find(\Modules\SiteInquiries\Domain\Inquiry::class, $result['id']);
        self::assertSame("Contact me.\n\nservice: Delivery", $inquiry->details());
        self::assertSame('received', $result['status']);
        self::assertSame('queued', $result['delivery_status']);
        self::assertSame([], $mailTransactions);
        $outbox->process();
        self::assertSame($delivery === 'failed' ? 'queued' : $delivery, $outbox->status($result['id'])['status']);
        $customer = $em->find(\Gereq\Customers\Domain\Customer::class, $result['customer_id']);
        self::assertSame('lead', $customer->status());
        $operations = new \Modules\SiteInquiries\Application\InquiryFormOperations($em, $outbox);
        self::assertSame(['route' => 'admin.site_inquiries.show', 'params' => ['id' => $result['id']]], $operations->recordLink($result['id']));
        self::assertSame($delivery === 'sent' ? 'sent' : 'queued', $operations->retryNotification($result['id']));
        self::assertSame(1, $em->getRepository(\Modules\SiteInquiries\Domain\Inquiry::class)->count([]));
        self::assertSame(1, $em->getRepository(\Gereq\Customers\Domain\Customer::class)->count([]));
        self::assertSame([false], $eventTransactions);
        self::assertSame($delivery === 'not_configured' ? [] : [false], $mailTransactions);
        $em->close();
    }
}
