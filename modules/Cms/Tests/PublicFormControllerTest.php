<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Api\Entity\IdempotencyRecord;
use Gereq\Api\IdempotencyService;
use Gereq\Cms\Cms;
use Gereq\Config\Repository;
use Gereq\Hook\HookManager;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\FrozenPublicReader;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Security\HtmlSanitizer;
use Gereq\Security\RateLimiter;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationRegistry;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Application\DynamicElement;
use Modules\Cms\Application\FormSchema;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Http\Public\PublicFormController;
use Symfony\Component\HttpFoundation\Request;

final class PublicFormControllerTest extends TestCase
{
    private EntityManager $em;
    private Cms $cms;
    private ContentReadContext $readContext;
    private int $calls = 0;
    private string $limiterPath;
    private Page $page;
    private PageBlock $block;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain', dirname(__DIR__, 3) . '/src/Api/Entity', dirname(__DIR__, 3) . '/src/Publish/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema(array_map(fn (string $class) => $this->em->getClassMetadata($class), [Page::class, PageBlock::class, ContentStagingEntry::class, IdempotencyRecord::class]));
        $migration = require __DIR__ . '/../Database/Migrations/2026_10_22_000001_form_attempts.php';
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $migration->up($schema);
        $deliveryMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000002_form_delivery.php';
        $deliveryMigration->up($schema);
        $receiptMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000003_form_receipts.php';
        $receiptMigration->up($schema);
        $uploadMigration = require __DIR__ . '/../Database/Migrations/2026_10_22_000004_form_uploads.php';
        $uploadMigration->up($schema);
        $retainedMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000001_retained_form_attachments.php';
        $retainedMigration->up($schema);
        $scanMigration = require __DIR__ . '/../Database/Migrations/2026_10_23_000002_form_upload_scanning.php';
        $scanMigration->up($schema);
        foreach ($schema->toSql($this->em->getConnection()->getDatabasePlatform()) as $sql) {
            $this->em->getConnection()->executeStatement($sql);
        }
        $modules = self::createStub(EnabledModulesProvider::class);
        $modules->method('enabledIds')->willReturn(['cms']);
        $this->cms = new Cms($modules);
        $this->cms->registerFormAction('test', 'Test', 'test.submit', ['email' => 'email'], function (array $values): array {
            $this->calls++;
            self::assertSame('a@example.test', $values['email']);
            return ['private_id' => 'must-not-leak', 'delivery_status' => 'sent'];
        });
        $this->readContext = new ContentReadContext();
        $this->limiterPath = sys_get_temp_dir() . '/cms-form-test-' . bin2hex(random_bytes(8));
        $this->page = new Page('contact', 'Contact');
        $this->page->setStatus('published');
        $this->block = new PageBlock($this->page->id(), 'form', 0, ['action' => 'test', 'children' => [
            ['id' => 'email', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'email', 'control' => 'email', 'required' => 1])],
        ]]);
        $this->em->persist($this->page);
        $this->em->persist($this->block);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $files = glob($this->limiterPath . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->limiterPath)) {
            rmdir($this->limiterPath);
        }
        $this->em->close();
    }

    public function testNotificationsRunOnlyAfterCommitAndFailureDoesNotRepeatIntake(): void
    {
        $notifications = 0;
        $this->cms->registerFormAction('deferred', 'Deferred', 'test.submit', ['email' => 'email'], function (): array {
            self::assertTrue($this->em->getConnection()->isTransactionActive());
            $this->calls++;
            return ['id' => 'private-record'];
        }, null, function () use (&$notifications): string {
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertSame(1, $this->em->getRepository(IdempotencyRecord::class)->count([]));
            $notifications++;
            throw new \RuntimeException('Private notification failure');
        });
        $payload = $this->block->payload();
        $payload['action'] = 'deferred';
        $this->block->setPayload($payload);
        $this->em->flush();
        $controller = $this->controller();
        $request = $this->request(['email' => 'a@example.test']);
        self::assertSame(201, $controller->submit($request, 'contact', $this->block->id())->getStatusCode());
        self::assertSame('failed', $this->em->getConnection()->fetchOne('SELECT delivery_status FROM cms_form_attempts'));
        self::assertSame(201, $controller->submit($request, 'contact', $this->block->id())->getStatusCode());
        self::assertSame(1, $this->calls);
        self::assertSame(1, $notifications);
    }

    public function testRolledBackIntakeNeverNotifies(): void
    {
        $notifications = 0;
        $this->cms->registerFormAction('rollback', 'Rollback', 'test.submit', ['email' => 'email'], static fn (): array => throw new \RuntimeException('Intake failed'), null, static function () use (&$notifications): string {
            $notifications++;
            return 'sent';
        });
        $payload = $this->block->payload();
        $payload['action'] = 'rollback';
        $this->block->setPayload($payload);
        $this->em->flush();
        self::assertSame(500, $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id())->getStatusCode());
        self::assertSame(0, $notifications);
        self::assertSame(0, $this->em->getRepository(IdempotencyRecord::class)->count([]));
    }

    public function testInvalidFormRequestsAreRateLimitedWithoutUnboundedDenialLogs(): void
    {
        $controller = $this->controller();
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(404, $controller->submit($this->request([]), 'missing', 'missing')->getStatusCode());
        }
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(429, $controller->submit($this->request([]), 'missing', 'missing')->getStatusCode());
        }
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cms_form_attempts'));
    }

    public function testMultipartUploadIsPrivateAndReplaysWithoutAnotherAction(): void
    {
        $payload = $this->block->payload();
        $payload['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => 'attachment', 'control' => 'file', 'required' => 1])];
        $this->block->setPayload($payload);
        $this->em->flush();
        $path = tempnam(sys_get_temp_dir(), 'cms-upload-test-');
        try {
            file_put_contents($path, "%PDF-1.4\nTest attachment");
            $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'test.pdf', 'application/pdf', null, true);
            $request = Request::create('/api/public/pages/contact/forms/' . $this->block->id(), 'POST', ['values' => json_encode(['email' => 'a@example.test'])], [], ['files' => ['attachment' => $file]], ['CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'HTTP_IDEMPOTENCY_KEY' => 'multipart_submission_12345']);
            $controller = $this->controller();
            $response = $controller->submit($request, 'contact', $this->block->id());
            self::assertSame(201, $response->getStatusCode());
            self::assertStringNotContainsString('test.pdf', (string) $response->getContent());
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cms_form_uploads'));
            self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT scan_status FROM cms_form_uploads'));
            self::assertSame(201, $controller->submit($request, 'contact', $this->block->id())->getStatusCode());
            self::assertSame(1, $this->calls);
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cms_form_uploads'));
            file_put_contents($path, "%PDF-1.4\nChanged attachment");
            self::assertSame(409, $controller->submit($request, 'contact', $this->block->id())->getStatusCode());
        } finally {
            unlink($path);
        }
    }

    public function testAnUploadCannotCommitWithoutItsDownloadReference(): void
    {
        $payload = $this->block->payload();
        $payload['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => 'attachment', 'control' => 'file'])];
        $this->block->setPayload($payload);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DROP TABLE cms_form_attempts');
        $path = tempnam(sys_get_temp_dir(), 'cms-upload-test-');
        try {
            file_put_contents($path, "%PDF-1.4\nTest attachment");
            $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'test.pdf', 'application/pdf', null, true);
            $request = Request::create('/api/public/pages/contact/forms/' . $this->block->id(), 'POST', ['values' => json_encode(['email' => 'a@example.test'])], [], ['files' => ['attachment' => $file]], ['CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'HTTP_IDEMPOTENCY_KEY' => 'multipart_submission_12345']);
            self::assertSame(500, $this->controller()->submit($request, 'contact', $this->block->id())->getStatusCode());
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cms_form_uploads'));
            self::assertSame(0, $this->em->getRepository(IdempotencyRecord::class)->count([]));
        } finally {
            unlink($path);
        }
    }

    public function testJsonFilenameCannotSatisfyARequiredUpload(): void
    {
        $payload = $this->block->payload();
        $payload['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => 'attachment', 'control' => 'file', 'required' => 1])];
        $this->block->setPayload($payload);
        $this->em->flush();
        self::assertSame(422, $this->controller()->submit($this->request(['email' => 'a@example.test', 'attachment' => 'forged.pdf']), 'contact', $this->block->id())->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    public function testConfiguredHomepageRespectsPublishedAndStagedStates(): void
    {
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(\Gereq\Setting\SettingRecord::class)]);
        $store = new StagingStore($this->em);
        $registry = new PublishableRegistry();
        $settings = new Settings($this->em, new Repository(), $this->readContext, $registry, new StagingWriter($store, $registry));
        $hooks = new HookManager();
        $makeProvider = fn (): CmsProvider => new CmsProvider($this->em, new HtmlSanitizer(), $this->readContext, new FrozenPublicReader(new StagingStore($this->em)), $settings, $hooks, new DynamicElement($this->cms, new HtmlSanitizer()));
        $legacy = new Page('home', 'Legacy home');
        $legacy->setStatus('published');
        $this->em->persist($legacy);
        $this->em->flush();
        self::assertSame($legacy->id(), $makeProvider()->findPublishedBySlug('home')?->id());
        $settings->setLive('cms.home_page_id', $this->page->id());
        self::assertSame($this->page->id(), $makeProvider()->findPublishedBySlug('home')?->id());
        $this->page->setStatus('draft');
        $this->em->persist(new ContentStagingEntry('cms.pages', 'page', $this->page->id(), 'update', ['production_snapshot' => ['status' => 'published', 'slug' => 'contact']], 'Contact'));
        $this->em->flush();
        self::assertSame($this->page->id(), $makeProvider()->findPublishedBySlug('home')?->id());
        $fresh = new Page('fresh', 'Fresh');
        $fresh->setStatus('published');
        $this->em->persist($fresh);
        $this->em->persist(new ContentStagingEntry('cms.pages', 'page', $fresh->id(), 'create', [], 'Fresh'));
        $this->em->flush();
        $settings->setLive('cms.home_page_id', $fresh->id());
        self::assertNull($makeProvider()->findPublishedBySlug('home'));
    }

    private function controller(): PublicFormController
    {
        $hooks = new HookManager();
        $translator = new PassthroughTranslator();
        $validation = new ValidationRegistry($hooks, $translator);
        FormSchema::registerValidation($validation, $translator);
        $validation->ruleset('test.submit', ['email' => 'required|email']);
        $store = new StagingStore($this->em);
        $registry = new PublishableRegistry();
        $settings = new Settings($this->em, new Repository(), $this->readContext, $registry, new StagingWriter($store, $registry));
        $provider = new CmsProvider($this->em, new HtmlSanitizer(), $this->readContext, new FrozenPublicReader($store), $settings, $hooks, new DynamicElement($this->cms, new HtmlSanitizer()));
        return new PublicFormController($provider, $this->cms, new FormSchema($this->cms, $validation, $translator), $this->readContext, new RateLimiter($this->limiterPath), new IdempotencyService($this->em), $this->em, $translator);
    }

    /** @param array<string, mixed> $values */
    private function request(array $values, string $key = 'test_submission_123456'): Request
    {
        return Request::create('/api/public/pages/contact/forms/' . $this->block->id(), 'POST', [], [], [], ['HTTP_IDEMPOTENCY_KEY' => $key, 'REMOTE_ADDR' => '127.0.0.1'], json_encode(['values' => $values], JSON_THROW_ON_ERROR));
    }

    public function testSubmitsAndReplaysWithoutInvokingTheActionTwice(): void
    {
        $controller = $this->controller();
        $request = $this->request(['email' => 'a@example.test']);
        $response = $controller->submit($request, 'contact', $this->block->id());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('{"data":{"status":"received"}}', $response->getContent());
        self::assertSame(201, $controller->submit($request, 'contact', $this->block->id())->getStatusCode());
        self::assertSame(1, $this->calls);
        self::assertSame(409, $controller->submit($this->request(['email' => 'other@example.test']), 'contact', $this->block->id())->getStatusCode());
    }

    public function testValidationNeverDispatchesAnAction(): void
    {
        $response = $this->controller()->submit($this->request(['email' => 'bad']), 'contact', $this->block->id());
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('email', json_decode((string) $response->getContent(), true)['error']['fields']);
        self::assertSame(0, $this->calls);
    }

    public function testPublishedSnapshotControlsSubmissionDespiteDraftEdits(): void
    {
        $snapshot = ['id' => $this->page->id(), 'slug' => 'contact', 'title' => 'Contact', 'status' => 'published', 'content_mode' => 'blocks', 'blocks' => [
            ['id' => $this->block->id(), 'type' => 'form', 'payload' => $this->block->payload()],
        ]];
        $entry = new ContentStagingEntry('cms.pages', 'page', $this->page->id(), ContentStagingEntry::ACTION_UPDATE, ['production_snapshot' => $snapshot], 'Contact');
        $this->block->setPayload(['action' => 'unpublished-action', 'children' => []]);
        $this->em->persist($entry);
        $this->em->flush();
        $this->readContext->useStaging();
        $response = $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->readContext->isStaging());
        self::assertSame(1, $this->calls);
    }

    public function testUnpublishedPagesAndMissingFormsCannotSubmit(): void
    {
        self::assertSame(404, $this->controller()->submit($this->request([]), 'contact', 'missing')->getStatusCode());
        $this->page->setStatus('draft');
        $this->em->flush();
        self::assertSame(404, $this->controller()->submit($this->request([]), 'contact', $this->block->id())->getStatusCode());
        self::assertSame(0, $this->calls);
    }
    public function testUnavailableActionAndRateLimitReturnExplicitErrors(): void
    {
        $payload = $this->block->payload();
        $limiter = new RateLimiter($this->limiterPath);
        for ($i = 0; $i < 10; $i++) {
            $limiter->hit('cms:form:127.0.0.1', 10, 600);
        }
        self::assertSame(429, $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id())->getStatusCode());
        $limiter->clear('cms:form:127.0.0.1');
        $payload['action'] = 'disabled';
        $this->block->setPayload($payload);
        $this->em->flush();
        self::assertSame(503, $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id())->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    public function testDeliveryStatusIsLoggedWithoutLeakingTheActionResult(): void
    {
        $response = $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id());
        self::assertSame('sent', $this->em->getConnection()->fetchOne('SELECT delivery_status FROM cms_form_attempts'));
        self::assertStringNotContainsString('delivery_status', (string) $response->getContent());
        self::assertStringNotContainsString('must-not-leak', (string) $response->getContent());
    }

    public function testFailedActionRollsBackItsDatabaseWritesAndDoesNotStoreSuccess(): void
    {
        $this->cms->registerFormAction('failure', 'Failure', 'test.submit', ['email' => 'email'], function (): array {
            $this->em->persist(new PageBlock($this->page->id(), 'text', 1, ['body' => 'Must roll back']));
            $this->em->flush();
            throw new \RuntimeException('Internal implementation details');
        });
        $payload = $this->block->payload();
        $payload['action'] = 'failure';
        $this->block->setPayload($payload);
        $this->em->flush();
        $response = $this->controller()->submit($this->request(['email' => 'a@example.test']), 'contact', $this->block->id());
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('Internal implementation details', (string) $response->getContent());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cms_page_blocks'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_idempotency'));
        self::assertSame('failed', $this->em->getConnection()->fetchOne('SELECT outcome FROM cms_form_attempts'));
    }
}
