<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Http;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasswordHasher;
use Gereq\Config\Repository;
use Gereq\Database\Migrator;
use Gereq\Http\Middleware\AutoUpdateModulesMiddleware;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUpdater;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\User\Entity\User;
use Gereq\User\PermissionRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AutoUpdateModulesMiddlewareTest extends TestCase
{
    private string $tmp = '';
    private InstalledModule $enabled;
    private InstalledModule $disabled;

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testUpdatesEnabledModuleAndRunsMigrationOnlyOnce(): void
    {
        [$middleware, $request] = $this->fixture();
        $next = static fn (Request $request): Response => new Response('ok');
        self::assertSame('ok', $middleware->process($request, $next)->getContent());
        self::assertSame('2.0.0', $this->enabled->version());
        self::assertNotNull($this->enabled->footprint());
        self::assertSame('1.0.0', $this->disabled->version());
        $middleware->process($request, $next);
        self::assertSame('ran', file_get_contents($this->tmp . '/alpha.ran'));
        self::assertFileDoesNotExist($this->tmp . '/beta.ran');
    }

    public function testDisabledOptionDoesNotUpdate(): void
    {
        $this->assertSkipped('local', false, true);
    }

    public function testProductionDoesNotUpdateEvenWhenEnabled(): void
    {
        $this->assertSkipped('production', true, true);
    }

    public function testUserWithoutModulePermissionDoesNotUpdate(): void
    {
        $this->assertSkipped('local', true, false);
    }

    public function testConcurrentRequestDoesNotRunUpdates(): void
    {
        [$middleware, $request] = $this->fixture();
        $lock = fopen($this->tmp . '/storage/framework/modules-auto-update.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $middleware->process($request, static fn (Request $request): Response => new Response('ok'));
            self::assertSame('1.0.0', $this->enabled->version());
            self::assertFileDoesNotExist($this->tmp . '/alpha.ran');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testFailedMigrationKeepsOldVersionAndReleasesLock(): void
    {
        [$middleware, $request] = $this->fixture();
        file_put_contents(
            $this->tmp . '/modules/Alpha/Database/Migrations/2026_01_01_init.php',
            '<?php return new class { public function up($schema): void { throw new \\RuntimeException("migration failed"); } };'
        );
        try {
            $middleware->process($request, static fn (Request $request): Response => new Response('stale'));
            self::fail('Failed migrations must not continue to the module UI.');
        } catch (\RuntimeException $exception) {
            self::assertSame('migration failed', $exception->getMessage());
        }
        self::assertSame('1.0.0', $this->enabled->version());
        $lock = fopen($this->tmp . '/storage/framework/modules-auto-update.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function assertSkipped(string $environment, bool $enabled, bool $permission): void
    {
        [$middleware, $request] = $this->fixture($environment, $enabled, $permission);
        $response = $middleware->process($request, static fn (Request $request): Response => new Response('ok'));
        self::assertSame('ok', $response->getContent());
        self::assertSame('1.0.0', $this->enabled->version());
        self::assertFileDoesNotExist($this->tmp . '/alpha.ran');
        self::assertFileDoesNotExist($this->tmp . '/storage/framework/modules-auto-update.lock');
    }

    /** @return array{AutoUpdateModulesMiddleware, Request} */
    private function fixture(string $environment = 'local', bool $enabled = true, bool $permission = true): array
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-auto-modules-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/storage/framework', 0775, true);
        foreach (['alpha', 'beta'] as $id) {
            $root = $this->tmp . '/modules/' . ucfirst($id);
            mkdir($root . '/Database/Migrations', 0775, true);
            file_put_contents($root . '/module.php', '<?php return ' . var_export([
                'id' => $id, 'name' => $id, 'version' => '2.0.0', 'class' => 'UnusedModule',
            ], true) . ';');
            $marker = var_export($this->tmp . '/' . $id . '.ran', true);
            file_put_contents(
                $root . '/Database/Migrations/2026_01_01_init.php',
                '<?php return new class { public function up($schema): void { file_put_contents(' . $marker . ', "ran", FILE_APPEND); } };'
            );
        }
        $this->enabled = new InstalledModule('alpha', '1.0.0', true);
        $this->disabled = new InstalledModule('beta', '1.0.0', false);
        $moduleRepo = $this->createMock(EntityRepository::class);
        $moduleRepo->method('findAll')->willReturn([$this->enabled, $this->disabled]);
        $settingsRepo = $this->createMock(EntityRepository::class);
        $settingsRepo->method('findAll')->willReturn([
            new SettingRecord(AutoUpdateModulesMiddleware::SETTING_ENABLED, $enabled ? '1' : '0', SettingRecord::TYPE_BOOLEAN),
        ]);
        $permissionsRepo = $this->createMock(EntityRepository::class);
        $permissionsRepo->method('findAll')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            InstalledModule::class => $moduleRepo,
            SettingRecord::class => $settingsRepo,
            default => $permissionsRepo,
        });
        $metadata = $this->createMock(ClassMetadataFactory::class);
        $metadata->method('getAllMetadata')->willReturn([]);
        $em->method('getMetadataFactory')->willReturn($metadata);
        $em->method('getConfiguration')->willReturn(new Configuration());
        $em->method('getConnection')->willReturn(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('can')->with('modules.manage')->willReturn($permission);
        $em->method('find')->with(User::class, 'user')->willReturn($user);
        $request = Request::create('/admin/settings');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('auth.user_id', 'user');
        $stack = new RequestStack();
        $stack->push($request);
        $auditor = new Auditor($em, $stack);
        $translator = new PassthroughTranslator();
        $modules = new ModuleManager($this->tmp, $em, new PermissionRegistry(), $auditor, $translator);
        $updater = new ModuleUpdater($modules, new Migrator($em, $modules), $em, $auditor, $translator);
        $config = new Repository(['app' => ['env' => $environment]]);
        $publishables = new PublishableRegistry();
        $settings = new Settings(
            $em,
            $config,
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables)
        );
        $auth = new Authenticator($em, $stack, new PasswordHasher(), $auditor);

        return [new AutoUpdateModulesMiddleware($settings, $config, $auth, $modules, $updater, $this->tmp), $request];
    }

    private function removeTree(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
