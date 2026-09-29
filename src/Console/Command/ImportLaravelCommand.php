<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Domain\Customer;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductPrice;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold ETL from Laravel / JSON dump (products + customers).
 * Expected JSON: { "products": [...], "customers": [...] }
 * Product keys: sku, name, description?, status?, unit?, price?, currency?
 * Customer keys: email, name, phone?
 */
#[AsCommand(name: 'migrate:import-laravel', description: 'Import products/customers from a Laravel JSON dump (scaffold)')]
final class ImportLaravelCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Cesta k JSON dumpu');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen validace bez zápisu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        if ($file === '' || !is_readable($file)) {
            $output->writeln('<error>Zadejte --file s čitelným JSON.</error>');

            return Command::FAILURE;
        }
        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $output->writeln('<error>Neplatný JSON.</error>');

            return Command::FAILURE;
        }

        $dry = (bool) $input->getOption('dry-run');
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        $customers = is_array($data['customers'] ?? null) ? $data['customers'] : [];
        $createdP = 0;
        $createdC = 0;
        $skipped = 0;

        foreach ($products as $row) {
            if (!is_array($row) || !isset($row['sku'], $row['name'])) {
                $skipped++;
                continue;
            }
            $sku = strtoupper(trim((string) $row['sku']));
            if ($this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]) instanceof Product) {
                $skipped++;
                continue;
            }
            if ($dry) {
                $createdP++;
                continue;
            }
            $product = new Product($sku, trim((string) $row['name']));
            if (isset($row['description'])) {
                $product->setDescription((string) $row['description']);
            }
            $status = (string) ($row['status'] ?? 'active');
            if (in_array($status, Product::STATUSES, true)) {
                $product->setStatus($status);
            }
            if (isset($row['unit'])) {
                $product->setUnit((string) $row['unit']);
            }
            $this->em->persist($product);
            if (isset($row['price']) && is_numeric($row['price'])) {
                $currency = strtoupper((string) ($row['currency'] ?? 'CZK'));
                $amount = number_format((float) $row['price'], 4, '.', '');
                $this->em->persist(new ProductPrice($product->id(), $currency, $amount));
            }
            $createdP++;
        }

        foreach ($customers as $row) {
            if (!is_array($row) || !isset($row['email'], $row['name'])) {
                $skipped++;
                continue;
            }
            $email = strtolower(trim((string) $row['email']));
            if ($this->em->getRepository(Customer::class)->findOneBy(['email' => $email]) instanceof Customer) {
                $skipped++;
                continue;
            }
            if ($dry) {
                $createdC++;
                continue;
            }
            $customer = new Customer(trim((string) $row['name']), $email);
            if (isset($row['phone'])) {
                $customer->setPhone((string) $row['phone']);
            }
            $this->em->persist($customer);
            $createdC++;
        }

        if (!$dry) {
            $this->em->flush();
        }

        $output->writeln(sprintf(
            '%s — products %d, customers %d, skipped %d',
            $dry ? 'DRY-RUN' : 'IMPORT',
            $createdP,
            $createdC,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
