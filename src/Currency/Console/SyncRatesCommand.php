<?php

declare(strict_types=1);

namespace Gereq\Currency\Console;

use Gereq\Currency\Application\SyncExchangeRates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'currency:rates:sync', description: 'Download daily CNB rates into exchange_rates')]
final class SyncRatesCommand extends Command
{
    public function __construct(private readonly SyncExchangeRates $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Datum kurzů ČNB (d.m.Y nebo Y-m-d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asOf = null;
        $rawDate = $input->getOption('date');
        if (is_string($rawDate) && $rawDate !== '') {
            $asOf = $this->parseDate($rawDate);
            if ($asOf === null) {
                $output->writeln('<error>Neplatné datum. Použijte d.m.Y nebo Y-m-d.</error>');

                return Command::FAILURE;
            }
        }

        try {
            $result = $this->sync->sync($asOf);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s</info> — vytvořeno %d, aktualizováno %d, beze změny %d',
            $result['label'],
            $result['created'],
            $result['updated'],
            $result['skipped'],
        ));
        if ($result['valid_at'] !== null) {
            $output->writeln('Platnost: ' . $result['valid_at']);
        }

        foreach ($result['pairs'] as $pair) {
            $output->writeln(sprintf(
                '  %s/%s = %s (%s)',
                $pair['base'],
                $pair['quote'],
                rtrim(rtrim($pair['rate'], '0'), '.'),
                $pair['action'],
            ));
        }

        return Command::SUCCESS;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('Europe/Prague'));
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
        } catch (\Exception) {
            return null;
        }
    }
}
