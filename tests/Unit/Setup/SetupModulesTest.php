<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Setup;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Gereq\Module\ModuleManager;
use Gereq\Setup\SetupModules;
use Gereq\Setup\SetupModuleCatalog;
use Gereq\Config\Repository;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\User\Entity\Permission;
use Gereq\User\PermissionRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

final class SetupModulesTest extends TestCase
{
    private string $root;
    private ModuleManager $manager;
    private SetupModules $modules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/gereq-setup-' . bin2hex(random_bytes(6));
        $this->manifest('alpha');
        $this->manifest('beta', ['alpha' => '*']);
        $this->manifest('cycle_a', ['cycle_b' => '*']);
        $this->manifest('cycle_b', ['cycle_a' => '*']);
        $config = ORMSetup::createAttributeMetadataConfiguration([
            dirname(__DIR__, 3) . '/src/Module', dirname(__DIR__, 3) . '/src/Audit',
        ], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $em->getClassMetadata(Permission::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->manager = new ModuleManager($this->root, $em, new PermissionRegistry(), new Auditor($em, new RequestStack()), new PassthroughTranslator());
        $this->modules = new SetupModules($this->manager, new Migrator($em, $this->manager));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testDependenciesAreEnabledBeforeSelectedModule(): void
    {
        self::assertSame(['alpha', 'beta'], $this->modules->apply(['beta']));
        self::assertTrue($this->manager->isEnabled('alpha'));
        self::assertTrue($this->manager->isEnabled('beta'));
    }

    public function testEmptySelectionPausesPreviouslyEnabledModules(): void
    {
        $this->modules->apply(['beta']);
        self::assertSame([], $this->modules->apply([]));
        self::assertSame([], $this->manager->enabledIds());
        self::assertNotNull($this->manager->installed('beta'));
    }

    public function testRejectsUnknownModuleBeforeChangingAnything(): void
    {
        $this->modules->apply(['alpha']);
        try {
            $this->modules->apply(['missing']);
            self::fail('Unknown selection accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($this->manager->isEnabled('alpha'));
        }
    }

    public function testRejectsDependencyCycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->modules->resolve(['cycle_a']);
    }

    public function testCatalogIncludesEveryPackageAndMergesCategories(): void
    {
        $this->manifest('plug', [], 'integration');
        $this->manager->rediscover();
        $catalog = (new SetupModuleCatalog($this->manager, new Repository([
            'module_groups' => ['example' => ['label' => 'Example', 'modules' => ['alpha']]],
        ])))->catalog();
        self::assertCount(count($this->manager->discover()), $catalog['modules']);
        $items = [];
        foreach ($catalog['modules'] as $item) {
            $items[$item['manifest']->id] = $item;
        }
        self::assertArrayHasKey('plug', $items);
        self::assertSame(['example'], $items['alpha']['categories']);
        self::assertSame(['other'], $items['beta']['categories']);
        self::assertSame('Example', $catalog['categories']['example']);
        self::assertSame(['plug'], $this->modules->resolve(['plug']));
    }

    /** @param array<string, string> $requires */
    private function manifest(string $id, array $requires = [], string $kind = 'module'): void
    {
        $directory = $this->root . '/modules/' . $id;
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/module.php', '<?php return ' . var_export([
            'id' => $id, 'class' => 'Modules\\' . $id . '\\FixtureModule', 'requires' => $requires, 'kind' => $kind,
        ], true) . ';');
    }
}
