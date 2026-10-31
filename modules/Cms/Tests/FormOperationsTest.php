<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Gereq\Testing\TestCase;
use Modules\Cms\Application\FormSubmissionLog;
use Symfony\Component\HttpFoundation\JsonResponse;

final class FormOperationsTest extends TestCase
{
    public function testUnavailableDeliveryDiagnosticsDoNotAbortTheOwningTransaction(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($db, $config);
        $db->beginTransaction();
        (new FormSubmissionLog($em))->updateDelivery('test', 'record', 'sent');
        self::assertTrue($db->isTransactionActive());
        $db->commit();
        self::assertFalse($db->isTransactionActive());
    }

    public function testAttemptMigrationReversesAndLogContainsMetadataOnly(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($db, $config);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $migration = require __DIR__ . '/../Database/Migrations/2026_10_22_000001_form_attempts.php';
        $migration->up($schema);
        $deliveryMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000002_form_delivery.php';
        $deliveryMigration->up($schema);
        $receiptMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000003_form_receipts.php';
        $receiptMigration->up($schema);
        $uploadMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000004_form_uploads.php';
        $uploadMigration->up($schema);
        $indexMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000005_form_upload_retention_index.php';
        $indexMigration->up($schema);
        self::assertTrue($schema->getTable('cms_form_uploads')->hasIndex('idx_cms_form_uploads_created'));
        $retainedMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000001_retained_form_attachments.php';
        $retainedMigration->up($schema);
        $scanMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000002_form_upload_scanning.php';
        $scanMigration->up($schema);
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $log = new FormSubmissionLog($em);
        $log->record('contact', 'form', new JsonResponse(['error' => ['code' => 'validation_error', 'fields' => ['email' => 'private@example.test']]], 422));
        $rows = $log->recent();
        self::assertCount(1, $rows);
        self::assertSame('validation_error', $rows[0]['outcome']);
        self::assertSame(422, $rows[0]['http_status']);
        self::assertStringNotContainsString('private@example.test', json_encode($rows, JSON_THROW_ON_ERROR));
        $db->update('cms_form_attempts', ['created_at' => '2020-01-01 00:00:00'], ['id' => $rows[0]['id']]);
        $log->record('contact', 'form', new JsonResponse(['data' => ['status' => 'received']], 201));
        self::assertCount(1, $log->recent());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM cms_form_attempts'));
        $log->record('other', 'another-form', new JsonResponse(['error' => ['code' => 'validation_error']], 422), null, 'failed', 'test', 'record');
        $log->updateDelivery('other-action', 'record', 'sent');
        self::assertCount(1, $log->recent(['delivery' => 'failed']));
        $log->updateDelivery('test', 'record', 'queued');
        self::assertCount(1, $log->recent(['delivery' => 'queued']));
        $log->updateDelivery('test', 'record', 'failed');
        self::assertCount(1, $log->recent(['page' => 'contact', 'form' => 'form', 'outcome' => 'received', 'delivery' => 'unknown', 'from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d')]));
        self::assertCount(1, $log->recent(['delivery' => 'failed']));
        self::assertCount(0, $log->recent(['page' => "' OR 1=1 --"]));
        self::assertCount(0, $log->recent(['from' => gmdate('Y-m-d', time() + 86400)]));
        self::assertSame(['page' => '', 'form' => '', 'outcome' => '', 'delivery' => '', 'from' => '', 'to' => ''], FormSubmissionLog::filters(['page' => [], 'outcome' => 'invalid', 'from' => '2026-02-30']));
        self::assertTrue($schema->getTable('cms_form_attempts')->hasColumn('delivery_status'));
        $scanMigration->down($schema);
        $retainedMigration->down($schema);
        $indexMigration->down($schema);
        $uploadMigration->down($schema);
        $receiptMigration->down($schema);
        $deliveryMigration->down($schema);
        self::assertFalse($schema->getTable('cms_form_attempts')->hasColumn('delivery_status'));
        $migration->down($schema);
        self::assertFalse($schema->hasTable('cms_form_attempts'));
    }
}
