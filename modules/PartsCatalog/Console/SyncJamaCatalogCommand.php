<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Console;

use Modules\PartsCatalog\Application\JamaCatalogSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'partscatalog:sync-jama',
    description: 'Stáhnout celý katalog autodily-jama.cz (značky, modely, kategorie, díly) do DB',
)]
final class SyncJamaCatalogCommand extends Command
{
    public function __construct(private readonly JamaCatalogSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('brand', null, InputOption::VALUE_REQUIRED, 'Jen jedna značka (slug nebo název, např. skoda)')
            ->addOption('structure-only', null, InputOption::VALUE_NONE, 'Jen značky + modely + motorizace (bez kategorií/dílů)')
            ->addOption('parts-only', null, InputOption::VALUE_NONE, 'Přeskočit discovery — sync kategorií/dílů z vozidel už v DB')
            ->addOption('limit-vehicles', null, InputOption::VALUE_REQUIRED, 'Max. počet vozidel pro sync kategorií/dílů')
            ->addOption('delay-ms', null, InputOption::VALUE_REQUIRED, 'Pauza mezi HTTP requesty (ms)', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $brand = $input->getOption('brand');
        $brand = is_string($brand) && $brand !== '' ? $brand : null;
        $structureOnly = (bool) $input->getOption('structure-only');
        $partsOnly = (bool) $input->getOption('parts-only');
        $limitRaw = $input->getOption('limit-vehicles');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : null;
        $delay = (int) $input->getOption('delay-ms');

        $output->writeln('<info>Spouštím sync Jama katalogu…</info>');
        if ($brand !== null) {
            $output->writeln('Filtr značky: ' . $brand);
        }
        if ($structureOnly) {
            $output->writeln('Režim: jen struktura (bez dílů)');
        }
        if ($partsOnly) {
            $output->writeln('Režim: parts-only (z DB)');
        }

        try {
            $stats = $this->sync->sync(
                onlyBrand: $brand,
                structureOnly: $structureOnly,
                partsOnly: $partsOnly,
                vehicleLimit: $limit,
                delayMs: $delay,
                logger: static function (string $msg) use ($output): void {
                    $output->writeln($msg);
                },
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Hotovo:</info> značky=%d, modely=%d, nová vozidla=%d, kategorie=%d, nové díly=%d, vazby=%d, done=%d, error=%d',
            $stats['brands'],
            $stats['models'],
            $stats['vehicles'],
            $stats['categories'],
            $stats['parts'],
            $stats['links'],
            $stats['vehicles_done'],
            $stats['vehicles_error'],
        ));
        if ($stats['soft_error'] !== null) {
            $output->writeln('<comment>Poslední soft error Jama: ' . $stats['soft_error'] . '</comment>');
        }

        return $stats['vehicles_error'] > 0 && $stats['vehicles_done'] === 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
