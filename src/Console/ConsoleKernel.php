<?php

declare(strict_types=1);

namespace Gereq\Console;

use Gereq\Console\Command\AboutCommand;
use Gereq\Console\Command\CacheClearCommand;
use Gereq\Console\Command\EnvInitCommand;
use Gereq\Console\Command\EnvValidateCommand;
use Gereq\Console\Command\ImportLaravelCommand;
use Gereq\Console\Command\ImportSqliteCommand;
use Gereq\Console\Command\InstallCommand;
use Gereq\Console\Command\MakeModuleCommand;
use Gereq\Console\Command\MakeResourceCommand;
use Gereq\Console\Command\MigrateCommand;
use Gereq\Console\Command\MigrateStatusCommand;
use Gereq\Console\Command\ModuleDisableCommand;
use Gereq\Console\Command\NnCommand;
use Gereq\Console\Command\ModuleEnableCommand;
use Gereq\Console\Command\ModulePauseCommand;
use Gereq\Console\Command\ModuleListCommand;
use Gereq\Console\Command\QueueWorkCommand;
use Gereq\Console\Command\SeedDemoCommand;
use Gereq\Console\Command\SeedRealityV3Command;
use Gereq\Console\Command\ServeCommand;
use Gereq\Console\Command\ServeDockerCommand;
use Gereq\Console\Command\TestCommand;
use Gereq\Console\Command\TwigTypesCommand;
use Gereq\Console\Command\UserCreateCommand;
use Gereq\Support\AppVersion;
use Modules\Currency\Console\SyncRatesCommand;
use Modules\CustomerUploads\Console\PurgeUploadsCommand;
use Modules\PartsCatalog\Console\SyncJamaCatalogCommand;
use Modules\Payments\Console\ImapMatchCommand;
use Modules\Portals\Console\SeedDemoPortalsCommand;
use Modules\Products\Console\ImportProductsCsvCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;

final class ConsoleKernel
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function run(): int
    {
        $console = new ConsoleApplication('GEREQ Platform', (string) config('app.version', AppVersion::DEFAULT));
        $console->setAutoExit(false);

        foreach ($this->commands() as $class) {
            /** @var Command $command */
            $command = $this->container->get($class);
            $console->add($command);
        }

        return $console->run();
    }

    /** @return list<class-string<Command>> */
    private function commands(): array
    {
        return [
            AboutCommand::class,
            InstallCommand::class,
            ModuleListCommand::class,
            ModuleEnableCommand::class,
            ModulePauseCommand::class,
            ModuleDisableCommand::class,
            MakeModuleCommand::class,
            MakeResourceCommand::class,
            MigrateCommand::class,
            MigrateStatusCommand::class,
            CacheClearCommand::class,
            QueueWorkCommand::class,
            UserCreateCommand::class,
            SeedDemoCommand::class,
            SeedRealityV3Command::class,
            ServeCommand::class,
            ServeDockerCommand::class,
            NnCommand::class,
            TestCommand::class,
            TwigTypesCommand::class,
            EnvInitCommand::class,
            EnvValidateCommand::class,
            SyncRatesCommand::class,
            SyncJamaCatalogCommand::class,
            ImportLaravelCommand::class,
            ImportSqliteCommand::class,
            ImportProductsCsvCommand::class,
            ImapMatchCommand::class,
            PurgeUploadsCommand::class,
            SeedDemoPortalsCommand::class,
        ];
    }
}
