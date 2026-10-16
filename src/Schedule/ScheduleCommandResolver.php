<?php

declare(strict_types=1);

namespace Gereq\Schedule;

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
use Gereq\Console\Command\ModuleEnableCommand;
use Gereq\Console\Command\ModuleListCommand;
use Gereq\Console\Command\ModulePauseCommand;
use Gereq\Console\Command\NnCommand;
use Gereq\Console\Command\QueueFailedCommand;
use Gereq\Console\Command\QueueWorkCommand;
use Gereq\Console\Command\ScheduleListCommand;
use Gereq\Console\Command\ScheduleRunCommand;
use Gereq\Console\Command\SeedDemoCommand;
use Gereq\Console\Command\ServeCommand;
use Gereq\Console\Command\ServeDockerCommand;
use Gereq\Console\Command\StorefrontRebuildCommand;
use Gereq\Console\Command\TestCommand;
use Gereq\Console\Command\TwigTypesCommand;
use Gereq\Console\Command\UserCreateCommand;
use Gereq\Currency\Console\SyncRatesCommand;
use Modules\CustomerUploads\Console\PurgeUploadsCommand;
use Modules\Events\Console\ImportEventsCommand;
use Modules\PartsCatalog\Console\SyncJamaCatalogCommand;
use Modules\Payments\Console\ImapMatchCommand;
use Modules\Portals\Console\SeedDemoPortalsCommand;
use Modules\Products\Console\ImportProductsCsvCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Maps gereq console command names to their classes (same set as ConsoleKernel).
 */
final class ScheduleCommandResolver
{
    /** @return list<class-string<Command>> */
    public static function classes(): array
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
            QueueFailedCommand::class,
            ScheduleRunCommand::class,
            ScheduleListCommand::class,
            UserCreateCommand::class,
            SeedDemoCommand::class,
            ServeCommand::class,
            ServeDockerCommand::class,
            StorefrontRebuildCommand::class,
            NnCommand::class,
            TestCommand::class,
            TwigTypesCommand::class,
            EnvInitCommand::class,
            EnvValidateCommand::class,
            SyncRatesCommand::class,
            ImportEventsCommand::class,
            SyncJamaCatalogCommand::class,
            ImportLaravelCommand::class,
            ImportSqliteCommand::class,
            ImportProductsCsvCommand::class,
            ImapMatchCommand::class,
            PurgeUploadsCommand::class,
            SeedDemoPortalsCommand::class,
        ];
    }

    /** @return class-string<Command>|null */
    public static function classFor(string $name): ?string
    {
        foreach (self::classes() as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(AsCommand::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsCommand $attr */
            $attr = $attrs[0]->newInstance();
            if ($attr->name === $name) {
                return $class;
            }
        }

        return null;
    }
}
