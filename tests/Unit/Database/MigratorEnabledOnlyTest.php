<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleManager;
use Gereq\Testing\TestCase;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ensures php gereq migrate never applies file migrations for disabled modules,
 * including when the modules table is still empty (fresh install).
 */
final class MigratorEnabledOnlyTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testMigrateRunsNothingFromModulesWhenNoneEnabled(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-migrator-' . bin2hex(random_bytes(4));
        $this->scaffoldModule('alpha', $this->tmp . '/markers/alpha.ran');
        $this->scaffoldModule('beta', $this->tmp . '/markers/beta.ran');

        $em = $this->sqliteEm();
        $modules = $this->moduleManager($em);
        $migrator = new Migrator($em, $modules);

        $messages = $migrator->migrate();

        self::assertFileDoesNotExist($this->tmp . '/markers/alpha.ran');
        self::assertFileDoesNotExist($this->tmp . '/markers/beta.ran');
        self::assertSame([], array_filter(
            $messages,
            static fn (string $line): bool => str_starts_with($line, 'Migrace '),
        ));
    }

    public function testMigrateRunsOnlyEnabledModuleMigrations(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-migrator-' . bin2hex(random_bytes(4));
        $this->scaffoldModule('alpha', $this->tmp . '/markers/alpha.ran');
        $this->scaffoldModule('beta', $this->tmp . '/markers/beta.ran');

        $em = $this->sqliteEm();
        $tool = new SchemaTool($em);
        $tool->updateSchema($em->getMetadataFactory()->getAllMetadata());

        $em->persist(new InstalledModule('alpha', '1.0.0', true));
        $em->persist(new InstalledModule('beta', '1.0.0', false));
        $em->flush();

        $modules = $this->moduleManager($em);
        $migrator = new Migrator($em, $modules);

        $messages = $migrator->migrate();

        self::assertFileExists($this->tmp . '/markers/alpha.ran');
        self::assertFileDoesNotExist($this->tmp . '/markers/beta.ran');
        self::assertNotSame([], array_filter(
            $messages,
            static fn (string $line): bool => str_contains($line, 'alpha:'),
        ));
        self::assertSame([], array_filter(
            $messages,
            static fn (string $line): bool => str_contains($line, 'beta:'),
        ));
    }

    private function scaffoldModule(string $id, string $markerPath): void
    {
        $moduleRoot = $this->tmp . '/modules/' . ucfirst($id);
        $migrationsDir = $moduleRoot . '/Database/Migrations';
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0775, true);
        }
        self::assertDirectoryExists($migrationsDir);

        $markerParent = dirname($markerPath);
        if (!is_dir($markerParent)) {
            mkdir($markerParent, 0775, true);
        }
        self::assertDirectoryExists($markerParent);

        $class = 'Modules\\' . ucfirst($id) . '\\' . ucfirst($id) . 'Module';
        file_put_contents($moduleRoot . '/module.php', <<<PHP
<?php

declare(strict_types=1);

return [
    'id' => '{$id}',
    'name' => '{$id}',
    'version' => '1.0.0',
    'class' => '{$class}',
];

PHP);

        $markerExport = var_export($markerPath, true);
        file_put_contents($migrationsDir . '/2026_01_01_000001_init.php', <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function up(\\Doctrine\\DBAL\\Schema\\Schema \$schema): void
    {
        file_put_contents({$markerExport}, '1');
    }

    public function down(\\Doctrine\\DBAL\\Schema\\Schema \$schema): void
    {
    }
};

PHP);
    }

    private function moduleManager(EntityManager $em): ModuleManager
    {
        $translator = new class () implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        return new ModuleManager(
            $this->tmp,
            $em,
            new PermissionRegistry(),
            new Auditor($em, new \Symfony\Component\HttpFoundation\RequestStack()),
            $translator,
        );
    }

    private function sqliteEm(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([
            dirname(__DIR__, 3) . '/src/Module',
        ], true);
        $config->enableNativeLazyObjects(true);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        return new EntityManager($conn, $config);
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
