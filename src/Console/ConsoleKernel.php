<?php

declare(strict_types=1);

namespace Gereq\Console;

use Gereq\Schedule\ScheduleCommandResolver;
use Gereq\Support\AppVersion;
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
            if (!class_exists($class)) {
                continue;
            }
            /** @var Command $command */
            $command = $this->container->get($class);
            $console->add($command);
        }

        return $console->run();
    }

    /** @return list<class-string<Command>> */
    private function commands(): array
    {
        return ScheduleCommandResolver::classes();
    }
}
