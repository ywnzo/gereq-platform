<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'twig:types',
    description: 'Generate Twig types into .cache/twig/ (hover/completion/goto in IDE)',
)]
final class TwigTypesCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $script = $this->resolveIndexerScript();
        if ($script === null) {
            $output->writeln('<error>Missing gereq-twig-goto/index-templates.php</error>');
            $output->writeln('Clone https://git.gereq.cz/gereq/GEREQ_TOOLS and set GEREQ_TOOLS to that path (or place it as a sibling of the platform).');

            return Command::FAILURE;
        }

        $cmd = [PHP_BINARY, $script];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, $this->basePath);
        if (!is_resource($proc)) {
            $output->writeln('<error>Cannot start indexer</error>');

            return Command::FAILURE;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($stdout !== '') {
            $output->write($stdout);
        }
        if ($stderr !== '') {
            $output->write('<error>' . $stderr . '</error>');
        }

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveIndexerScript(): ?string
    {
        $relative = 'gereq-twig-goto/index-templates.php';
        $candidates = [];

        $envTools = getenv('GEREQ_TOOLS');
        if (is_string($envTools) && $envTools !== '') {
            $candidates[] = rtrim($envTools, '/\\') . '/' . $relative;
        }

        $parent = dirname($this->basePath);
        $candidates[] = $parent . '/GEREQ_TOOLS/' . $relative;
        $candidates[] = $this->basePath . '/../GEREQ_TOOLS/' . $relative;
        // Legacy layout (removed submodule) — keep for local overrides
        $candidates[] = $this->basePath . '/tools/' . $relative;

        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }
}
