<?php

declare(strict_types=1);

namespace Modules\Products\Console;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductPrice;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'products:import-csv', description: 'Import produktů z CSV (sku,name,price,currency,status)')]
final class ImportProductsCsvCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Cesta k CSV');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen spočítat');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        if ($file === '' || !is_readable($file)) {
            $output->writeln('<error>Zadejte --file.</error>');

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            $output->writeln('<error>Nelze otevřít soubor.</error>');

            return Command::FAILURE;
        }
        $header = fgetcsv($fh);
        if (!is_array($header)) {
            fclose($fh);
            $output->writeln('<error>Prázdný CSV.</error>');

            return Command::FAILURE;
        }
        $map = [];
        foreach ($header as $i => $col) {
            $map[strtolower(trim((string) $col))] = $i;
        }
        if (!isset($map['sku'], $map['name'])) {
            fclose($fh);
            $output->writeln('<error>CSV musí mít sloupce sku,name.</error>');

            return Command::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $sku = strtoupper(trim((string) ($row[$map['sku']] ?? '')));
            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($sku === '' || $name === '') {
                $skipped++;
                continue;
            }
            if ($this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]) instanceof Product) {
                $skipped++;
                continue;
            }
            if ($dry) {
                $created++;
                continue;
            }
            $product = new Product($sku, $name);
            $status = isset($map['status']) ? (string) ($row[$map['status']] ?? 'active') : 'active';
            if (in_array($status, Product::STATUSES, true)) {
                $product->setStatus($status);
            }
            $this->em->persist($product);
            if (isset($map['price']) && is_numeric($row[$map['price']] ?? null)) {
                $currency = isset($map['currency'])
                    ? strtoupper((string) ($row[$map['currency']] ?? 'CZK'))
                    : 'CZK';
                $amount = number_format((float) $row[$map['price']], 4, '.', '');
                $this->em->persist(new ProductPrice($product->id(), $currency, $amount));
            }
            $created++;
        }
        fclose($fh);
        if (!$dry) {
            $this->em->flush();
        }
        $output->writeln(sprintf('%s — created %d, skipped %d', $dry ? 'DRY-RUN' : 'IMPORT', $created, $skipped));

        return Command::SUCCESS;
    }
}
