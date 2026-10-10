<?php

declare(strict_types=1);

namespace Modules\Events\Console;

use Gereq\Queue\Queue;
use Modules\Vikendo\Application\VikendoImportJob;
use Modules\Events\Application\EventFeeds;
use Modules\Events\Application\Import\EventImportService;
use Modules\Events\Domain\EventFeed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'events:import', description: 'Import akcí z konfigurovaných feedů (ICS / JSON / HTML / ASP / Vikendo)')]
final class ImportEventsCommand extends Command
{
    public function __construct(
        private readonly EventImportService $import,
        private readonly EventFeeds $feeds,
        private readonly Queue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Klíč feedu, nebo all', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen stáhnout a spočítat, neukládat')
            ->addOption('no-archive', null, InputOption::VALUE_NONE, 'Nearchivovat akce chybějící ve zdroji')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Vikendo spustit synchronně (jinak fronta)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = strtolower(trim((string) $input->getOption('source')));
        $dryRun = (bool) $input->getOption('dry-run');
        $archive = !(bool) $input->getOption('no-archive');
        $sync = (bool) $input->getOption('sync');
        $keys = $source === 'all' ? null : [$source];

        $available = $this->import->sourceKeys();
        if ($available === []) {
            $output->writeln('<error>Žádné aktivní feedy. Přidejte je v adminu: Eventy → Zdroje.</error>');

            return Command::FAILURE;
        }

        if ($keys !== null) {
            foreach ($keys as $key) {
                if (!in_array($key, $available, true)) {
                    $output->writeln('<error>Neznámý nebo neaktivní zdroj: ' . $key . '</error>');
                    $output->writeln('Dostupné: ' . implode(', ', $available));

                    return Command::FAILURE;
                }
            }
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry-run — změny se neuloží.</comment>');
        }

        $runKeys = $keys ?? $available;
        $syncKeys = [];
        foreach ($runKeys as $key) {
            $feed = $this->feeds->findByKey($key);
            $isVikendo = $feed instanceof EventFeed && $feed->driver() === 'vikendo';
            if ($isVikendo && !$dryRun && !$sync && class_exists(VikendoImportJob::class)) {
                if (class_exists(\Modules\Vikendo\Application\VikendoLog::class)) {
                    \Modules\Vikendo\Application\VikendoLog::info('queue.dispatch', [
                        'source' => $key,
                        'archive_missing' => $archive,
                        'via' => 'cli.events:import',
                    ]);
                }
                $this->queue->dispatch(new VikendoImportJob($key, $archive));
                $output->writeln('<info>' . ($feed->name() ?? $key) . ' [' . $key . ']</info> — zařazeno do fronty (pozadí).');
                continue;
            }
            $syncKeys[] = $key;
        }

        if ($syncKeys === []) {
            return Command::SUCCESS;
        }

        $results = $this->import->importMany($syncKeys, $archive, $dryRun);
        $failed = false;
        foreach ($results as $result) {
            $label = ($result['name'] ?? $result['source']) . ' [' . $result['source'] . ']';
            $output->writeln(sprintf(
                '<info>%s</info> — staženo %d, vytvořeno %d, aktualizováno %d, přeskočeno %d, archivováno %d',
                $label,
                $result['fetched'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                $result['archived'],
            ));
            foreach ($result['errors'] as $error) {
                $failed = true;
                $output->writeln('  <error>' . $error . '</error>');
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
