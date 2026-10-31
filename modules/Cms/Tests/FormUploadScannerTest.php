<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Gereq\Security\Malware\MalwareScanner;
use Gereq\Security\Malware\ScanResult;
use Gereq\Testing\TestCase;
use Modules\Cms\Application\FormUploadScanner;

final class FormUploadScannerTest extends TestCase
{
    private function fixture(): EntityManager
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($db, $config);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        foreach (['2026_10_22_000001_form_attempts', '2026_10_22_000004_form_uploads', '2026_10_23_000001_retained_form_attachments', '2026_10_23_000002_form_upload_scanning'] as $name) {
            (require __DIR__ . '/../Database/Migrations/' . $name . '.php')->up($schema);
        }
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $db->insert('cms_form_uploads', ['id' => 'file', 'attempt_id' => 'attempt', 'field_name' => 'file', 'filename' => 'file.pdf', 'mime_type' => 'application/pdf', 'content' => 'stored bytes']);
        return $em;
    }

    public function testSuccessfulScanIsBoundToBytesAndNotRepeated(): void
    {
        $em = $this->fixture();
        $scanner = self::createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scan')->with('stored bytes')->willReturn(ScanResult::clean('engine', 'definitions'));
        $worker = new FormUploadScanner($em, $scanner);
        $worker->process();
        $worker->process();
        $row = $em->getConnection()->fetchAssociative('SELECT * FROM cms_form_uploads');
        self::assertSame('clean', $row['scan_status']);
        self::assertSame(hash('sha256', 'stored bytes'), $row['scan_hash']);
        self::assertSame('engine', $row['scan_engine']);
        self::assertSame('definitions', $row['scan_definitions']);
        self::assertFalse($worker->retry('file'));
    }

    public function testUnavailableScannerBacksOffAndEventuallyShowsFailureWithoutRemovingBytes(): void
    {
        $em = $this->fixture();
        $scanner = self::createMock(MalwareScanner::class);
        $scanner->expects(self::exactly(5))->method('scan')->willReturn(ScanResult::failed('unavailable'));
        $worker = new FormUploadScanner($em, $scanner);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $worker->process();
            self::assertSame($attempt < 5 ? 'pending' : 'failed', $em->getConnection()->fetchOne('SELECT scan_status FROM cms_form_uploads'));
            $worker->process();
            $em->getConnection()->update('cms_form_uploads', ['scan_next_at' => '2020-01-01 00:00:00'], ['id' => 'file']);
        }
        self::assertSame('stored bytes', $em->getConnection()->fetchOne('SELECT content FROM cms_form_uploads'));
        self::assertTrue($worker->retry('file'));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT scan_attempts FROM cms_form_uploads'));
    }

    public function testThreatVerdictsCannotBeOverriddenByRetry(): void
    {
        $em = $this->fixture();
        $scanner = self::createStub(MalwareScanner::class);
        $scanner->method('scan')->willReturn(ScanResult::infected('engine', 'definitions'));
        $worker = new FormUploadScanner($em, $scanner);
        $worker->process();
        self::assertSame('infected', $em->getConnection()->fetchOne('SELECT scan_status FROM cms_form_uploads'));
        self::assertFalse($worker->retry('file'));
    }

    public function testActiveLeasesAndSupersededWorkersCannotOverwriteAnotherVerdict(): void
    {
        $em = $this->fixture();
        $db = $em->getConnection();
        $scanner = self::createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scan')->willReturnCallback(static function () use ($db): ScanResult {
            $db->update('cms_form_uploads', ['scan_status' => 'infected', 'scan_lease_token' => 'another-worker'], ['id' => 'file']);
            return ScanResult::clean('old-worker', 'old-definitions');
        });
        $worker = new FormUploadScanner($em, $scanner);
        $db->update('cms_form_uploads', ['scan_status' => 'scanning', 'scan_lease_until' => gmdate('Y-m-d H:i:s', time() + 60)], ['id' => 'file']);
        $worker->process();
        $db->update('cms_form_uploads', ['scan_lease_until' => '2020-01-01 00:00:00'], ['id' => 'file']);
        $worker->process();
        self::assertSame('infected', $db->fetchOne('SELECT scan_status FROM cms_form_uploads'));
    }

    public function testRollingBackScanMetadataPreservesAttachmentBytes(): void
    {
        $em = $this->fixture();
        $db = $em->getConnection();
        $before = $db->createSchemaManager()->introspectSchema();
        $after = clone $before;
        (require __DIR__ . '/../Database/Migrations/2026_10_23_000002_form_upload_scanning.php')->down($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($db->createSchemaManager()->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertSame('stored bytes', $db->fetchOne('SELECT content FROM cms_form_uploads'));
        self::assertFalse($db->createSchemaManager()->introspectTable('cms_form_uploads')->hasColumn('scan_status'));
    }

    public function testInterruptedFinalAttemptBecomesVisibleFailure(): void
    {
        $em = $this->fixture();
        $em->getConnection()->update('cms_form_uploads', ['scan_status' => 'scanning', 'scan_attempts' => 5, 'scan_lease_until' => '2020-01-01 00:00:00'], ['id' => 'file']);
        $scanner = self::createMock(MalwareScanner::class);
        $scanner->expects(self::never())->method('scan');
        (new FormUploadScanner($em, $scanner))->process();
        self::assertSame('failed', $em->getConnection()->fetchOne('SELECT scan_status FROM cms_form_uploads'));
    }
}
