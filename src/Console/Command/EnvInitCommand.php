<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Env\CliEnvFile;
use Gereq\Env\EnvSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'env:init',
    description: 'Generate .env from config/env.schema.toml',
)]
final class EnvInitCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('docker', null, InputOption::VALUE_NONE, 'Apply Docker Compose service defaults (pgsql/redis)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing .env')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'APP_URL host port when using --docker', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaPath = $this->basePath . '/config/env.schema.toml';
        $envPath = CliEnvFile::resolvedPath() ?? ($this->basePath . '/.env');
        $envLabel = basename($envPath);

        if (is_file($envPath) && !$input->getOption('force')) {
            $output->writeln('<info>' . $envLabel . ' already exists</info> (use --force to overwrite)');

            return Command::SUCCESS;
        }

        try {
            $schema = EnvSchema::load($schemaPath);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $overrides = [];
        if ($input->getOption('docker')) {
            $port = (string) $input->getOption('port');
            $overrides = [
                'APP_DEBUG' => true,
                'APP_URL' => 'http://127.0.0.1:' . $port,
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'postgres',
                'DB_PORT' => 5432,
                'REDIS_HOST' => 'redis',
                'SESSION_DRIVER' => 'redis',
                'QUEUE_DRIVER' => 'redis',
                'CACHE_DRIVER' => 'redis',
            ];
        }

        $contents = $schema->renderEnv($overrides);
        $dir = dirname($envPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->writeln('<error>Failed to create directory for ' . $envLabel . '</error>');

            return Command::FAILURE;
        }
        if (file_put_contents($envPath, $contents) === false) {
            $output->writeln('<error>Failed to write ' . $envLabel . '</error>');

            return Command::FAILURE;
        }

        foreach (['cache', 'logs', 'sessions', 'tmp/queue', 'uploads', 'invoices', 'database'] as $dir) {
            $path = $this->basePath . '/storage/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        $output->writeln('<info>✓</info> Wrote ' . $envLabel . ' from env.schema.toml'
            . ($input->getOption('docker') ? ' (docker profile)' : ''));

        return Command::SUCCESS;
    }
}
