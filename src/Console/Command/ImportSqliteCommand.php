<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\ORM\EntityManagerInterface;
use Gereq\Database\Migrator;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:import-sqlite', description: 'Import data from an SQLite file into the current DB (typically PostgreSQL)')]
final class ImportSqliteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Migrator $migrator,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sqlite', InputArgument::OPTIONAL, 'Cesta k .sqlite', 'storage/database.sqlite')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Nejdřív spustit migrace na cílové DB')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš tabulky, nic nezapisuj');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('sqlite');
        if (!str_starts_with($path, '/')) {
            $path = $this->basePath . '/' . $path;
        }
        if (!is_file($path)) {
            $output->writeln('<error>SQLite soubor neexistuje: ' . $path . '</error>');

            return Command::FAILURE;
        }

        $conn = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $isPostgres = $platform instanceof PostgreSQLPlatform;
        if (!$isPostgres && !str_contains($platform::class, 'MySQL') && !str_contains($platform::class, 'MariaDB')) {
            $output->writeln('<error>Cílová DB musí být PostgreSQL/MySQL (teď: ' . $platform::class . '). Nastav DB_CONNECTION=pgsql.</error>');

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('migrate')) {
            $output->writeln('<comment>Migrace cílového schématu…</comment>');
            foreach ($this->migrator->migrate() as $line) {
                $output->writeln('  ' . $line);
            }
        }

        $sqlite = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $srcTables = $sqlite->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $dstTables = array_map('strtolower', $conn->createSchemaManager()->listTableNames());
        $dstSet = array_fill_keys($dstTables, true);

        $toCopy = [];
        foreach ($srcTables as $table) {
            if (isset($dstSet[strtolower((string) $table)])) {
                $toCopy[] = (string) $table;
            } else {
                $output->writeln('<comment>skip (není v cíli): ' . $table . '</comment>');
            }
        }

        if ((bool) $input->getOption('dry-run')) {
            foreach ($toCopy as $table) {
                $n = (int) $sqlite->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
                $output->writeln(sprintf('  would copy %s (%d rows)', $table, $n));
            }

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Importuji %d tabulek ze %s…', count($toCopy), $path));

        // Disable FK checks while bulk-loading.
        if ($isPostgres) {
            $conn->executeStatement("SET session_replication_role = 'replica'");
        } else {
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            if ($toCopy !== []) {
                $quoted = array_map(static fn (string $t): string => $conn->quoteIdentifier($t), $toCopy);
                $conn->executeStatement('TRUNCATE ' . implode(', ', $quoted) . ' CASCADE');
            }

            $boolCols = $isPostgres ? $this->booleanColumns($conn) : [];

            foreach ($toCopy as $table) {
                $safe = str_replace('"', '""', $table);
                $rows = $sqlite->query('SELECT * FROM "' . $safe . '"')->fetchAll();
                if ($rows === []) {
                    $output->writeln(sprintf('  %s: 0', $table));
                    continue;
                }

                $conn->executeStatement('DELETE FROM ' . $conn->quoteIdentifier($table));

                $srcColumns = array_keys($rows[0]);
                $dstColumns = [];
                $dstBool = [];
                $dstNotNull = [];
                $dstHasDefault = [];
                $dstByLower = [];
                foreach ($conn->createSchemaManager()->listTableColumns($table) as $column) {
                    $name = $column->getName();
                    $dstColumns[] = $name;
                    $dstByLower[strtolower($name)] = $name;
                    if ($column->getType() instanceof BooleanType) {
                        $dstBool[$name] = true;
                    }
                    if ($column->getNotnull()) {
                        $dstNotNull[$name] = true;
                    }
                    if ($column->getDefault() !== null) {
                        $dstHasDefault[$name] = true;
                    }
                }

                $srcByLower = [];
                foreach ($srcColumns as $srcCol) {
                    $srcByLower[strtolower($srcCol)] = $srcCol;
                }

                /** @var list<array{src?: string, const?: mixed, dst: string}> $mapped */
                $mapped = [];
                $mappedDst = [];
                foreach ($srcColumns as $srcCol) {
                    $dstCol = $dstByLower[strtolower($srcCol)] ?? null;
                    if ($dstCol !== null) {
                        $mapped[] = ['src' => $srcCol, 'dst' => $dstCol];
                        $mappedDst[strtolower($dstCol)] = true;
                    }
                }

                // Legacy SQLite: product_option_groups.product_id → scope_id + scope_type=product
                if (
                    isset($srcByLower['product_id'], $dstByLower['scope_id'])
                    && !isset($mappedDst['scope_id'])
                    && !isset($dstByLower['product_id'])
                ) {
                    $mapped[] = ['src' => $srcByLower['product_id'], 'dst' => $dstByLower['scope_id']];
                    $mappedDst['scope_id'] = true;
                    if (isset($dstByLower['scope_type']) && !isset($mappedDst['scope_type'])) {
                        $mapped[] = ['const' => 'product', 'dst' => $dstByLower['scope_type']];
                        $mappedDst['scope_type'] = true;
                    }
                }

                // Missing NOT NULL without default (newer schema than SQLite dump)
                foreach ($dstNotNull as $dstCol => $_) {
                    if (isset($mappedDst[strtolower($dstCol)]) || isset($dstHasDefault[$dstCol])) {
                        continue;
                    }
                    $asBool = ($boolCols[$table][$dstCol] ?? false)
                        || ($boolCols[strtolower($table)][$dstCol] ?? false)
                        || ($dstBool[$dstCol] ?? false);
                    $mapped[] = ['const' => $asBool ? false : '', 'dst' => $dstCol];
                    $mappedDst[strtolower($dstCol)] = true;
                }

                if ($mapped === []) {
                    $output->writeln(sprintf('  %s: skip (žádné společné sloupce)', $table));
                    continue;
                }

                $colList = implode(', ', array_map(
                    static fn (array $m): string => $conn->quoteIdentifier($m['dst']),
                    $mapped,
                ));
                $placeholders = implode(', ', array_fill(0, count($mapped), '?'));
                $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $conn->quoteIdentifier($table), $colList, $placeholders);
                if ($isPostgres) {
                    $sql .= ' ON CONFLICT DO NOTHING';
                }

                $inserted = 0;
                foreach (array_chunk($rows, 200) as $chunk) {
                    $conn->beginTransaction();
                    try {
                        foreach ($chunk as $row) {
                            $values = [];
                            $types = [];
                            foreach ($mapped as $m) {
                                $asBool = ($boolCols[$table][$m['dst']] ?? false)
                                    || ($boolCols[strtolower($table)][$m['dst']] ?? false)
                                    || ($dstBool[$m['dst']] ?? false);
                                if (array_key_exists('const', $m)) {
                                    $raw = $m['const'];
                                } else {
                                    $raw = $row[$m['src'] ?? ''] ?? null;
                                }
                                $values[] = $this->castValue(
                                    $raw,
                                    $asBool,
                                    isset($dstNotNull[$m['dst']]),
                                );
                                $types[] = $asBool ? ParameterType::BOOLEAN : ParameterType::STRING;
                            }
                            $conn->executeStatement($sql, $values, $types);
                            $inserted++;
                        }
                        $conn->commit();
                    } catch (\Throwable $e) {
                        $conn->rollBack();
                        throw new \RuntimeException($table . ': ' . $e->getMessage(), 0, $e);
                    }
                }

                $output->writeln(sprintf('  %s: %d', $table, $inserted));
            }
        } finally {
            if ($isPostgres) {
                $conn->executeStatement("SET session_replication_role = 'origin'");
            } else {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $output->writeln('<info>Hotovo.</info>');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array<string, true>> table => column => true
     */
    private function booleanColumns(Connection $conn): array
    {
        $out = [];
        $rows = $conn->fetchAllAssociative(
            "SELECT table_name, column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND data_type = 'boolean'"
        );
        foreach ($rows as $row) {
            $out[(string) $row['table_name']][(string) $row['column_name']] = true;
        }

        return $out;
    }

    private function castValue(mixed $value, bool $asBool, bool $notNull = false): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($asBool) {
                return false;
            }

            return $notNull ? '' : null;
        }
        if ($asBool) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (int) $value === 1;
            }
            $s = strtolower(trim((string) $value));

            return in_array($s, ['1', 'true', 't', 'yes', 'on'], true);
        }

        return $value;
    }
}
