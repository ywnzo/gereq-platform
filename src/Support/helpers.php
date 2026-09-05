<?php

declare(strict_types=1);

use Gereq\Foundation\Application;
use Gereq\Foundation\Gereq;

function gereq(): Application
{
    return Gereq::app();
}

function base_path(string $path = ''): string
{
    return Gereq::app()->basePath($path);
}

function config(string $key, mixed $default = null): mixed
{
    return Gereq::app()->config()->get($key, $default);
}

function env_value(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string, string> $headers */
function abort(int $status, string $message = '', array $headers = []): never
{
    throw new \Gereq\Http\Exception\HttpException($status, $message, $headers);
}

function old(string $key, mixed $default = null, ?string $form = null): mixed
{
    return gereq()->make(\Gereq\Http\FormState::class)->old($key, $default, $form);
}

function error(string $key, ?string $form = null): ?string
{
    return gereq()->make(\Gereq\Http\FormState::class)->error($key, $form);
}

/** @param array<string, mixed> $parameters */
function __(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
{
    return gereq()->make(\Symfony\Contracts\Translation\TranslatorInterface::class)
        ->trans($id, $parameters, $domain, $locale);
}

/** @param array<string, mixed> $parameters */
function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
{
    return __($id, $parameters, $domain, $locale);
}

function dump(mixed ...$values): mixed
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $file = (string) ($trace['file'] ?? '');
    $line = (int) ($trace['line'] ?? 0);

    if (Gereq::has()) {
        try {
            $bar = gereq()->make(\Gereq\Debug\DebugBar::class);
            if ($bar->enabled()) {
                foreach ($values as $value) {
                    $bar->addDump($value, $file, $line);
                }
            }
        } catch (\Throwable) {
        }
    }

    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        foreach ($values as $value) {
            fwrite(STDOUT, print_r($value, true) . PHP_EOL);
        }
    }

    return $values[0] ?? null;
}

function bdump(mixed $value, string $label = ''): mixed
{
    dump($label !== '' ? [$label => $value] : $value);

    return $value;
}
