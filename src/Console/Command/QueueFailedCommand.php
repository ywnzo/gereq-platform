<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Queue\FailedJob;
use Gereq\Queue\FailedJobStore;
use Gereq\Queue\Queue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:failed', description: 'Inspect, retry or forget failed jobs')]
final class QueueFailedCommand extends Command
{
    public function __construct(private readonly Queue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many records to list (default 20)')
            ->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Retry a job (id, uuid, or "all")')
            ->addOption('forget', null, InputOption::VALUE_REQUIRED, 'Delete a record (id or uuid)')
            ->addOption('prune', null, InputOption::VALUE_REQUIRED, 'Delete records older than N days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->queue->failedStore();
        if ($store === null) {
            $output->writeln('<error>failed_jobs není dostupné.</error>');

            return Command::FAILURE;
        }

        $prune = $input->getOption('prune');
        if (is_numeric($prune)) {
            $output->writeln(sprintf('Pročistěno %d záznamů.', $store->prune((int) $prune)));

            return Command::SUCCESS;
        }

        $forget = $input->getOption('forget');
        if (is_string($forget) && $forget !== '') {
            return $this->forget($store->find($forget), $output);
        }

        $retry = $input->getOption('retry');
        if (is_string($retry) && $retry !== '') {
            return $this->retry($store, strtolower($retry) === 'all' ? null : $retry, $output);
        }

        $this->render($store->recent(max(1, (int) ($input->getOption('limit') ?? 20))), $store->count(), $output);

        return Command::SUCCESS;
    }

    private function retry(FailedJobStore $store, ?string $id, OutputInterface $output): int
    {
        if ($id === null) {
            $rows = $store->recent(1000);
        } else {
            $found = $store->find($id);
            $rows = $found === null ? [] : [$found];
        }

        if ($rows === []) {
            $output->writeln('<comment>Nic k obnovení.</comment>');

            return Command::FAILURE;
        }

        $requeued = 0;
        foreach ($rows as $row) {
            if (!$store->requeue($row, $this->queue)) {
                $output->writeln(sprintf('<error>%s: payload nelze načíst, přeskočeno.</error>', $row->id()));
                continue;
            }

            $store->forget($row);
            $requeued++;
            $output->writeln(sprintf('Znovu spuštěno: %s (%s)', $row->job(), $row->id()));
        }

        $output->writeln(sprintf('Obnoveno %d úloh.', $requeued));

        return $requeued > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function forget(?FailedJob $failedJob, OutputInterface $output): int
    {
        if ($failedJob === null) {
            $output->writeln('<comment>Záznam nebyl nalezen.</comment>');

            return Command::FAILURE;
        }

        $store = $this->queue->failedStore();
        if ($store === null || !$store->forget($failedJob)) {
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Smazáno: %s', $failedJob->id()));

        return Command::SUCCESS;
    }

    /** @param list<FailedJob> $rows */
    private function render(array $rows, int $total, OutputInterface $output): void
    {
        if ($rows === []) {
            $output->writeln('<comment>failed_jobs je prázdná — žádná úloha neselhala.</comment>');

            return;
        }

        $output->writeln(sprintf('failed_jobs: %d záznamů, zobrazeno %d', $total, count($rows)));
        $output->writeln('');

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%s  %s  pokusů: %d  fronta: %s',
                $row->failedAt()->format('Y-m-d H:i:s'),
                $row->job() !== '' ? $row->job() : '(nerozpoznáto)',
                $row->attempts(),
                $row->queue(),
            ));
            $output->writeln('  id: ' . $row->id());
            $output->writeln('  chyba: ' . $this->firstLine($row->exception()));
            $output->writeln('');
        }
    }

    private function firstLine(string $exception): string
    {
        $line = trim(explode("\n", $exception)[0] ?? $exception);

        return mb_substr($line, 0, 200);
    }
}
