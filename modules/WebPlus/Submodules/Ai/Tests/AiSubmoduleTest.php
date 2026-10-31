<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleDiscovery;
use Gereq\Module\ModuleManager;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\User\PermissionRegistry;
use Modules\WebPlus\Submodules\Ai\AiModule;
use Modules\WebPlus\Submodules\Ai\Domain\AgentTool;
use Symfony\Component\HttpFoundation\RequestStack;

final class AiSubmoduleTest extends TestCase
{
    public function testAiIsBundledUnderWebPlusWithStableIdentity(): void
    {
        $manifests = ModuleDiscovery::discover(dirname(__DIR__, 5));
        $manifest = $manifests['webplus_ai'];
        self::assertSame('webplus', $manifest->parentId);
        self::assertSame('AI', $manifest->name);
        self::assertSame(AiModule::class, $manifest->moduleClass);
        self::assertSame((new AiModule())->version(), $manifest->version);
        self::assertNull($manifest->repository);
        self::assertSame(['Submodules/Ai'], $manifests['webplus']->submodules);
        self::assertSame(dirname(__DIR__), $manifest->path);
    }

    public function testMovedEntityReadsTheExistingTable(): void
    {
        $em = $this->entityManager();
        $tool = new AgentTool('legacy_lookup', 'Existing tool', 'Existing data');
        $em->persist($tool);
        $em->flush();
        $em->clear();
        $loaded = $em->getRepository(AgentTool::class)->find($tool->id());
        self::assertInstanceOf(AgentTool::class, $loaded);
        self::assertSame('legacy_lookup', $loaded->name());
        self::assertSame('webplus_ai_tools', $em->getClassMetadata(AgentTool::class)->getTableName());
    }

    public function testInstalledStateAndMigrationHistorySurviveTheMove(): void
    {
        $em = $this->entityManager();
        $em->persist(new InstalledModule('webplus', '2.0.1', true));
        $em->persist(new InstalledModule('webplus_ai', '1.99.4', true));
        $em->flush();
        $manager = new ModuleManager(
            dirname(__DIR__, 5),
            $em,
            new PermissionRegistry(),
            new Auditor($em, new RequestStack()),
            new PassthroughTranslator(),
        );
        $connection = $em->getConnection();
        $connection->executeStatement('CREATE TABLE schema_migrations (version VARCHAR(180) PRIMARY KEY, ran_at VARCHAR(40) NOT NULL)');
        $connection->insert('schema_migrations', [
            'version' => 'webplus_ai:2026_09_24_000001_webplus_ai', 'ran_at' => '2026-09-24 00:00:00',
        ]);
        $migrator = new Migrator($em, $manager);
        self::assertTrue($manager->isEnabled('webplus_ai'));
        self::assertSame('1.99.4', $manager->installed('webplus_ai')?->version());
        self::assertTrue($migrator->statusForModule('webplus_ai')[0]['ran']);
        self::assertCount(1, $migrator->metadataForModule('webplus_ai'));
        self::assertNotContains(AgentTool::class, array_map(
            static fn ($metadata): string => $metadata->getName(),
            $migrator->metadataForModule('webplus'),
        ));
    }

    private function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $metadata = [$em->getClassMetadata(AgentTool::class), $em->getClassMetadata(InstalledModule::class)];
        (new SchemaTool($em))->createSchema($metadata);

        return $em;
    }
}
