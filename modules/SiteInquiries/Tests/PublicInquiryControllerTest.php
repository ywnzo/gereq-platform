<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Config\Repository;
use Gereq\Customers\Application\LeadIntake;
use Gereq\Customers\Domain\Customer;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Security\RateLimiter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationRegistry;
use Modules\SiteInquiries\Application\InquiryIntake;
use Modules\SiteInquiries\Domain\Inquiry;
use Modules\SiteInquiries\Http\Public\PublicInquiryController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;

final class PublicInquiryControllerTest extends TestCase
{
    private string $limiterPath;
    private EntityManager $em;
    private PublicInquiryController $controller;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain', dirname(__DIR__, 3) . '/src/Customers/Domain', dirname(__DIR__, 3) . '/src/Setting'], true);
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema(array_map(fn (string $class) => $this->em->getClassMetadata($class), [Inquiry::class, Customer::class, SettingRecord::class]));
        $registry = new PublishableRegistry();
        $settings = new Settings($this->em, new Repository(), new ContentReadContext(), $registry, new StagingWriter(new StagingStore($this->em), $registry));
        $translator = new PassthroughTranslator();
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $hooks = new HookManager();
        $sender = new \Modules\SiteInquiries\Application\InquiryNotificationSender($settings, $mailer, new Repository(), $translator, new \Gereq\Cms\Cms(self::createStub(\Gereq\Module\EnabledModulesProvider::class)));
        $outbox = new \Modules\SiteInquiries\Application\InquiryNotificationOutbox($this->em, $sender, new \Gereq\Cms\Cms(self::createStub(\Gereq\Module\EnabledModulesProvider::class)));
        $intake = new InquiryIntake($this->em, new EventBus($hooks), $settings, $translator, new LeadIntake($this->em), $outbox);
        $validation = new ValidationRegistry($hooks, $translator);
        $validation->ruleset('site_inquiries.public', ['name' => 'required|min:2|max:180', 'email' => 'required|email|max:180', 'phone' => 'required|max:40', 'details' => 'required|min:5|max:5000']);
        $this->limiterPath = sys_get_temp_dir() . '/inquiry-audit-' . bin2hex(random_bytes(8));
        $this->controller = new PublicInquiryController($intake, new RateLimiter($this->limiterPath), $settings, $validation, $translator);
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

    public function testPublicReceiptDoesNotExposeCustomerOrNotificationData(): void
    {
        $request = Request::create('/api/public/site/inquiries', 'POST', [], [], [], [], json_encode(['name' => 'Alice', 'email' => 'a@example.test', 'phone' => '123', 'details' => 'Contact me.'], JSON_THROW_ON_ERROR));
        $response = $this->controller->store($request);
        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(['id', 'status'], array_keys($body['data']));
        self::assertSame(1, $this->em->getRepository(Inquiry::class)->count([]));
    }

    public function testMalformedAndInvalidValuesNeverReachIntake(): void
    {
        $array = Request::create('/api/public/site/inquiries', 'POST', [], [], [], [], '{"name":["nested"]}');
        self::assertSame(400, $this->controller->store($array)->getStatusCode());
        $invalid = Request::create('/api/public/site/inquiries', 'POST', [], [], [], [], '{"name":"A","email":"bad","phone":"123","details":"x"}');
        self::assertSame(422, $this->controller->store($invalid)->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Inquiry::class)->count([]));
    }
}
