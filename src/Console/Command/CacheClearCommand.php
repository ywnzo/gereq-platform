<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:clear', description: 'Vymazat cache')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->basePath . '/storage/cache';
        $this->wipe($dir);
        $output->writeln('Cache vymazána.');

        return Command::SUCCESS;
    }

    private function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->wipe($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
