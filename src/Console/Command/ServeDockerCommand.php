<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve-docker', description: 'Spustit GEREQ přes Docker (nginx + PHP-FPM + Redis)')]
final class ServeDockerCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Host port pro nginx', '8080')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Spustit i queue worker (profil queue)')
            ->addOption('build', null, InputOption::VALUE_NONE, 'Přestavět Docker image před startem')
            ->addOption('detach', 'd', InputOption::VALUE_NONE, 'Běžet na pozadí (docker compose up -d)')
            ->addOption('down', null, InputOption::VALUE_NONE, 'Zastavit Docker stack a ukončit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->dockerAvailable()) {
            $output->writeln('<error>Docker / docker compose není dostupný. Nainstalujte Docker a zkuste znovu.</error>');

            return Command::FAILURE;
        }

        $composeFile = $this->basePath . '/docker-compose.yml';
        if (!is_file($composeFile)) {
            $output->writeln('<error>Chybí docker-compose.yml v kořeni projektu.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption('down')) {
            return $this->runCompose($output, ['down']);
        }

        $requested = (int) $input->getOption('port');
        $port = $this->findOpenPort($requested);

        if ($port === null) {
            $output->writeln(sprintf(
                '<error>Port %d (ani dalších 20 portů) není volný. Zkuste: php gereq serve-docker --port=8090</error>',
                $requested
            ));

            return Command::FAILURE;
        }

        if ($port !== $requested) {
            $output->writeln(sprintf(
                '<comment>Port %d je obsazený, spouštím na %d.</comment>',
                $requested,
                $port
            ));
        }

        $withQueue = (bool) $input->getOption('queue');
        $detach = (bool) $input->getOption('detach');
        $build = (bool) $input->getOption('build');

        $lanIp = $this->lanIp();
        $output->writeln('GEREQ Platform (Docker): nginx + PHP-FPM + Redis');
        $output->writeln(sprintf('V Cursoru / na VM:     <info>http://127.0.0.1:%d/admin</info>', $port));
        if ($lanIp !== null) {
            $output->writeln(sprintf('Z Windows přes IP VM: <info>http://%s:%d/admin</info>', $lanIp, $port));
        }
        $output->writeln('Použijte 127.0.0.1, ne localhost.');
        if ($withQueue) {
            $output->writeln('<comment>Queue worker běží (QUEUE_DRIVER=redis uvnitř workeru).</comment>');
            $output->writeln('Pro frontu v aplikaci nastavte v .env: QUEUE_DRIVER=redis');
        } else {
            $output->writeln('Queue worker: php gereq serve-docker --queue');
        }
        $output->writeln('Zastavení: Ctrl+C' . ($detach ? ' (nebo php gereq serve-docker --down)' : ' / php gereq serve-docker --down'));
        $output->writeln('');

        putenv('GEREQ_PORT=' . $port);
        putenv('APP_URL=http://127.0.0.1:' . $port);
        $_ENV['GEREQ_PORT'] = (string) $port;
        $_ENV['APP_URL'] = 'http://127.0.0.1:' . $port;

        $args = ['up'];
        if ($detach) {
            $args[] = '-d';
        }
        if ($build) {
            $args[] = '--build';
        }
        if ($withQueue) {
            $args[] = '--profile';
            $args[] = 'queue';
        }

        return $this->runCompose($output, $args);
    }

    /** @param list<string> $args */
    private function runCompose(OutputInterface $output, array $args): int
    {
        $command = array_merge(
            ['docker', 'compose', '-f', $this->basePath . '/docker-compose.yml', '--project-directory', $this->basePath],
            $args
        );

        $cmd = implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            $output->writeln('<error>Docker compose selhal (kód ' . $exitCode . ').</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function dockerAvailable(): bool
    {
        $code = 0;
        exec('docker compose version >/dev/null 2>&1', $out, $code);

        return $code === 0;
    }

    private function lanIp(): ?string
    {
        $raw = trim((string) shell_exec('hostname -I 2>/dev/null'));
        foreach (preg_split('/\s+/', $raw) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            if (
                str_starts_with($ip, '172.17.')
                || str_starts_with($ip, '172.18.')
                || str_starts_with($ip, '172.19.')
                || str_starts_with($ip, '172.20.')
                || str_starts_with($ip, '172.21.')
                || str_starts_with($ip, '172.22.')
            ) {
                continue;
            }

            return $ip;
        }

        return null;
    }

    private function findOpenPort(int $start): ?int
    {
        for ($port = $start; $port < $start + 21; $port++) {
            if ($this->isPortFree($port)) {
                return $port;
            }
        }

        return null;
    }

    private function isPortFree(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return false;
        }

        return true;
    }
}
