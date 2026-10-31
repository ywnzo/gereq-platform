<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Cms\Cms;
use Gereq\Config\Repository;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Modules\SiteInquiries\Application\InquiryNotificationOutbox;
use Modules\SiteInquiries\Application\InquiryNotificationSender;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Component\Mailer\MailerInterface;

final class InquiryNotificationOutboxTest extends TestCase
{
    /** @return array{EntityManager, InquiryNotificationOutbox, Inquiry} */
    private function fixture(MailerInterface $mailer, ?\Gereq\Cms\FormAttachmentStorage $attachments = null): array
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain', dirname(__DIR__, 3) . '/src/Setting'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Inquiry::class), $em->getClassMetadata(SettingRecord::class)]);
        $registry = new PublishableRegistry();
        $settings = new Settings($em, new Repository(), new ContentReadContext(), $registry, new StagingWriter(new StagingStore($em), $registry));
        $em->persist(new SettingRecord('site_inquiries.notify_email', 'staff@example.test', SettingRecord::TYPE_STRING));
        $inquiry = new Inquiry('Test', 'visitor@example.test', '123', 'Details');
        $inquiry->queueNotification();
        $em->persist($inquiry);
        $em->flush();
        $cms = new Cms(self::createStub(EnabledModulesProvider::class));
        if ($attachments !== null) {
            $cms->registerAttachmentStorage($attachments);
        }
        $cms->registerFormDeliveryUpdater(static function (string $action, string $id, string $status) use ($inquiry): void {
            self::assertSame('site_inquiries.create', $action);
            self::assertSame($inquiry->id(), $id);
            self::assertContains($status, ['sent', 'failed', 'queued']);
        });
        return [$em, new InquiryNotificationOutbox($em, new InquiryNotificationSender($settings, $mailer, new Repository(), new PassthroughTranslator(), $cms), $cms), $inquiry];
    }

    public function testStaffEmailWarnsAboutUnscannedAndBlockedAttachments(): void
    {
        $attachments = self::createStub(\Gereq\Cms\FormAttachmentStorage::class);
        $attachments->method('forRecord')->willReturn([
            ['id' => 'pending', 'filename' => 'pending.pdf', 'scan_status' => 'pending', 'route' => 'download', 'params' => []],
            ['id' => 'infected', 'filename' => 'blocked.pdf', 'scan_status' => 'infected', 'route' => 'download', 'params' => []],
        ]);
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function (\Symfony\Component\Mime\RawMessage $message): void {
            self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
            self::assertStringContainsString('site_inquiries.scan.warning', $message->getTextBody());
            self::assertStringContainsString('site_inquiries.scan.blocked', $message->getTextBody());
        });
        [$em, $outbox, $inquiry] = $this->fixture($mailer, $attachments);
        $outbox->process();
        self::assertSame('sent', $outbox->status($inquiry->id())['status']);
    }

    public function testFailureBackoffStopsAfterFiveAttemptsAndExplicitRetryRestartsTheCycle(): void
    {
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::exactly(5))->method('send')->willThrowException(new \RuntimeException('Transport down'));
        [$em, $outbox, $inquiry] = $this->fixture($mailer);
        $db = $em->getConnection();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $outbox->process();
            $state = $outbox->status($inquiry->id());
            self::assertSame($attempt, $state['attempts']);
            self::assertSame($attempt === 5 ? 'failed' : 'queued', $state['status']);
            if ($attempt < 5) {
                self::assertNotNull($state['next_at']);
                self::assertGreaterThan(gmdate('Y-m-d H:i:s'), $state['next_at']);
                $outbox->process();
                self::assertSame($attempt, $outbox->status($inquiry->id())['attempts']);
                $db->update('site_inquiries', ['notification_next_at' => '2020-01-01 00:00:00'], ['id' => $inquiry->id()]);
            }
        }
        $outbox->process();
        self::assertSame(5, $outbox->status($inquiry->id())['attempts']);
        self::assertSame('queued', $outbox->retry($inquiry->id()));
        self::assertSame(0, $outbox->status($inquiry->id())['attempts']);
    }

    public function testConcurrentWorkersAndManualRetryNeverResendAnAcceptedNotification(): void
    {
        $mailer = self::createMock(MailerInterface::class);
        [$em, $outbox, $inquiry] = $this->fixture($mailer);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function () use ($em, $outbox, $inquiry): void {
            self::assertFalse($em->getConnection()->isTransactionActive());
            $outbox->process();
            self::assertSame('queued', $outbox->retry($inquiry->id()));
        });
        $outbox->process();
        self::assertSame('sent', $outbox->status($inquiry->id())['status']);
        self::assertNotNull($outbox->status($inquiry->id())['sent_at']);
        self::assertSame('sent', $outbox->retry($inquiry->id()));
        $outbox->process();
    }

    public function testExpiredLeasesRecoverButExhaustedLeasesDoNotSendAgain(): void
    {
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');
        [$em, $outbox, $inquiry] = $this->fixture($mailer);
        $db = $em->getConnection();
        $db->update('site_inquiries', ['notification_status' => 'sending', 'notification_attempts' => 1, 'notification_lease_until' => '2020-01-01 00:00:00', 'notification_lease_token' => 'dead-worker'], ['id' => $inquiry->id()]);
        $outbox->process();
        self::assertSame('sent', $outbox->status($inquiry->id())['status']);
        self::assertSame(2, $outbox->status($inquiry->id())['attempts']);
        $db->update('site_inquiries', ['notification_status' => 'sending', 'notification_attempts' => 5, 'notification_lease_until' => '2020-01-01 00:00:00'], ['id' => $inquiry->id()]);
        $outbox->process();
        self::assertSame('failed', $outbox->status($inquiry->id())['status']);
    }

    public function testPendingWorkRollsBackAndDeletedInquiriesCannotBeSent(): void
    {
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        [$em, $outbox, $inquiry] = $this->fixture($mailer);
        $db = $em->getConnection();
        $db->beginTransaction();
        $rolledBack = new Inquiry('Rolled back', 'visitor@example.test', '123', 'Details');
        $rolledBack->queueNotification();
        $em->persist($rolledBack);
        $em->flush();
        $db->rollBack();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM site_inquiries WHERE id = ?', [$rolledBack->id()]));
        $em->remove($inquiry);
        $em->flush();
        $outbox->process();
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM site_inquiries WHERE notification_status = 'queued'"));
    }

    public function testWorkerRefusesToSendInsideAnIntakeTransaction(): void
    {
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        [$em, $outbox] = $this->fixture($mailer);
        $em->getConnection()->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $outbox->process();
        } finally {
            $em->getConnection()->rollBack();
        }
    }

    public function testMigrationLeavesHistoricalInquiriesUnscheduledAndReverses(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $schema->createTable('site_inquiries')->addColumn('id', 'string');
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $db->insert('site_inquiries', ['id' => 'historical']);
        $migration = require __DIR__ . '/../Database/Migrations/2026_10_23_000001_inquiry_notification_outbox.php';
        $empty = new \Doctrine\DBAL\Schema\Schema();
        $migration->up($empty);
        self::assertFalse($empty->hasTable('site_inquiries'));
        $old = clone $schema;
        $migration->up($schema);
        $db->createSchemaManager()->alterSchema($db->createSchemaManager()->createComparator()->compareSchemas($old, $schema));
        self::assertSame('not_attempted', $db->fetchOne('SELECT notification_status FROM site_inquiries'));
        self::assertNull($db->fetchOne('SELECT notification_next_at FROM site_inquiries'));
        $old = clone $schema;
        $migration->down($schema);
        $db->createSchemaManager()->alterSchema($db->createSchemaManager()->createComparator()->compareSchemas($old, $schema));
        self::assertSame('historical', $db->fetchOne('SELECT id FROM site_inquiries'));
        self::assertFalse($schema->getTable('site_inquiries')->hasColumn('notification_status'));
    }
}
