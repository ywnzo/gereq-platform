<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Testing\TestEngine;
use Gereq\Testing\TestRunOptions;
use Gereq\Testing\TestTargetKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'test',
    description: 'Run the testing engine (all, core, modules, or a single module)',
)]
final class TestCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'all | core | file | modules | integrations | module:<id> | <module-id>',
                'all',
            )
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List available targets')
            ->addOption(
                'all-packages',
                null,
                InputOption::VALUE_NONE,
                'With --list, include modules/integrations that currently have zero tests',
            )
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'PHPUnit --filter pattern')
            ->addOption('stop-on-failure', null, InputOption::VALUE_NONE, 'Stop on first failure')
            ->addOption('testdox', null, InputOption::VALUE_NONE, 'Write output in TestDox format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = new TestEngine($this->basePath);

        if ((bool) $input->getOption('list')) {
            return $this->listTargets(
                $engine,
                $output,
                (bool) $input->getOption('all-packages'),
            );
        }

        $targetArg = (string) $input->getArgument('target');
        try {
            $target = $engine->resolve($targetArg);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Testing engine</info> → <comment>%s</comment> (%s, %d test file(s))',
            $target->id,
            $target->label,
            $target->testFileCount(),
        ));

        $options = new TestRunOptions(
            filter: $input->getOption('filter') !== null ? (string) $input->getOption('filter') : null,
            stopOnFailure: (bool) $input->getOption('stop-on-failure'),
            testdox: (bool) $input->getOption('testdox'),
        );

        try {
            $result = $engine->run($targetArg, $options);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return $result->ok() ? Command::SUCCESS : Command::FAILURE;
    }

    private function listTargets(TestEngine $engine, OutputInterface $output, bool $allPackages): int
    {
        $table = new Table($output);
        $table->setHeaders(['Target', 'Kind', 'Test files', 'Label']);

        foreach ($engine->listTargets(false) as $target) {
            $isPackage = $target->kind === TestTargetKind::Module
                || $target->kind === TestTargetKind::Integration;
            if ($isPackage && !$allPackages && !$target->hasTests()) {
                continue;
            }

            $table->addRow([
                $target->id,
                $target->kind->value,
                (string) $target->testFileCount(),
                $target->label,
            ]);
        }

        $table->render();
        $output->writeln('');
        $output->writeln('Examples:');
        $output->writeln('  php bin/gereq-test');
        $output->writeln('  php bin/gereq-test core');
        $output->writeln('  php bin/gereq-test modules');
        $output->writeln('  php bin/gereq-test module:shop');
        $output->writeln('  php gereq test shop');
        $output->writeln('  composer test -- --list');

        return Command::SUCCESS;
    }
}
