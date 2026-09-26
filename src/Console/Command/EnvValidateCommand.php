<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Env\EnvSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'env:validate',
    description: 'Validate .env against config/env.schema.toml',
)]
final class EnvValidateCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $schema = EnvSchema::load($this->basePath . '/config/env.schema.toml');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $problems = $schema->validateEnvFile($this->basePath . '/.env');
        if ($problems === []) {
            $output->writeln('<info>✓</info> .env matches env.schema.toml');

            return Command::SUCCESS;
        }

        foreach ($problems as $problem) {
            $output->writeln('<error>•</error> ' . $problem);
        }

        return Command::FAILURE;
    }
}
