<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Api\Auth\PublicApiKey;
use Gereq\Console\Docker\ServeLogTui;
use Gereq\Console\Docker\ViteHotProcess;
use Gereq\Support\EnvFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve-docker', description: 'Run GEREQ via Docker (nginx + PHP-FPM + Redis)')]
final class ServeDockerCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Host port pro HTTP (nginx :80)', '8080')
            ->addOption('https', null, InputOption::VALUE_NONE, 'Zapnout HTTPS na host portu 443 (self-signed cert)')
            ->addOption('hot', null, InputOption::VALUE_NONE, 'Spustit Bun asset watch (bun run dev → public/build)')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Spustit i queue worker (profil queue)')
            ->addOption('build', null, InputOption::VALUE_NONE, 'Přestavět Docker image před startem')
            ->addOption('detach', 'd', InputOption::VALUE_NONE, 'Běžet na pozadí (docker compose up -d)')
            ->addOption('down', null, InputOption::VALUE_NONE, 'Zastavit Docker stack a ukončit')
            ->addOption('reuse', null, InputOption::VALUE_NONE, 'Nejdřív zastavit běžící stack, pak znovu spustit (změna portu / --https bez ručního --down)')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Surové docker compose logy (bez formátování)')
            ->addOption('dashboard', null, InputOption::VALUE_NONE, 'Fullscreen TUI dashboard (místo scroll streamu)')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Při ukončení logů nenechat stack padnout (compose down přeskočit)');
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

        $https = (bool) $input->getOption('https');
        $hot = (bool) $input->getOption('hot');
        // --down: prefer HTTPS override when present so project matches last --https start
        if ($input->getOption('down')) {
            return $this->stopStack($output, $https, $hot);
        }

        if ($input->getOption('reuse')) {
            $output->writeln('<comment>Reuse: zastavuji běžící stack…</comment>');
            $downCode = $this->stopStack($output, $https, $hot);
            if ($downCode !== Command::SUCCESS) {
                return $downCode;
            }
            $output->writeln('<info>Reuse: stack zastaven, spouštím znovu…</info>');
            $output->writeln('');
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
        $raw = (bool) $input->getOption('raw');
        $keep = (bool) $input->getOption('keep');
        $dashboard = (bool) $input->getOption('dashboard');

        $lanIp = $this->lanIp();
        $vite = $hot ? new ViteHotProcess($this->basePath) : null;

        if ($https) {
            if (!$this->isPortFree(443)) {
                $output->writeln('<error>Port 443 je obsazený. Uvolněte ho, nebo vypněte --https.</error>');

                return Command::FAILURE;
            }
            if (!$this->ensureDevCertificates($output, $lanIp)) {
                return Command::FAILURE;
            }
        }

        if ($vite !== null && !$vite->start($output)) {
            return Command::FAILURE;
        }

        $appUrl = $https
            ? 'https://127.0.0.1'
            : ('http://127.0.0.1:' . $port);

        putenv('GEREQ_PORT=' . $port);
        putenv('APP_URL=' . $appUrl);
        putenv('TRUSTED_PROXIES=*');
        $_ENV['GEREQ_PORT'] = (string) $port;
        $_ENV['APP_URL'] = $appUrl;
        $_ENV['TRUSTED_PROXIES'] = '*';

        $origins = [
            'http://127.0.0.1:5173',
            'http://localhost:5173',
            'http://127.0.0.1:5174',
            'http://localhost:5174',
            'http://127.0.0.1:5175',
            'http://localhost:5175',
        ];
        if ($https) {
            $origins[] = 'https://127.0.0.1';
            $origins[] = 'https://localhost';
            foreach (['realestates.local', 'realestates.dev', 'gereq.local', 'gereq.dev'] as $host) {
                $origins[] = 'https://' . $host;
            }
        }
        if ($lanIp !== null && $lanIp !== '') {
            foreach ([5173, 5174, 5175] as $fePort) {
                $origins[] = 'http://' . $lanIp . ':' . $fePort;
            }
            if ($https) {
                $origins[] = 'https://' . $lanIp;
            }
        }
        $existing = trim((string) (getenv('FRONTEND_ORIGIN') ?: ($_ENV['FRONTEND_ORIGIN'] ?? '')));
        if ($existing !== '' && $existing !== '*') {
            foreach (explode(',', $existing) as $part) {
                $part = trim($part);
                if ($part !== '' && !in_array($part, $origins, true)) {
                    $origins[] = $part;
                }
            }
        }
        $frontendOrigin = implode(',', $origins);
        putenv('FRONTEND_ORIGIN=' . $frontendOrigin);
        $_ENV['FRONTEND_ORIGIN'] = $frontendOrigin;

        $publicKey = $this->ensurePublicApiKey($output);
        putenv('API_PUBLIC_KEY=' . $publicKey);
        $_ENV['API_PUBLIC_KEY'] = $publicKey;

        if ($detach || $raw) {
            $output->writeln('GEREQ Platform (Docker): nginx + PHP-FPM + PostgreSQL + Redis + queue');
            $this->writeUrls($output, $port, $lanIp, $https);
            $output->writeln('Admin: http://127.0.0.1:' . $port . ' (nebo http://localhost:' . $port . ' pro passkeys).');
            if ($https) {
                $output->writeln('<comment>HTTPS self-signed — prohlížeč jednou varuje (pokračovat / výjimka).</comment>');
            }
            if ($hot) {
                $output->writeln('<comment>Bun watch: bun run dev → public/build (refresh prohlížeče).</comment>');
            }
            $output->writeln('<comment>Queue worker běží automaticky (QUEUE_DRIVER=redis).</comment>');
            if ($withQueue) {
                $output->writeln('<comment>--queue je legacy; worker je součástí výchozího stacku.</comment>');
            }
            $output->writeln('Zastavení: Ctrl+C' . ($detach ? ' (nebo php gereq serve-docker --down)' : ' / php gereq serve-docker --down'));
            $output->writeln('');

            $args = ['up'];
            if ($detach) {
                $args[] = '-d';
            }
            if ($build) {
                $args[] = '--build';
            }

            try {
                return $this->runCompose($output, $args, $https);
            } finally {
                // Detach: Bun watch keeps running (pid file); raw/Ctrl+C: stop watch.
                if ($vite !== null && !$detach) {
                    $vite->stop($output);
                }
            }
        }

        $output->writeln($this->banner($port, $lanIp, $https, $hot));
        if ($https) {
            $output->writeln('<comment>HTTPS self-signed — prohlížeč jednou varuje (pokračovat / výjimka).</comment>');
        }
        if ($withQueue) {
            $output->writeln('<comment>--queue je legacy; worker je součástí výchozího stacku.</comment>');
        }

        $upArgs = ['up', '-d'];
        if ($build) {
            $upArgs[] = '--build';
        }
        $code = $this->runCompose($output, $upArgs, $https);
        if ($code !== Command::SUCCESS) {
            $vite?->stop($output);

            return $code;
        }

        $output->writeln('<info>Stack běží.</info> ' . ($dashboard ? 'Otevírám dashboard…' : 'Otevírám log stream…'));
        $hint = '  [q] ukončit  [a] assety  [p] php  [n]oise  [rb]uild';
        if ($hot) {
            $hint .= '  [h]ot';
        }
        $hint .= '  [space] pause';
        $output->writeln($this->cDim($hint));
        $output->writeln('');

        usleep(400_000);

        $tui = new ServeLogTui(
            basePath: $this->basePath,
            port: $port,
            lanIp: $lanIp,
            bringDownOnExit: !$keep,
            dashboard: $dashboard,
            https: $https,
            hot: $hot,
        );

        try {
            return $tui->run() === 0 ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $vite?->stop($output);
        }
    }

    private function banner(int $port, ?string $lanIp, bool $https, bool $hot = false): string
    {
        $suffix = [];
        if ($https) {
            $suffix[] = 'HTTPS';
        }
        if ($hot) {
            $suffix[] = 'watch';
        }
        $tag = $suffix !== [] ? ' <fg=cyan>(' . implode(' + ', $suffix) . ')</>' : '';

        $lines = [
            '<fg=green;options=bold>GEREQ</> <fg=gray>·</> Docker stack' . $tag,
        ];
        if ($https) {
            $lines[] = '  https   <info>https://127.0.0.1/admin</info>';
            if ($lanIp !== null) {
                $lines[] = sprintf('  lan     <info>https://%s/admin</info>', $lanIp);
            }
            $lines[] = sprintf('  http    <fg=gray>http://127.0.0.1:%d/admin</> (stále mapováno)', $port);
        } else {
            $lines[] = sprintf('  local   <info>http://127.0.0.1:%d/admin</info>', $port);
            if ($lanIp !== null) {
                $lines[] = sprintf('  lan     <info>http://%s:%d/admin</info>', $lanIp, $port);
            }
        }
        if ($hot) {
            $lines[] = '  assets  <info>bun run dev</info> <fg=gray>→ public/build (watch)</>';
        }
        $lines[] = '  <fg=gray>PostgreSQL + Redis + queue jsou součástí stacku.</>';
        if ($https) {
            $lines[] = '  <fg=gray>Passkeys / zelený zámek: hosts → realestates.local / realestates.dev, stáhněte https://realestates.dev/dev-ca.crt a naimportujte do Trusted Root (Windows/Mac).</>';
        }

        return implode("\n", $lines);
    }

    private function writeUrls(OutputInterface $output, int $port, ?string $lanIp, bool $https): void
    {
        if ($https) {
            $output->writeln('V Cursoru / na VM:     <info>https://127.0.0.1/admin</info>');
            if ($lanIp !== null) {
                $output->writeln(sprintf('Z Windows přes IP VM: <info>https://%s/admin</info>', $lanIp));
            }
            $output->writeln(sprintf('HTTP (volitelně):      <fg=gray>http://127.0.0.1:%d/admin</>', $port));

            return;
        }

        $output->writeln(sprintf('V Cursoru / na VM:     <info>http://127.0.0.1:%d/admin</info>', $port));
        if ($lanIp !== null) {
            $output->writeln(sprintf('Z Windows přes IP VM: <info>http://%s:%d/admin</info>', $lanIp, $port));
        }
    }

    private function cDim(string $text): string
    {
        return '<fg=gray>' . $text . '</>';
    }

    /** Stop compose stack (+ Bun watch from --hot / -d). */
    private function stopStack(OutputInterface $output, bool $https, bool $hot): int
    {
        if ($hot || is_file($this->basePath . '/storage/tmp/bun-assets.pid') || is_file($this->basePath . '/storage/tmp/vite-dev.pid')) {
            (new ViteHotProcess($this->basePath))->stopFromPidFile($output);
        }
        $useHttps = $https || is_file($this->basePath . '/docker-compose.https.yml');

        return $this->runCompose(
            $output,
            ['down'],
            $useHttps && is_file($this->basePath . '/docker-compose.https.yml'),
        );
    }

    /** @param list<string> $args */
    private function runCompose(OutputInterface $output, array $args, bool $https = false): int
    {
        $command = array_merge(
            ['docker', 'compose'],
            $this->composeFileArgs($https),
            ['--project-directory', $this->basePath],
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

    /** @return list<string> */
    private function composeFileArgs(bool $https): array
    {
        $args = ['-f', $this->basePath . '/docker-compose.yml'];
        if ($https) {
            $override = $this->basePath . '/docker-compose.https.yml';
            if (is_file($override)) {
                $args[] = '-f';
                $args[] = $override;
            }
        }

        return $args;
    }

    private function ensureDevCertificates(OutputInterface $output, ?string $lanIp): bool
    {
        $dir = $this->basePath . '/docker/nginx/certs';
        $crt = $dir . '/dev.crt';
        $key = $dir . '/dev.key';
        $rootCa = $dir . '/rootCA.pem';

        $sans = $this->devCertificateSans($lanIp);
        $mkcert = $this->mkcertBinary();
        if (is_file($crt) && is_file($key) && $this->certificateCoversSans($crt, $sans)) {
            $output->writeln('<fg=gray>HTTPS certifikát: docker/nginx/certs/dev.crt</>');
            if ($mkcert !== null) {
                $this->exportMkcertRootCa($rootCa);
                $output->writeln('<comment>Zelený zámek na Windows: naimportujte docker/nginx/certs/rootCA.pem do „Důvěryhodné kořenové certifikační úřady“.</comment>');
            }

            return true;
        }

        if (is_file($crt) || is_file($key)) {
            $output->writeln('<comment>Obnovuji HTTPS certifikát (chybí SAN / mkcert).</comment>');
            @unlink($crt);
            @unlink($key);
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $output->writeln('<error>Nelze vytvořit adresář docker/nginx/certs.</error>');

            return false;
        }

        if ($mkcert !== null && $this->generateMkcertCertificate($output, $mkcert, $crt, $key, $lanIp)) {
            $this->exportMkcertRootCa($rootCa);
            $output->writeln('<info>Vygenerován mkcert certifikát (důvěryhodný po instalaci rootCA).</info>');
            $output->writeln('<comment>Windows (prohlížeč): naimportujte docker/nginx/certs/rootCA.pem → Local Computer → Trusted Root Certification Authorities.</comment>');
            $output->writeln('<comment>Pak restart prohlížeče a otevřete https://realestates.dev/admin (nebo https://realestates.local/admin)</comment>');

            return true;
        }

        return $this->generateOpensslSelfSigned($output, $dir, $crt, $key, $sans);
    }

    private function mkcertBinary(): ?string
    {
        $paths = ['mkcert', '/usr/local/bin/mkcert', '/usr/bin/mkcert'];
        foreach ($paths as $bin) {
            if ($bin === 'mkcert') {
                exec('command -v mkcert 2>/dev/null', $out, $code);
                if ($code === 0 && ($out[0] ?? '') !== '') {
                    return trim($out[0]);
                }
                continue;
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function exportMkcertRootCa(string $target): void
    {
        exec('mkcert -CAROOT 2>/dev/null', $out, $code);
        $caroot = ($code === 0 && isset($out[0])) ? trim($out[0]) : '';
        $src = $caroot !== '' ? $caroot . '/rootCA.pem' : '';
        if ($src !== '' && is_file($src)) {
            @copy($src, $target);
            @chmod($target, 0644);
        }
    }

    private function generateMkcertCertificate(
        OutputInterface $output,
        string $mkcert,
        string $crt,
        string $key,
        ?string $lanIp,
    ): bool {
        exec(escapeshellarg($mkcert) . ' -install 2>&1', $installOut, $installCode);
        if ($installCode !== 0) {
            $output->writeln('<fg=gray>mkcert -install: ' . implode(' ', $installOut) . '</>');
        }

        $names = [
            'localhost',
            '127.0.0.1',
            '::1',
            'realestates.local',
            'realestates.dev',
            'gereq.local',
            'gereq.dev',
        ];
        $envName = trim((string) (getenv('WEBAUTHN_LOCAL_HOSTNAME') ?: ($_ENV['WEBAUTHN_LOCAL_HOSTNAME'] ?? '')));
        if ($envName !== '') {
            $names[] = $envName;
        }
        $appName = strtolower((string) (getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? 'gereq')));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $appName), '-') ?: 'gereq';
        $names[] = $slug . '.local';
        $names[] = $slug . '.dev';
        if ($lanIp !== null && filter_var($lanIp, FILTER_VALIDATE_IP)) {
            $names[] = $lanIp;
        }

        $names = array_values(array_unique(array_filter($names)));
        $cmd = sprintf(
            '%s -cert-file %s -key-file %s %s 2>&1',
            escapeshellarg($mkcert),
            escapeshellarg($crt),
            escapeshellarg($key),
            implode(' ', array_map('escapeshellarg', $names))
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || !is_file($crt) || !is_file($key)) {
            $output->writeln('<error>mkcert selhal.</error>');
            if ($out !== []) {
                $output->writeln('<fg=gray>' . implode("\n", $out) . '</>');
            }

            return false;
        }
        @chmod($key, 0600);
        $output->writeln('<fg=gray>SAN: ' . implode(', ', $names) . '</>');

        return true;
    }

    /** @param list<string> $sans */
    private function generateOpensslSelfSigned(
        OutputInterface $output,
        string $dir,
        string $crt,
        string $key,
        array $sans,
    ): bool {
        $san = implode(',', $sans);
        $cnf = $dir . '/openssl.cnf';
        $cnfBody = <<<CNF
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = localhost

[v3_req]
subjectAltName = {$san}
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
CNF;
        if (file_put_contents($cnf, $cnfBody) === false) {
            $output->writeln('<error>Nelze zapsat openssl.cnf.</error>');

            return false;
        }

        $cmd = sprintf(
            'openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout %s -out %s -config %s 2>&1',
            escapeshellarg($key),
            escapeshellarg($crt),
            escapeshellarg($cnf)
        );
        exec($cmd, $out, $code);
        @unlink($cnf);

        if ($code !== 0 || !is_file($crt) || !is_file($key)) {
            $output->writeln('<error>openssl selhal při generování self-signed certifikátu.</error>');
            if ($out !== []) {
                $output->writeln('<fg=gray>' . implode("\n", $out) . '</>');
            }

            return false;
        }

        @chmod($key, 0600);
        $output->writeln('<info>Vygenerován self-signed certifikát (bez zeleného zámku).</info>');
        $output->writeln('<comment>Pro zelený zámek nainstalujte mkcert a znovu spusťte serve-docker --https.</comment>');
        $output->writeln('<fg=gray>SAN: ' . implode(', ', $sans) . '</>');

        return true;
    }

    /** @return list<string> */
    private function devCertificateSans(?string $lanIp): array
    {
        $sans = ['DNS:localhost', 'IP:127.0.0.1'];
        if ($lanIp !== null && filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $sans[] = 'IP:' . $lanIp;
        }

        $names = ['realestates.local', 'realestates.dev', 'gereq.local', 'gereq.dev'];
        $envName = trim((string) (getenv('WEBAUTHN_LOCAL_HOSTNAME') ?: ($_ENV['WEBAUTHN_LOCAL_HOSTNAME'] ?? '')));
        if ($envName !== '') {
            $names[] = $envName;
        }
        $appName = strtolower((string) (getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? 'gereq')));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $appName), '-') ?: 'gereq';
        $names[] = $slug . '.local';
        $names[] = $slug . '.dev';

        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || filter_var($name, FILTER_VALIDATE_IP)) {
                continue;
            }
            $entry = 'DNS:' . $name;
            if (!in_array($entry, $sans, true)) {
                $sans[] = $entry;
            }
        }

        return $sans;
    }

    /** @param list<string> $sans */
    private function certificateCoversSans(string $crtPath, array $sans): bool
    {
        $cmd = sprintf('openssl x509 -in %s -noout -text 2>/dev/null', escapeshellarg($crtPath));
        exec($cmd, $out, $code);
        if ($code !== 0) {
            return false;
        }
        $text = implode("\n", $out);
        foreach ($sans as $san) {
            if (str_starts_with($san, 'DNS:')) {
                $host = substr($san, 4);
                if (!str_contains($text, 'DNS:' . $host) && !preg_match('/DNS Name:\s*' . preg_quote($host, '/') . '\b/', $text)) {
                    return false;
                }
            }
            if (str_starts_with($san, 'IP:')) {
                $ip = substr($san, 3);
                if (!str_contains($text, $ip)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function ensurePublicApiKey(OutputInterface $output): string
    {
        $rootEnv = new EnvFile($this->basePath . '/.env');
        $fromFile = $rootEnv->get('API_PUBLIC_KEY');
        $fromEnv = (string) ($_ENV['API_PUBLIC_KEY'] ?? (getenv('API_PUBLIC_KEY') ?: ''));
        $existing = trim((string) ($fromFile ?? $fromEnv));
        if ($existing !== '' && str_starts_with($existing, PublicApiKey::PREFIX)) {
            $key = $existing;
        } else {
            $key = PublicApiKey::generate();
            $rootEnv->set('API_PUBLIC_KEY', $key);
            $output->writeln('<info>Vygenerován API_PUBLIC_KEY (grq_pub_*) do .env</info>');
        }

        $storefrontEnvPath = $this->basePath . '/storefront/.env';
        if (!is_dir($this->basePath . '/storefront')) {
            return $key;
        }

        $feEnv = new EnvFile($storefrontEnvPath);
        $feKey = trim((string) ($feEnv->get('VITE_API_PUBLIC_KEY') ?? ''));
        if ($feKey !== $key) {
            $feEnv->set('VITE_API_PUBLIC_KEY', $key);
            if ($feEnv->get('VITE_API_URL') === null) {
                $feEnv->set('VITE_API_URL', 'http://127.0.0.1:8080');
            }
            if ($feEnv->get('VITE_API_PROXY_TARGET') === null) {
                $feEnv->set('VITE_API_PROXY_TARGET', 'http://127.0.0.1:8080');
            }
            $output->writeln('<comment>storefront/.env: VITE_API_PUBLIC_KEY synchronizován (restart Vite / bun run dev).</comment>');
        }

        return $key;
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
