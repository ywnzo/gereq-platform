<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Gereq\Testing\TestCatalog;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleDiscovery;
use Gereq\Module\ModuleInterface;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUninstaller;
use Gereq\File\FileManager;
use Gereq\Module\ModuleManifest;
use Gereq\Module\ModuleInstalledSnapshot;
use Gereq\Module\ModuleUpdater;
use Gereq\Module\ModuleVersionActivator;
use Gereq\Module\ModuleVersionInstaller;
use Gereq\Module\ModuleVersionStore;
use Gereq\Module\GitLabModuleClient;
use Gereq\Config\Repository;
use Gereq\User\Entity\Permission;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\User\PermissionRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

final class SubmoduleTest extends TestCase
{
    private string $root;

    private EntityManager $em;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Modules\\Bundle\\BundleModule', false)) {
            class_alias(SubmoduleBundleFixture::class, 'Modules\\Bundle\\BundleModule');
            class_alias(SubmoduleFeatureFixture::class, 'Modules\\Bundle\\Submodules\\Feature\\FeatureModule');
        }
        $this->root = sys_get_temp_dir() . '/gereq-submodules-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules/Bundle/Submodules/Feature', 0775, true);
        $this->manifest('modules/Bundle', 'bundle', 'Modules\\Bundle\\BundleModule', ['Submodules/Feature']);
        $this->manifest('modules/Bundle/Submodules/Feature', 'bundle_feature', 'Modules\\Bundle\\Submodules\\Feature\\FeatureModule');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testDiscoveryAddsParentDependencyAndNestedNamespace(): void
    {
        $manifests = ModuleDiscovery::discover($this->root);
        self::assertSame(['bundle', 'bundle_feature'], array_keys($manifests));
        self::assertSame('bundle', $manifests['bundle_feature']->parentId);
        self::assertSame(['bundle' => '*'], $manifests['bundle_feature']->requires);
        self::assertSame('Modules\\Bundle\\Submodules\\Feature\\', $manifests['bundle_feature']->phpNamespace());
    }

    public function testCannotEnableWithoutParent(): void
    {
        $manager = $this->manager();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modules.error.missing_deps');
        $manager->enable('bundle_feature');
    }

    public function testOrphanedEnabledRowIsNeverActive(): void
    {
        $manager = $this->manager(false, true);
        self::assertFalse($manager->isEnabled('bundle_feature'));
        self::assertSame([], $manager->enabledIds());
    }

    public function testParentRequiresChildFirstWhenDisabling(): void
    {
        $manager = $this->manager(true, true);
        self::assertSame(['bundle', 'bundle_feature'], $manager->sortForEnable(['bundle_feature', 'bundle']));
        self::assertSame(['bundle_feature', 'bundle'], $manager->sortForDisable(['bundle', 'bundle_feature']));
        self::assertSame(['bundle', 'bundle_feature'], $manager->expandWithDependents(['bundle']));
        $this->expectException(\RuntimeException::class);
        $manager->disable('bundle');
    }

    public function testUninstallExpansionIncludesPausedChildren(): void
    {
        $manager = $this->manager(true, false);
        self::assertSame(['bundle'], $manager->expandWithDependents(['bundle']));
        self::assertSame(['bundle', 'bundle_feature'], $manager->expandWithDependents(['bundle'], true));
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleManifest::fromArray(['id' => 'bad', 'class' => 'Bad', 'submodules' => ['../Other']], '/tmp');
    }

    public function testRejectsDeclarationMismatch(): void
    {
        $this->manifest('modules/Bundle', 'bundle', 'Modules\\Bundle\\BundleModule', ['Submodules/Other']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SUBMODULES');
        ModuleDiscovery::discover($this->root);
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->manifest('modules/Bundle/Submodules/Feature', 'bundle', 'Modules\\Bundle\\Submodules\\Feature\\FeatureModule');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate');
        ModuleDiscovery::discover($this->root);
    }

    public function testPauseCascadesAndChildCanPauseIndependently(): void
    {
        $manager = $this->manager(true, true);
        self::assertSame(['ok' => 1, 'errors' => []], $manager->pauseMany(['bundle_feature']));
        self::assertTrue($manager->isEnabled('bundle'));
        self::assertFalse($manager->isEnabled('bundle_feature'));
        self::assertSame(['ok' => 1, 'errors' => []], $manager->pauseMany(['bundle']));
        self::assertFalse($manager->isEnabled('bundle'));

        $manager = $this->manager(true, true);
        self::assertSame(['ok' => 2, 'errors' => []], $manager->pauseMany(['bundle']));
        self::assertSame([], $manager->enabledIds());
    }

    public function testSchemaAndRollbackBelongToIndividualPackages(): void
    {
        $suffix = bin2hex(random_bytes(6));
        foreach (['Parent' => 'modules/Bundle', 'Child' => 'modules/Bundle/Submodules/Feature'] as $name => $path) {
            mkdir($this->root . '/' . $path . '/Domain', 0775, true);
            $class = $name . $suffix;
            $namespace = $name === 'Parent' ? 'Modules\\Bundle\\Domain' : 'Modules\\Bundle\\Submodules\\Feature\\Domain';
            file_put_contents($this->root . '/' . $path . '/Domain/' . $class . '.php', <<<PHP
<?php
namespace {$namespace};
#[\Doctrine\ORM\Mapping\Entity]
#[\Doctrine\ORM\Mapping\Table(name: 'test_{$name}')]
class {$class}
{
    #[\Doctrine\ORM\Mapping\Id]
    #[\Doctrine\ORM\Mapping\Column(type: 'integer')]
    public int \$id = 1;
}
PHP);
        }
        $manager = $this->manager(true, false);
        $migrator = new Migrator($this->em, $manager);
        self::assertCount(1, $migrator->metadataForModule('bundle'));
        self::assertCount(1, $migrator->metadataForModule('bundle_feature'));
        $schema = $this->em->getConnection()->createSchemaManager();
        // The fixture initially creates all mapped tables; remove them before testing enabled-only sync.
        $migrator->rollbackModule('bundle');
        $migrator->rollbackModule('bundle_feature');
        $migrator->syncSchema();
        self::assertTrue($schema->tablesExist(['test_Parent']));
        self::assertFalse($schema->tablesExist(['test_Child']));

        $child = $manager->installed('bundle_feature');
        self::assertNotNull($child);
        $child->enable();
        $this->em->flush();
        $manager->rediscover();
        $migrator->syncSchema();
        self::assertTrue($schema->tablesExist(['test_Child']));
        $this->em->getConnection()->insert('test_Parent', ['id' => 42]);
        $manager->pauseMany(['bundle_feature']);
        $migrator->rollbackModule('bundle_feature');
        self::assertFalse($schema->tablesExist(['test_Child']));
        self::assertSame(42, (int) $this->em->getConnection()->fetchOne('SELECT id FROM test_Parent'));
    }

    public function testTestingEngineDiscoversChildTests(): void
    {
        $path = $this->root . '/modules/Bundle/Submodules/Feature/Tests';
        mkdir($path);
        $catalog = new TestCatalog($this->root);
        self::assertContains($path, $catalog->modules()->paths);
        self::assertSame([$path], $catalog->module('bundle_feature')->paths);
    }

    public function testBootRegistersParentBeforeChildAndSkipsOrphans(): void
    {
        $context = (new \ReflectionClass(ModuleContext::class))->newInstanceWithoutConstructor();
        SubmoduleBundleFixture::$calls = [];
        $manager = $this->manager(false, true);
        $manager->boot($context);
        self::assertSame([], SubmoduleBundleFixture::$calls);
        $manager = $this->manager(true, true);
        $manager->boot($context);
        self::assertSame(['parent.register', 'child.register', 'parent.boot', 'child.boot'], SubmoduleBundleFixture::$calls);
    }

    public function testUninstallParentAlsoUninstallsPausedChild(): void
    {
        $manager = $this->manager(true, false);
        $migrator = new Migrator($this->em, $manager);
        $files = new FileManager($this->em, new Repository([]), $this->root, new PassthroughTranslator());
        $uninstaller = new ModuleUninstaller($manager, $migrator, $this->em, $files);
        self::assertSame(['ok' => 2, 'errors' => []], $uninstaller->uninstallMany(['bundle']));
        self::assertFalse($manager->isEnabled('bundle'));
        self::assertFileExists($this->root . '/modules/Bundle/Submodules/Feature/module.php');
    }

    public function testFailedChildRollbackRetainsParent(): void
    {
        $manager = $this->manager(true, true);
        $directory = $this->root . '/modules/Bundle/Submodules/Feature/Database/Migrations';
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/2026_10_16_000001_failure.php', <<<'PHP'
<?php
return new class {
    public function up(\Doctrine\DBAL\Schema\Schema $schema): void
    {
    }
    public function down(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        throw new \RuntimeException('Rollback failed');
    }
};
PHP);
        $migrator = new Migrator($this->em, $manager);
        $migrator->migrateModule('bundle_feature');
        $files = new FileManager($this->em, new Repository([]), $this->root, new PassthroughTranslator());
        $uninstaller = new ModuleUninstaller($manager, $migrator, $this->em, $files);
        $result = $uninstaller->uninstallMany(['bundle']);
        self::assertSame(0, $result['ok']);
        self::assertCount(2, $result['errors']);
        self::assertTrue($manager->isEnabled('bundle'));
    }

    public function testBundleActivationUpdatesChildrenAndRejectsSeparateActivation(): void
    {
        $manager = $this->manager(true, true);
        $target = 'storage/modules/bundle/0.2.0';
        mkdir($this->root . '/' . $target . '/Submodules/Feature', 0775, true);
        $this->manifest($target, 'bundle', 'Modules\\Bundle\\BundleModule', ['Submodules/Feature'], '0.2.0');
        $this->manifest($target . '/Submodules/Feature', 'bundle_feature', 'Modules\\Bundle\\Submodules\\Feature\\FeatureModule', [], '0.2.0');
        $migrationDir = $this->root . '/' . $target . '/Submodules/Feature/Database/Migrations';
        mkdir($migrationDir, 0775, true);
        file_put_contents($migrationDir . '/2026_10_16_000001_feature.php', <<<'PHP'
<?php
return new class {
    public function up(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $schema->createTable('bundle_extra')->addColumn('id', 'integer');
    }
    public function down(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $schema->dropTable('bundle_extra');
    }
};
PHP);
        [$activator, $installer] = $this->versionServices($manager);
        $activator->activate('bundle', '0.2.0', false);
        self::assertSame('0.2.0', $manager->installed('bundle')?->version());
        self::assertSame('0.2.0', $manager->installed('bundle_feature')?->version());
        self::assertTrue($manager->isEnabled('bundle_feature'));
        self::assertTrue($this->em->getConnection()->createSchemaManager()->tablesExist(['bundle_extra']));

        $activator->activate('bundle', '0.1.0', false);
        self::assertSame('0.1.0', $manager->installed('bundle_feature')->version());
        self::assertFalse($this->em->getConnection()->createSchemaManager()->tablesExist(['bundle_extra']));
        self::assertSame('0.1.0', $manager->manifest('bundle')?->version);

        try {
            $installer->fetch('bundle_feature', '0.2.0');
            self::fail('Separate child downloads must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('modules.error.submodule_versions', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modules.error.submodule_versions');
        $activator->activate('bundle_feature', '0.2.0', false);
    }

    public function testBundleCannotSilentlyRemoveAnInstalledChild(): void
    {
        $manager = $this->manager(true, false);
        mkdir($this->root . '/storage/modules/bundle/0.2.0', 0775, true);
        $this->manifest('storage/modules/bundle/0.2.0', 'bundle', 'Modules\\Bundle\\BundleModule', [], '0.2.0');
        [$activator] = $this->versionServices($manager);
        try {
            $activator->activate('bundle', '0.2.0', false);
            self::fail('Removing a child with retained data must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('modules.error.submodule_bundle_update', $e->getMessage());
        }
        self::assertFileExists($this->root . '/modules/Bundle/Submodules/Feature/module.php');
        self::assertSame('0.1.0', $manager->manifest('bundle')?->version);
    }

    /** @return array{ModuleVersionActivator, ModuleVersionInstaller} */
    private function versionServices(ModuleManager $manager): array
    {
        $config = new Repository([]);
        $translator = new PassthroughTranslator();
        $auditor = new Auditor($this->em, new RequestStack());
        $store = new ModuleVersionStore($this->root, $config, $translator);
        $installer = new ModuleVersionInstaller(new GitLabModuleClient($config, $translator), $store, $manager, $config, $translator);
        $migrator = new Migrator($this->em, $manager);
        $updater = new ModuleUpdater($manager, $migrator, $this->em, $auditor, $translator, new ModuleInstalledSnapshot($this->root));

        return [new ModuleVersionActivator($this->root, $manager, $store, $installer, $migrator, $updater, $this->em, $auditor, $translator), $installer];
    }

    /** @param list<string> $children */
    private function manifest(string $path, string $id, string $class, array $children = [], string $version = '0.1.0'): void
    {
        file_put_contents($this->root . '/' . $path . '/module.php', '<?php return ' . var_export([
            'id' => $id,
            'class' => $class,
            'submodules' => $children,
            'version' => $version,
        ], true) . ';');
    }

    private function manager(bool $parent = false, bool $child = false): ModuleManager
    {
        $paths = [dirname(__DIR__, 3) . '/src/Module', dirname(__DIR__, 3) . '/src/Audit'];
        foreach (['modules/Bundle/Domain', 'modules/Bundle/Submodules/Feature/Domain'] as $relative) {
            if (is_dir($this->root . '/' . $relative)) {
                $paths[] = $this->root . '/' . $relative;
            }
        }
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $config->enableNativeLazyObjects(true);
        $em = $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $em->getClassMetadata(Permission::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new InstalledModule('bundle', '0.1.0', $parent));
        $em->persist(new InstalledModule('bundle_feature', '0.1.0', $child));
        $em->flush();

        return new ModuleManager(
            $this->root,
            $em,
            new PermissionRegistry(),
            new Auditor($em, new RequestStack()),
            new PassthroughTranslator(),
        );
    }
}

final class SubmoduleBundleFixture implements ModuleInterface
{
    public const SUBMODULES = ['Submodules/Feature'];

    /** @var list<string> */
    public static array $calls = [];

    public function id(): string
    {
        return 'bundle';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(ModuleContext $context): void
    {
        self::$calls[] = 'parent.register';
    }

    public function boot(ModuleContext $context): void
    {
        self::$calls[] = 'parent.boot';
    }
}

final class SubmoduleFeatureFixture implements ModuleInterface
{
    public function id(): string
    {
        return 'bundle_feature';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(ModuleContext $context): void
    {
        SubmoduleBundleFixture::$calls[] = 'child.register';
    }

    public function boot(ModuleContext $context): void
    {
        SubmoduleBundleFixture::$calls[] = 'child.boot';
    }
}
