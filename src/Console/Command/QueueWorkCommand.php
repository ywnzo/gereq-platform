<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Queue\JobRun;
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
        $output->writeln('GEREQ worker running. Ctrl+C to stop.');

        while (true) {
            try {
                $payload = $this->queue->popRaw();
            } catch (\Throwable $e) {
                $output->writeln('<error>Pop failed: ' . $e->getMessage() . '</error>');
                error_log('GEREQ queue:work pop failed: ' . $e->getMessage());
                sleep(3);
                continue;
            }

            if ($payload === null) {
                sleep(1);
                continue;
            }

            $job = $this->queue->unserializePayload($payload);
            if ($job === null) {
                $this->queue->failRaw($payload, 'unserialize_failed');
                $output->writeln('<error>Unreadable payload discarded.</error>');
                continue;
            }

            $output->writeln('Processing: ' . $job::class);
            try {
                $this->queue->runTracked($job, JobRun::TRIGGER_WORKER, $payload);
                $this->queue->clearFailure($payload);
                $output->writeln('Done: ' . $job::class);
            } catch (\Throwable $e) {
                $row = $this->queue->fail($job, $payload, $e);
                $attempts = $row?->attempts() ?? 0;
                $max = $this->queue->maxAttempts();
                $output->writeln(sprintf(
                    '<error>Failed: %s (%d/%d) — %s</error>',
                    $job::class,
                    $attempts,
                    $max,
                    $e->getMessage(),
                ));
                error_log('GEREQ queue:work job failed: ' . $job::class . ' — ' . $e->getMessage());
            }
        }
    }
}
