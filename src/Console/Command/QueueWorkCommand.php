<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Queue\Queue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Process the job queue')]
final class QueueWorkCommand extends Command
{
    public function __construct(private readonly Queue $queue)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('GEREQ worker běží. Ctrl+C pro ukončení.');

        while (true) {
            $job = $this->queue->pop();
            if ($job === null) {
                sleep(1);
                continue;
            }

            try {
                $job->handle();
                $output->writeln('Hotovo: ' . $job::class);
            } catch (\Throwable $e) {
                $output->writeln('<error>Selhalo: ' . $e->getMessage() . '</error>');
            }
        }
    }
}
