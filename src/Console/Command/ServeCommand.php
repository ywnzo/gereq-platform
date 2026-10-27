<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Console\Docker\AssetWatchProcess;
use Gereq\Console\Docker\QueueWorkProcess;
use Gereq\Console\Docker\ScheduleLoopProcess;
use Gereq\Console\Serve\PhpBuiltinLogCoalescer;
use Gereq\Console\Serve\ProcessKiller;
use Gereq\Console\Serve\ServeDevTui;
use Gereq\Support\AdminPath;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'serve',
    description: 'Run the built-in PHP development server',
    aliases: ['server'],
)]
final class ServeCommand extends Command
{
    /** @var resource|null */
    private $serverProc = null;

    private ?AssetWatchProcess $watch = null;

    private ?QueueWorkProcess $queue = null;

    private ?ScheduleLoopProcess $scheduler = null;

    private bool $stopping = false;

    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Listen address', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Listen port', '8080')
            ->addOption('hot', null, InputOption::VALUE_NONE, 'Start Bun asset watch (bun run dev)')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Start queue:work + schedule:run loop (non-Docker jobs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $requested = (int) $input->getOption('port');
        $hot = (bool) $input->getOption('hot');
        $withQueue = (bool) $input->getOption('queue');

        $port = $this->findOpenPort($requested);
        if ($port === null) {
            $output->writeln(sprintf(
                '<error>Port %d (and the next 20) are busy. Try: php gereq serve --port=8090</error>',
                $requested
            ));

            return Command::FAILURE;
        }

        if ($port !== $requested) {
            $output->writeln(sprintf(
                '<comment>Port %d is busy — using %d.</comment>',
                $requested,
                $port
            ));
        }

        if ($hot) {
            $this->watch = new AssetWatchProcess($this->basePath);
            if (!$this->watch->start($output)) {
                return Command::FAILURE;
            }
        }

        if ($withQueue) {
            $driver = strtolower((string) (getenv('QUEUE_DRIVER') ?: ($_ENV['QUEUE_DRIVER'] ?? 'sync')));
            if ($driver === '' || $driver === 'sync') {
                $output->writeln('<comment>QUEUE_DRIVER=sync — jobs run inline; queue worker stays idle, scheduler still ticks.</comment>');
            }

            $this->queue = new QueueWorkProcess($this->basePath);
            if (!$this->queue->start($output)) {
                $this->cleanup($output);

                return Command::FAILURE;
            }

            $this->scheduler = new ScheduleLoopProcess($this->basePath);
            if (!$this->scheduler->start($output)) {
                $this->cleanup($output);

                return Command::FAILURE;
            }
        }

        $lanIp = $this->lanIp();
        $interactive = $this->isInteractive();

        if (!$interactive) {
            $this->printBanner($output, $host, $port, $hot, $withQueue);
        }

        $public = $this->basePath . '/public';
        $router = $public . '/router.php';
        $bind = $host . ':' . $port;
        $cmd = [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            '-S',
            $bind,
            '-t',
            $public,
            $router,
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->basePath);
        if (!is_resource($proc)) {
            $output->writeln('<error>Failed to start the PHP built-in server.</error>');
            $this->cleanup($output);

            return Command::FAILURE;
        }

        $this->serverProc = $proc;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            if ($interactive) {
                $tui = new ServeDevTui(
                    $this->basePath,
                    $proc,
                    $pipes,
                    $port,
                    $host,
                    $hot,
                    $withQueue,
                    $lanIp,
                );
                $tui->run();
            } else {
                $this->runPlainStream($proc, $pipes, $output);
            }
        } finally {
            // Drop TUI handlers; Ctrl+C / alarm hard-exit if cleanup blocks.
            ProcessKiller::resetSignals();
            $this->installForceExitHandler();
            ProcessKiller::armCleanupAlarm(2);

            try {
                ProcessKiller::forceProc($proc, $pipes);
                $this->serverProc = null;

                $output->writeln('<fg=gray>Shutting down…</>');
                $this->cleanup($output);
            } finally {
                ProcessKiller::clearCleanupAlarm();
            }
        }

        return Command::SUCCESS;
    }

    /** Ctrl+C during cleanup → immediate exit (do not swallow the signal). */
    private function installForceExitHandler(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        $force = static function (): void {
            fwrite(STDERR, "\nForce exit.\n");
            exit(130);
        };
        @pcntl_signal(SIGINT, $force);
        @pcntl_signal(SIGTERM, $force);
    }

    /**
     * @param resource $proc
     * @param array{1?: resource, 2?: resource} $pipes
     */
    private function runPlainStream($proc, array $pipes, OutputInterface $output): void
    {
        $this->installSignalHandlers();
        $coalescer = new PhpBuiltinLogCoalescer();
        $bufOut = '';
        $bufErr = '';

        while (!$this->stopping) {
            $status = proc_get_status($proc);
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $this->drainPipe($pipes[1], $bufOut, $output, $coalescer);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $this->drainPipe($pipes[2], $bufErr, $output, $coalescer);
            }

            if (!$status['running']) {
                break;
            }

            usleep(50_000);
        }

        $coalescer->flush($output);
    }

    private function printBanner(OutputInterface $output, string $host, int $port, bool $hot, bool $queue): void
    {
        $admin = AdminPath::base();
        $lanIp = $this->lanIp();
        $appEnv = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'local'));

        $output->writeln('');
        $output->writeln('<fg=bright-white;options=bold>  GEREQ</> <fg=gray>·</> development server');
        $output->writeln(sprintf('  <fg=gray>listen</>   <info>%s:%d</info>', $host, $port));
        $output->writeln(sprintf('  <fg=gray>local</>    <href=http://127.0.0.1:%d%s>http://127.0.0.1:%d%s</>', $port, $admin, $port, $admin));
        if ($lanIp !== null) {
            $output->writeln(sprintf('  <fg=gray>network</>  <info>http://%s:%d%s</info>', $lanIp, $port, $admin));
        }
        $output->writeln(sprintf('  <fg=gray>env</>      %s', $appEnv));
        $flags = [];
        if ($hot) {
            $flags[] = '<info>hot</info>';
        }
        if ($queue) {
            $flags[] = '<info>queue</info>';
            $flags[] = '<info>schedule</info>';
        }
        if ($flags !== []) {
            $output->writeln('  <fg=gray>extras</>   ' . implode(' <fg=gray>·</> ', $flags));
        }
        $output->writeln('  <fg=gray>stop</>     Ctrl+C');
        $output->writeln('  <fg=gray>tabs</>     Tab / click · app · db · queue · bun');
        $output->writeln('  <fg=gray>login</>    account from install / migrate (not .env)');
        $output->writeln('');
    }

    /**
     * @param resource $pipe
     */
    private function drainPipe(
        $pipe,
        string &$buffer,
        OutputInterface $output,
        PhpBuiltinLogCoalescer $coalescer,
        bool $flush = false,
    ): void {
        while (($chunk = fread($pipe, 8192)) !== false && $chunk !== '') {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                $coalescer->push($line, $output);
            }
        }

        if ($flush && $buffer !== '') {
            $coalescer->push($buffer, $output);
            $buffer = '';
        }
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
            if (is_resource($this->serverProc)) {
                @proc_terminate($this->serverProc, 15);
            }
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    private function cleanup(OutputInterface $output): void
    {
        if ($this->scheduler !== null) {
            $this->scheduler->stop($output);
            $this->scheduler = null;
        }
        if ($this->queue !== null) {
            $this->queue->stop($output);
            $this->queue = null;
        }
        if ($this->watch !== null) {
            $this->watch->stop($output);
            $this->watch = null;
        }
    }

    private function isInteractive(): bool
    {
        return \defined('STDIN') && is_resource(STDIN) && @stream_isatty(STDIN)
            && \defined('STDOUT') && is_resource(STDOUT) && @stream_isatty(STDOUT);
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
