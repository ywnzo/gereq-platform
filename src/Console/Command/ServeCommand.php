<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Spustit vestavěný PHP server')]
final class ServeCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Adresa, na které server poslouchá', '0.0.0.0');
        $this->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $requested = (int) $input->getOption('port');
        $port = $this->findOpenPort($requested);

        if ($port === null) {
            $output->writeln(sprintf(
                '<error>Port %d (ani dalších 20 portů) není volný. Zkuste: php gereq serve --port=8090</error>',
                $requested
            ));

            return Command::FAILURE;
        }

        if ($port !== $requested) {
            $output->writeln(sprintf(
                '<comment>Port %d je obsazený jinou službou, spouštím na %d.</comment>',
                $requested,
                $port
            ));
        }

        $public = $this->basePath . '/public';
        $router = $public . '/router.php';
        $lanIp = $this->lanIp();
        $output->writeln(sprintf('GEREQ Platform poslouchá na 0.0.0.0:%d', $port));
        $output->writeln(sprintf('V Cursoru / na VM:     <info>http://127.0.0.1:%d/admin</info>', $port));
        if ($lanIp !== null) {
            $output->writeln(sprintf('Z Windows přes IP VM: <info>http://%s:%d/admin</info>', $lanIp, $port));
        }
        $output->writeln('Použijte 127.0.0.1, ne localhost.');
        $output->writeln('Když IP VM z Windows nefunguje, otevřete firewall:');
        $output->writeln(sprintf('  sudo firewall-cmd --add-port=%d/tcp --permanent && sudo firewall-cmd --reload', $port));
        $output->writeln('Přihlášení: ADMIN_EMAIL / ADMIN_PASSWORD z .env');
        $output->writeln('Ukončení: Ctrl+C');

        $bind = $host . ':' . $port;
        $command = sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($bind),
            escapeshellarg($public),
            escapeshellarg($router)
        );

        passthru($command, $exitCode);

        if ($exitCode !== 0) {
            $output->writeln('<error>PHP server se nespustil (kód ' . $exitCode . ').</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function lanIp(): ?string
    {
        $raw = trim((string) shell_exec('hostname -I 2>/dev/null'));
        foreach (preg_split('/\s+/', $raw) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            if (str_starts_with($ip, '172.17.') || str_starts_with($ip, '172.18.') || str_starts_with($ip, '172.19.') || str_starts_with($ip, '172.20.') || str_starts_with($ip, '172.21.') || str_starts_with($ip, '172.22.')) {
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
