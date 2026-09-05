<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'twig:types',
    description: 'Vygenerovat Twig typy do .cache/twig/ (hover/completion/goto v IDE)',
)]
final class TwigTypesCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $script = $this->basePath . '/tools/gereq-twig-goto/index-templates.php';
        if (!is_file($script)) {
            $output->writeln('<error>Chybí tools/gereq-twig-goto/index-templates.php</error>');

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
            $output->writeln('<error>Nelze spustit indexer</error>');

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
}
