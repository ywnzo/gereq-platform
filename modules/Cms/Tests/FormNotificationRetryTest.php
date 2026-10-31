<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Audit\AuditLog;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasswordHasher;
use Gereq\Cms\Cms;
use Gereq\Cms\FormActionOperations;
use Gereq\Http\Exception\HttpException;
use Gereq\Http\Router;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\User\Entity\User;
use Modules\Cms\Application\FormSubmissionLog;
use Modules\Cms\Http\FormController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FormNotificationRetryTest extends TestCase
{
    /** @return array{FormController, FormActionOperations&\PHPUnit\Framework\MockObject\MockObject, EntityManager, string} */
    private function fixture(bool $permitted): array
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Audit'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(AuditLog::class)]);
        $schema = new \Doctrine\DBAL\Schema\Schema();
        foreach (['000001_form_attempts', '000002_form_delivery', '000003_form_receipts', '000004_form_uploads'] as $name) {
            $migration = require __DIR__ . '/../Database/Migrations/2026_10_22_' . $name . '.php';
            $migration->up($schema);
        }
        $retainedMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000001_retained_form_attachments.php';
        $retainedMigration->up($schema);
        $scanMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000002_form_upload_scanning.php';
        $scanMigration->up($schema);
        foreach ($schema->toSql($em->getConnection()->getDatabasePlatform()) as $sql) {
            $em->getConnection()->executeStatement($sql);
        }
        $stack = new RequestStack();
        $request = Request::create('/admin/cms/forms/attempts/attempt/retry', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
        $auditor = new Auditor($em, $stack);
        $auth = new Authenticator($em, $stack, new PasswordHasher(), $auditor);
        $user = self::createStub(User::class);
        $user->method('can')->willReturn($permitted);
        $authReflection = new \ReflectionClass($auth);
        $authReflection->getProperty('user')->setValue($auth, $user);
        $authReflection->getProperty('identityResolved')->setValue($auth, true);
        $modules = self::createStub(EnabledModulesProvider::class);
        $modules->method('enabledIds')->willReturn(['cms']);
        $cms = new Cms($modules);
        $operations = self::createMock(FormActionOperations::class);
        $operations->method('viewPermission')->willReturn('test.view');
        $operations->method('retryPermission')->willReturn('test.manage');
        $operations->method('recordLink')->willReturn(['route' => 'test.show', 'params' => ['id' => 'record']]);
        $cms->registerFormAction('test', 'Test', 'test', [], static fn (): array => throw new \RuntimeException('Retry must never call intake.'), $operations);
        $log = new FormSubmissionLog($em);
        $log->record('contact', 'form', new JsonResponse(['data' => ['status' => 'received']], 201), null, 'failed', 'test', 'record');
        $id = (string) $em->getConnection()->fetchOne('SELECT id FROM cms_form_attempts');
        $router = new Router();
        $router->get('/admin/cms/forms/attempts', static fn () => null)->name('admin.cms.forms.attempts');
        $reflection = new \ReflectionClass(FormController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach (['em' => $em, 'auth' => $auth, 'formActions' => $cms, 'auditor' => $auditor, 'translator' => new PassthroughTranslator(), 'requestStack' => $stack, 'router' => $router] as $property => $value) {
            $reflection->getProperty($property)->setValue($controller, $value);
        }
        return [$controller, $operations, $em, $id];
    }

    public function testPrivateAttachmentsRequireTheActionViewPermission(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(false);
        $em->getConnection()->insert('cms_form_uploads', ['id' => 'upload', 'attempt_id' => $id, 'action_id' => 'test', 'receipt_id' => 'record', 'field_name' => 'attachment', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'content' => 'private bytes', 'scan_status' => 'clean', 'scan_hash' => hash('sha256', 'private bytes')]);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $controller->downloadFormUpload('upload');
    }

    public function testAllowedDownloadsAreAttachmentsAndNeverCachePrivateBytes(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $em->getConnection()->insert('cms_form_uploads', ['id' => 'upload', 'attempt_id' => $id, 'action_id' => 'test', 'receipt_id' => 'record', 'field_name' => 'attachment', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'content' => 'private bytes', 'scan_status' => 'clean', 'scan_hash' => hash('sha256', 'private bytes')]);
        $em->getConnection()->update('cms_form_uploads', ['created_at' => '2020-01-01 00:00:00'], ['id' => 'upload']);
        $em->getConnection()->delete('cms_form_attempts', ['id' => $id]);
        $response = $controller->downloadFormUpload('upload');
        self::assertSame('private bytes', $response->getContent());
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testChangedBytesCannotUseAPreviousCleanVerdict(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $em->getConnection()->insert('cms_form_uploads', ['id' => 'upload', 'attempt_id' => $id, 'action_id' => 'test', 'receipt_id' => 'record', 'field_name' => 'attachment', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'content' => 'changed bytes', 'scan_status' => 'clean', 'scan_hash' => hash('sha256', 'original bytes')]);
        $request = (new \ReflectionClass($controller))->getProperty('requestStack')->getValue($controller)->getCurrentRequest();
        $request->request->set('acknowledge', '1');
        foreach (['GET', 'POST'] as $method) {
            $request->setMethod($method);
            try {
                $controller->downloadFormUpload('upload');
                self::fail('Changed attachment bytes must not use a previous clean verdict.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getCode());
            }
        }
    }

    public function testManualScanRetryRequiresManagementPermission(): void
    {
        [$controller] = $this->fixture(false);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $controller->retryUploadScan('upload');
    }

    public function testManualScanRetryOnlyQueuesFailedAttachmentsAndAuditsTheRequest(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $em->getConnection()->insert('cms_form_uploads', ['id' => 'upload', 'attempt_id' => $id, 'action_id' => 'test', 'receipt_id' => 'record', 'field_name' => 'attachment', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'content' => 'private bytes', 'scan_status' => 'infected']);
        $reflection = new \ReflectionClass($controller);
        $reflection->getProperty('uploadScanner')->setValue($controller, new \Modules\Cms\Application\FormUploadScanner($em, new \Gereq\Security\Malware\ClamAvScanner(new \Gereq\Config\Repository())));
        $reflection->getProperty('router')->getValue($controller)->get('/admin/cms/forms/uploads/{id}', static fn () => null)->name('admin.cms.forms.download');
        foreach (['infected', 'clean', 'pending', 'scanning'] as $status) {
            $em->getConnection()->update('cms_form_uploads', ['scan_status' => $status], ['id' => 'upload']);
            try {
                $controller->retryUploadScan('upload');
                self::fail('Only failed attachments can be manually queued for scanning.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getCode());
            }
            self::assertSame($status, $em->getConnection()->fetchOne('SELECT scan_status FROM cms_form_uploads'));
        }
        self::assertSame(0, $em->getRepository(AuditLog::class)->count(['action' => 'cms.form.scan_retry_requested']));
        $em->getConnection()->update('cms_form_uploads', ['scan_status' => 'failed', 'scan_attempts' => 5, 'scan_reason' => 'unavailable'], ['id' => 'upload']);
        self::assertSame(302, $controller->retryUploadScan('upload')->getStatusCode());
        $row = $em->getConnection()->fetchAssociative('SELECT scan_status, scan_attempts, scan_reason FROM cms_form_uploads');
        self::assertSame('pending', $row['scan_status']);
        self::assertSame(0, (int) $row['scan_attempts']);
        self::assertSame('', $row['scan_reason']);
        self::assertSame(1, $em->getRepository(AuditLog::class)->count(['action' => 'cms.form.scan_retry_requested']));
    }

    public function testUnscannedDownloadsRequirePostAcknowledgementAndBlockedFilesIgnoreIt(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $em->getConnection()->insert('cms_form_uploads', ['id' => 'upload', 'attempt_id' => $id, 'action_id' => 'test', 'receipt_id' => 'record', 'field_name' => 'file', 'filename' => 'private.pdf', 'mime_type' => 'application/pdf', 'content' => 'private bytes']);
        $reflection = new \ReflectionClass($controller);
        $reflection->getProperty('twig')->setValue($controller, new \Twig\Environment(new \Twig\Loader\ArrayLoader(['@cms/pages/form_upload_warning.html.twig' => 'WARNING {{ scan_status }}'])));
        $reflection->getProperty('menu')->setValue($controller, new \Gereq\Admin\Menu\Menu(new PassthroughTranslator()));
        $reflection->getProperty('palette')->setValue($controller, new \Gereq\Admin\CommandPalette\CommandPalette());
        $stack = $reflection->getProperty('requestStack')->getValue($controller);
        $request = $stack->getCurrentRequest();
        $request->setMethod('GET');
        $request->query->set('acknowledge', '1');
        self::assertSame('WARNING pending', $controller->downloadFormUpload('upload')->getContent());
        $request->setMethod('POST');
        self::assertSame('WARNING pending', $controller->downloadFormUpload('upload')->getContent());
        $request->request->set('acknowledge', '1');
        $csrf = new \Gereq\Security\Csrf($stack);
        $middleware = new \Gereq\Http\Middleware\CsrfMiddleware($csrf);
        try {
            $middleware->process($request, static fn (): \Symfony\Component\HttpFoundation\Response => $controller->downloadFormUpload('upload'));
            self::fail('Unscanned download acknowledgements require a valid CSRF token.');
        } catch (\Gereq\Http\Exception\CsrfTokenMismatchException $exception) {
            self::assertSame(419, $exception->getCode());
        }
        $request->request->set('_csrf', $csrf->token());
        self::assertSame('private bytes', $middleware->process($request, static fn (): \Symfony\Component\HttpFoundation\Response => $controller->downloadFormUpload('upload'))->getContent());
        self::assertSame(1, $em->getRepository(AuditLog::class)->count(['action' => 'cms.form.unscanned_download_acknowledged']));
        $em->getConnection()->update('cms_form_uploads', ['scan_status' => 'infected'], ['id' => 'upload']);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $controller->downloadFormUpload('upload');
    }

    public function testRetryClaimsAtomicallyAndNeverCallsIntake(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $operations->expects(self::once())->method('retryNotification')->with('record')->willReturnCallback(function () use ($controller, $id): string {
            try {
                $controller->retryFormNotification($id);
                self::fail('A concurrent retry must not send another notification.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->status());
            }
            return 'sent';
        });
        self::assertSame(302, $controller->retryFormNotification($id)->getStatusCode());
        $row = $em->getConnection()->fetchAssociative('SELECT * FROM cms_form_attempts WHERE id = ?', [$id]);
        self::assertSame('sent', $row['delivery_status']);
        self::assertSame(1, $row['retry_count']);
        self::assertNotNull($row['last_retry_at']);
        self::assertSame(2, $em->getRepository(AuditLog::class)->count([]));
    }

    public function testRetryRequiresTheActionPermission(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(false);
        $operations->expects(self::never())->method('retryNotification');
        try {
            $controller->retryFormNotification($id);
            self::fail('An action permission is required.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT retry_count FROM cms_form_attempts WHERE id = ?', [$id]));
        }
    }

    public function testRetryLimitIsEnforcedOnTheServer(): void
    {
        [$controller, $operations, $em, $id] = $this->fixture(true);
        $em->getConnection()->update('cms_form_attempts', ['retry_count' => 5], ['id' => $id]);
        $operations->expects(self::never())->method('retryNotification');
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(409);
        $controller->retryFormNotification($id);
    }
}
