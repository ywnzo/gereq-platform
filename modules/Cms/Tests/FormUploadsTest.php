<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationException;
use Modules\Cms\Application\FormUploads;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FormUploadsTest extends TestCase
{
    public function testExistingLinkedAttachmentsSurviveLogRetentionAndDeleteTransactionally(): void
    {
        $db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new \Doctrine\ORM\EntityManager($db, $config);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        foreach (['000001_form_attempts', '000002_form_delivery', '000003_form_receipts', '000004_form_uploads'] as $name) {
            $migration = require __DIR__ . '/../Database/Migrations/2026_10_22_' . $name . '.php';
            $migration->up($schema);
        }
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $log = new \Modules\Cms\Application\FormSubmissionLog($em);
        $db->beginTransaction();
        $log->record('page', 'form', new \Symfony\Component\HttpFoundation\JsonResponse([], 201), null, 'sent', 'test', 'record', 'attempt', true);
        $db->commit();
        foreach (['linked', 'temporary'] as $id) {
            $db->insert('cms_form_uploads', [
                'id' => $id, 'attempt_id' => $id === 'linked' ? 'attempt' : 'missing',
                'field_name' => 'attachment', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf',
                'content' => 'private bytes', 'created_at' => '2020-01-01 00:00:00',
            ]);
        }
        $migration = require __DIR__ . '/../Database/Migrations/2026_10_23_000001_retained_form_attachments.php';
        $old = clone $schema;
        $migration->up($schema);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($db->createSchemaManager()->createComparator()->compareSchemas($old, $schema)) as $sql) {
            $db->executeStatement($sql);
        }
        $migration->afterUp($db);
        $old = clone $schema;
        $scanMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000002_form_upload_scanning.php';
        $scanMigration->up($schema);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($db->createSchemaManager()->createComparator()->compareSchemas($old, $schema)) as $sql) {
            $db->executeStatement($sql);
        }
        $db->update('cms_form_attempts', ['created_at' => '2020-01-01 00:00:00'], ['id' => 'attempt']);
        $log->prune(true);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cms_form_attempts'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM cms_form_uploads'));
        $storage = new FormUploads($em, new PassthroughTranslator());
        self::assertSame([['id' => 'linked', 'filename' => 'private.pdf', 'scan_status' => 'pending', 'route' => 'admin.cms.forms.download', 'params' => ['id' => 'linked']]], $storage->forRecord('test', 'record'));
        self::assertSame([], $storage->forRecord('another-action', 'record'));
        $db->beginTransaction();
        $storage->removeForRecord('test', 'record');
        self::assertSame([], $storage->forRecord('test', 'record'));
        $db->rollBack();
        self::assertCount(1, $storage->forRecord('test', 'record'));
        $storage->removeForRecord('another-action', 'record');
        self::assertCount(1, $storage->forRecord('test', 'record'));
        $storage->removeForRecord('test', 'record');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cms_form_uploads'));
        $migration->down($schema);
        self::assertFalse($schema->getTable('cms_form_uploads')->hasColumn('receipt_id'));
    }

    public function testRejectsDisguisedFilesUnknownFieldsAndNestedValues(): void
    {
        $service = new FormUploads(self::createStub(EntityManagerInterface::class), new PassthroughTranslator());
        $path = tempnam(sys_get_temp_dir(), 'cms-upload-test-');
        try {
            file_put_contents($path, '<?php echo "unsafe";');
            $file = new UploadedFile($path, 'document.pdf', 'application/pdf', null, true);
            try {
                $service->validate(['attachment' => ['control' => 'file']], ['attachment' => $file, 'other' => $file, 'nested' => [$file]]);
                self::fail('Client-provided MIME types and unknown fields must not be trusted.');
            } catch (ValidationException $exception) {
                self::assertSame(['attachment', 'other', 'nested'], array_keys($exception->errors()));
            }
        } finally {
            unlink($path);
        }
    }

    public function testRejectsAnOversizedAttachment(): void
    {
        $service = new FormUploads(self::createStub(EntityManagerInterface::class), new PassthroughTranslator());
        $path = tempnam(sys_get_temp_dir(), 'cms-upload-test-');
        try {
            file_put_contents($path, "%PDF-1.4\n" . str_repeat('x', 5 * 1024 * 1024));
            $this->expectException(ValidationException::class);
            $service->validate(['attachment' => ['control' => 'file']], ['attachment' => new UploadedFile($path, 'document.pdf', 'application/pdf', null, true)]);
        } finally {
            unlink($path);
        }
    }
}
