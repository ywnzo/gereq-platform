<?php

declare(strict_types=1);

namespace Gereq\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resolves the initial admin account for install / migrate --bare|--seed.
 * Credentials are never read from or written to .env.
 *
 * @phpstan-type AdminCreds array{name: string, email: string, password: string, role: string, generated: bool}
 */
final class AdminBootstrap
{
    public const DEFAULT_EMAIL = 'admin@gereq.local';
    public const DEFAULT_NAME = 'Superadmin';
    public const DEFAULT_ROLE = 'superadmin';

    /**
     * @return AdminCreds
     */
    public static function resolve(InputInterface $input, ?SymfonyStyle $io = null): array
    {
        $email = strtolower(trim((string) $input->getOption('admin-email')));
        $name = trim((string) $input->getOption('admin-name'));
        $password = (string) $input->getOption('admin-password');
        $role = strtolower(trim((string) $input->getOption('admin-role'))) ?: self::DEFAULT_ROLE;

        $interactive = $io !== null && $input->isInteractive();

        if ($interactive) {
            if ($email === '') {
                $email = strtolower(trim((string) $io->ask('Admin e-mail', self::DEFAULT_EMAIL)));
            }
            if ($name === '') {
                $name = trim((string) $io->ask('Admin jméno', self::DEFAULT_NAME));
            }
            if ($password === '') {
                $entered = (string) $io->askHidden('Admin heslo (prázdné = vygenerovat)');
                $password = $entered;
            }
        }

        $generated = false;
        if ($password === '') {
            $password = self::generatePassword();
            $generated = true;
        }
        if ($email === '') {
            $email = self::DEFAULT_EMAIL;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid admin e-mail: ' . $email);
        }
        if ($name === '') {
            $name = self::DEFAULT_NAME;
        }

        return [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role !== '' ? $role : self::DEFAULT_ROLE,
            'generated' => $generated,
        ];
    }

    public static function generatePassword(int $bytes = 18): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Aa'), '=');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function inputOptions(): array
    {
        return [
            ['admin-email', 'E-mail prvního admin účtu (neukládá se do .env)'],
            ['admin-password', 'Heslo prvního admin účtu (neukládá se do .env; prázdné = vygenerovat)'],
            ['admin-name', 'Jméno prvního admin účtu'],
            ['admin-role', 'Role prvního admin účtu (default superadmin)'],
        ];
    }
}
