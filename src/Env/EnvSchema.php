<?php

declare(strict_types=1);

namespace Gereq\Env;

/**
 * Reads config/env.schema.toml (subset) and builds / validates .env files.
 *
 * Supported table fields: type, values, default, secret, required, description.
 */
final class EnvSchema
{
    /** @var array<string, array{type: string, values: list<string>, default: string|null, secret: bool, required: bool, description: string}> */
    private array $vars = [];

    private function __construct()
    {
    }

    public static function load(string $schemaPath): self
    {
        if (!is_file($schemaPath)) {
            throw new \InvalidArgumentException('Env schema not found: ' . $schemaPath);
        }

        $self = new self();
        $self->parse((string) file_get_contents($schemaPath));

        return $self;
    }

    /** @return array<string, array{type: string, values: list<string>, default: string|null, secret: bool, required: bool, description: string}> */
    public function all(): array
    {
        return $this->vars;
    }

    public function has(string $key): bool
    {
        return isset($this->vars[$key]);
    }

    /** @return array{type: string, values: list<string>, default: string|null, secret: bool, required: bool, description: string}|null */
    public function get(string $key): ?array
    {
        return $this->vars[$key] ?? null;
    }

    /**
     * @param array<string, string|int|bool|null> $overrides
     * @param array{generate_secrets?: bool} $options
     */
    public function renderEnv(array $overrides = [], array $options = []): string
    {
        $generateSecrets = $options['generate_secrets'] ?? true;
        $lines = [
            '# GEREQ Platform — generated from config/env.schema.toml',
            '# Do not commit real secrets. Regenerate: php gereq env:init',
            '',
        ];

        foreach ($this->vars as $key => $meta) {
            $value = array_key_exists($key, $overrides)
                ? $this->stringify($overrides[$key])
                : ($meta['default'] ?? '');

            if ($generateSecrets && $value === '') {
                if ($key === 'APP_KEY') {
                    $value = bin2hex(random_bytes(32));
                } elseif ($key === 'DB_PASSWORD') {
                    $value = $this->randomPassword(32);
                }
            }

            $lines[] = $this->formatLine($key, $value);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string> human-readable problems
     */
    public function validateEnvFile(string $envPath): array
    {
        if (!is_file($envPath)) {
            return ['Missing .env file: ' . $envPath];
        }

        $parsed = $this->parseEnvFile((string) file_get_contents($envPath));
        $problems = [];

        foreach ($this->vars as $key => $meta) {
            $present = array_key_exists($key, $parsed);
            $raw = $present ? $parsed[$key] : null;

            if ($meta['required'] && (!$present || $raw === '')) {
                $problems[] = "Required {$key} is missing or empty";
                continue;
            }

            if (!$present || $raw === null || $raw === '') {
                continue;
            }

            $problems = array_merge($problems, $this->validateValue($key, $raw, $meta));
        }

        foreach (array_keys($parsed) as $key) {
            if (!$this->has($key)) {
                $problems[] = "Unknown key {$key} (not in env.schema.toml)";
            }
        }

        return $problems;
    }

    /**
     * @param array{type: string, values: list<string>, default: string|null, secret: bool, required: bool, description: string} $meta
     * @return list<string>
     */
    private function validateValue(string $key, string $raw, array $meta): array
    {
        $problems = [];
        $type = $meta['type'];

        if ($type === 'bool' && !in_array(strtolower($raw), ['true', 'false', '1', '0', '(true)', '(false)'], true)) {
            $problems[] = "{$key} must be bool, got: {$raw}";
        }

        if ($type === 'int' && !preg_match('/^-?\d+$/', $raw)) {
            $problems[] = "{$key} must be int, got: {$raw}";
        }

        if ($type === 'enum' && $meta['values'] !== [] && !in_array($raw, $meta['values'], true)) {
            $problems[] = "{$key} must be one of [" . implode(', ', $meta['values']) . "], got: {$raw}";
        }

        return $problems;
    }

    private function parse(string $toml): void
    {
        $current = null;
        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }

            if (preg_match('/^\[([A-Z][A-Z0-9_]*)\]$/', $trim, $m)) {
                $current = $m[1];
                $this->vars[$current] = [
                    'type' => 'string',
                    'values' => [],
                    'default' => null,
                    'secret' => false,
                    'required' => false,
                    'description' => '',
                ];
                continue;
            }

            if ($current === null || !preg_match('/^([a-z_]+)\s*=\s*(.+)$/', $trim, $m)) {
                continue;
            }

            $field = $m[1];
            $raw = trim($m[2]);
            match ($field) {
                'type', 'description' => $this->vars[$current][$field] = $this->unquote($raw),
                'default' => $this->vars[$current]['default'] = $this->parseDefault($raw),
                'secret', 'required' => $this->vars[$current][$field] = $this->parseBool($raw),
                'values' => $this->vars[$current]['values'] = $this->parseStringArray($raw),
                default => null,
            };
        }
    }

    private function parseDefault(string $raw): ?string
    {
        if ($raw === 'true' || $raw === 'false') {
            return $raw;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return $raw;
        }
        if ($raw === '""' || $raw === "''") {
            return '';
        }

        return $this->unquote($raw);
    }

    private function parseBool(string $raw): bool
    {
        return in_array(strtolower($this->unquote($raw)), ['true', '1', 'yes'], true);
    }

    /** @return list<string> */
    private function parseStringArray(string $raw): array
    {
        if (!preg_match('/^\[(.*)\]$/', $raw, $m)) {
            return [];
        }

        $inner = trim($m[1]);
        if ($inner === '') {
            return [];
        }

        $out = [];
        if (preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'/', $inner, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $piece = array_key_exists(1, $match) && $match[0][0] === '"'
                    ? $match[1]
                    : ($match[2] ?? '');
                $out[] = stripcslashes($piece);
            }
        }

        return $out;
    }

    private function unquote(string $raw): string
    {
        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            return stripcslashes(substr($raw, 1, -1));
        }

        return $raw;
    }

    /** @return array<string, string> */
    private function parseEnvFile(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            if (!str_contains($trim, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $trim, 2);
            $key = trim($key);
            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = stripcslashes(substr($value, 1, -1));
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function formatLine(string $key, string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\\\\\']/', $value)) {
            return $key . '="' . addcslashes($value, "\\\"\n") . '"';
        }

        return $key . '=' . $value;
    }

    private function stringify(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function randomPassword(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
