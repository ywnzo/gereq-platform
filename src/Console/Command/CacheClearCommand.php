<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Cache\AppCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:clear', description: 'Clear cache (app, Twig, Doctrine metadata)')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly string $basePath,
        private readonly AppCache $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->flush();
        $this->wipe($this->basePath . '/storage/cache');
        // Keep directory structure for next writes
        foreach (['data', 'twig', 'doctrine', 'rate'] as $subdir) {
            $path = $this->basePath . '/storage/cache/' . $subdir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
        $output->writeln('Cache vymazána (app + twig + doctrine + filesystem).');

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
