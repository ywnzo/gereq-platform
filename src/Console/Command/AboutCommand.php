<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Console\ConsoleBrand;
use Gereq\Module\ModuleManager;
use Gereq\Support\AppVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'about', description: 'Information about GEREQ Platform')]
final class AboutCommand extends Command
{
    public function __construct(private readonly ModuleManager $modules)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = (string) config('app.version', AppVersion::DEFAULT);
        $signal = ConsoleBrand::SIGNAL;
        $muted = ConsoleBrand::MUTED;
        $cyan = ConsoleBrand::CYAN;

        $io->writeln('');
        $io->writeln(ConsoleBrand::banner($version));
        $io->writeln(sprintf('  <fg=%s>Interní modulární backendová platforma</>', $muted));
        $io->writeln('');

        $rows = [
            ['PHP', PHP_VERSION],
            ['Prostředí', (string) config('app.env')],
            ['Databáze', (string) config('database.connection')],
            [
                'Moduly',
                sprintf(
                    '%d nalezeno · %d aktivních',
                    count($this->modules->discover()),
                    count($this->modules->enabledIds()),
                ),
            ],
        ];

        foreach ($rows as [$label, $value]) {
            $io->writeln(sprintf(
                '  <fg=%s;options=bold>%s</> <fg=%s>%s</>',
                $signal,
                mb_str_pad($label, 10),
                $cyan,
                $value,
            ));
        }

        $io->writeln('');

        return Command::SUCCESS;
    }
}
