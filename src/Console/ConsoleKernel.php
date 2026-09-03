<?php

declare(strict_types=1);

namespace Gereq\Console;

use Gereq\Console\Command\AboutCommand;
use Gereq\Console\Command\CacheClearCommand;
use Gereq\Console\Command\MakeModuleCommand;
use Gereq\Console\Command\MakeResourceCommand;
use Gereq\Console\Command\MigrateCommand;
use Gereq\Console\Command\MigrateStatusCommand;
use Gereq\Console\Command\ModuleDisableCommand;
use Gereq\Console\Command\ModuleEnableCommand;
use Gereq\Console\Command\ModuleListCommand;
use Gereq\Console\Command\QueueWorkCommand;
use Gereq\Console\Command\ServeCommand;
use Gereq\Console\Command\UserCreateCommand;
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
        $console = new ConsoleApplication('GEREQ Platform', (string) config('app.version', '0.1.0'));
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
            ModuleListCommand::class,
            ModuleEnableCommand::class,
            ModuleDisableCommand::class,
            MakeModuleCommand::class,
            MakeResourceCommand::class,
            MigrateCommand::class,
            MigrateStatusCommand::class,
            CacheClearCommand::class,
            QueueWorkCommand::class,
            UserCreateCommand::class,
            ServeCommand::class,
        ];
    }
}
