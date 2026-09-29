<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Console\AdminBootstrap;
use Gereq\Config\Repository;
use Gereq\Module\ModuleManager;
use Gereq\Setup\SetupState;

/**
 * When the target database has no core schema / users, apply a bare install
 * (core schema + roles + generated superadmin) and announce credentials to the logs.
 *
 * Host CLI without DB connectivity (e.g. `serve-docker`) skips quietly.
 */
final class DatabaseBootstrap
{
    public function __construct(
        private readonly string $basePath,
        private readonly Repository $config,
        private readonly EntityManagerInterface $em,
        private readonly Migrator $migrator,
        private readonly Seeder $seeder,
        private readonly ModuleManager $modules,
        private readonly SetupState $setup,
    ) {
    }

    /**
     * @return array{name: string, email: string, password: string, role: string, generated: bool}|null
     */
    public function ensureBareInstallIfEmpty(): ?array
    {
        if ((string) $this->config->get('app.env', 'production') === 'testing') {
            return null;
        }
        if (filter_var((string) env_value('GEREQ_SKIP_AUTO_BOOTSTRAP', ''), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        try {
            if (!$this->needsBareInstall()) {
                return null;
            }
        } catch (\Throwable $e) {
            if (DatabaseCreator::isUnreachable($e)) {
                return null;
            }
            throw $e;
        }

        $lockDir = $this->basePath . '/storage/framework';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0775, true);
        }
        $lockPath = $lockDir . '/bare-bootstrap.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open bare-bootstrap lock file.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return null;
            }
            // Re-check after winning the lock (another worker may have finished).
            try {
                if (!$this->needsBareInstall()) {
                    return null;
                }
            } catch (\Throwable $e) {
                if (DatabaseCreator::isUnreachable($e)) {
                    return null;
                }
                throw $e;
            }

            $this->log('Empty database detected — running bare install (core schema + superadmin).');

            foreach ($this->migrator->migrate() as $line) {
                $this->log($line);
            }

            $admin = [
                'name' => AdminBootstrap::DEFAULT_NAME,
                'email' => AdminBootstrap::DEFAULT_EMAIL,
                'password' => AdminBootstrap::generatePassword(),
                'role' => AdminBootstrap::DEFAULT_ROLE,
                'generated' => true,
            ];
            $this->seeder->seedBare($admin);
            $this->modules->rediscover();
            $this->setup->markIncomplete();

            $this->log('Bare install complete.');
            $this->log('Initial admin (not stored in .env):');
            $this->log('  email:    ' . $admin['email']);
            $this->log('  password: ' . $admin['password']);
            $this->log('  role:     ' . $admin['role']);

            return $admin;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @throws \Throwable when the database server is unreachable (caller may skip)
     */
    private function needsBareInstall(): bool
    {
        $conn = $this->em->getConnection();
        try {
            $conn->fetchOne('SELECT 1 FROM users LIMIT 1 /* gereq:bootstrap */');

            return false;
        } catch (\Throwable $e) {
            if (DatabaseCreator::isUnreachable($e)) {
                throw $e;
            }

            // Missing relation / empty schema → bare install.
            return true;
        }
    }

    private function log(string $message): void
    {
        $line = '[gereq] ' . $message;
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            if (\defined('STDERR') && is_resource(STDERR)) {
                fwrite(STDERR, $line . "\n");
            } else {
                echo $line . "\n";
            }

            return;
        }
        error_log($line);
    }
}
