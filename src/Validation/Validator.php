<?php

declare(strict_types=1);

namespace Gereq\Validation;

use Gereq\Hook\HookManager;
use Symfony\Component\HttpFoundation\Request;

final class Validator
{
    public function __construct(
        private readonly ValidationRegistry $registry,
        private readonly HookManager $hooks,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validateRequest(Request $request, string|array $rules, array $context = []): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->request->all();
        $form = is_string($rules)
            ? $rules
            : (string) ($context['form'] ?? $request->attributes->get('_route', ''));

        if (is_string($rules)) {
            return $this->registry->validate($form, $data, $context);
        }

        return $this->validate($data, $rules, $context, $form);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validate(array $data, array $rules, array $context = [], string $form = ''): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $definition) {
            $field = (string) $field;
            $value = $data[$field] ?? null;
            $parsed = $this->parse($definition);
            $nullable = in_array('nullable', $parsed['names'], true);
            $empty = $this->isEmpty($value);

            if ($empty && $nullable) {
                $validated[$field] = null;
                continue;
            }

            foreach ($parsed['rules'] as $rule) {
                $message = $this->check($field, $value, $rule['name'], $rule['params'], $data, $context);
                if ($message !== null) {
                    $errors[$field] = $message;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        $errors = $this->hooks->filter('validation.errors.' . $form, $errors, $data, $context);
        if (!is_array($errors)) {
            $errors = [];
        }

        if ($errors !== []) {
            /** @var array<string, string> $normalized */
            $normalized = [];
            foreach ($errors as $field => $message) {
                $normalized[(string) $field] = (string) $message;
            }

            throw new ValidationException($normalized, $this->safeInput($data), $form);
        }

        return $validated;
    }

    /**
     * @param list<string> $params
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function check(
        string $field,
        mixed $value,
        string $rule,
        array $params,
        array $data,
        array $context,
    ): ?string {
        $custom = $this->registry->customRule($rule);
        if ($custom !== null) {
            return $custom($value, $params, $data, $context);
        }

        return match ($rule) {
            'nullable' => null,
            'required' => $this->isEmpty($value) ? 'Toto pole je povinné.' : null,
            'filled' => $this->isEmpty($value) ? 'Vyplňte toto pole.' : null,
            'email' => $this->isEmpty($value) || filter_var((string) $value, FILTER_VALIDATE_EMAIL)
                ? null
                : 'Zadejte platný e-mail.',
            'url' => $this->isEmpty($value) || filter_var((string) $value, FILTER_VALIDATE_URL)
                ? null
                : 'Zadejte platnou URL.',
            'numeric' => $this->isEmpty($value) || is_numeric($value) ? null : 'Zadejte číslo.',
            'integer' => $this->isEmpty($value) || filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null
                : 'Zadejte celé číslo.',
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', ''], true)
                ? null
                : 'Neplatná hodnota.',
            'accepted' => in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true)
                ? null
                : 'Toto pole musí být potvrzené.',
            'min' => $this->min($value, (int) ($params[0] ?? 0)),
            'max' => $this->max($value, (int) ($params[0] ?? 0)),
            'between' => $this->between($value, (int) ($params[0] ?? 0), (int) ($params[1] ?? 0)),
            'in' => $this->isEmpty($value) || in_array((string) $value, $params, true)
                ? null
                : 'Vyberte jednu z nabízených hodnot.',
            'not_in' => $this->isEmpty($value) || !in_array((string) $value, $params, true)
                ? null
                : 'Tato hodnota není povolená.',
            'confirmed' => (string) $value === (string) ($data[$field . '_confirmation'] ?? '')
                ? null
                : 'Potvrzení se neshoduje.',
            'same' => (string) $value === (string) ($data[$params[0] ?? ''] ?? '')
                ? null
                : 'Hodnoty se neshodují.',
            'different' => (string) $value !== (string) ($data[$params[0] ?? ''] ?? '')
                ? null
                : 'Hodnoty se musí lišit.',
            'regex' => $this->regex($value, $params[0] ?? ''),
            'date' => $this->isEmpty($value) || strtotime((string) $value) !== false
                ? null
                : 'Zadejte platné datum.',
            'alpha' => $this->isEmpty($value) || preg_match('/^[\p{L}]+$/u', (string) $value)
                ? null
                : 'Použijte jen písmena.',
            'alpha_dash' => $this->isEmpty($value) || preg_match('/^[\p{L}0-9_-]+$/u', (string) $value)
                ? null
                : 'Použijte jen písmena, čísla, pomlčku nebo podtržítko.',
            'json' => $this->isEmpty($value) || $this->isJson((string) $value)
                ? null
                : 'Zadejte platný JSON.',
            default => throw new \InvalidArgumentException('Neznámé validační pravidlo „' . $rule . '“.'),
        };
    }

    private function min(mixed $value, int $min): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_numeric($value) && !is_string($value)) {
            return (float) $value >= $min ? null : 'Minimální hodnota je ' . $min . '.';
        }

        $length = mb_strlen((string) $value);

        return $length >= $min ? null : 'Zadejte alespoň ' . $min . ' znaků.';
    }

    private function max(mixed $value, int $max): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_numeric($value) && !is_string($value)) {
            return (float) $value <= $max ? null : 'Maximální hodnota je ' . $max . '.';
        }

        $length = mb_strlen((string) $value);

        return $length <= $max ? null : 'Maximálně ' . $max . ' znaků.';
    }

    private function between(mixed $value, int $min, int $max): ?string
    {
        return $this->min($value, $min) ?? $this->max($value, $max);
    }

    private function regex(mixed $value, string $pattern): ?string
    {
        if ($this->isEmpty($value) || $pattern === '') {
            return $pattern === '' ? 'Neplatný vzor.' : null;
        }

        $ok = @preg_match($pattern, (string) $value);
        if ($ok === false) {
            return 'Neplatný vzor.';
        }

        return $ok === 1 ? null : 'Hodnota má neplatný formát.';
    }

    private function isJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @return array{names: list<string>, rules: list<array{name: string, params: list<string>}>}
     */
    private function parse(mixed $definition): array
    {
        if (is_callable($definition) && !is_string($definition)) {
            return [
                'names' => ['custom'],
                'rules' => [['name' => 'custom', 'params' => []]],
            ];
        }

        $parts = [];
        if (is_string($definition)) {
            $parts = $this->split($definition);
        } elseif (is_array($definition)) {
            foreach ($definition as $item) {
                if (is_string($item)) {
                    $parts = [...$parts, ...$this->split($item)];
                }
            }
        }

        $rules = [];
        $names = [];
        foreach ($parts as $part) {
            [$name, $paramString] = array_pad(explode(':', $part, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $params = $paramString === '' ? [] : array_map('trim', explode(',', $paramString));
            $names[] = $name;
            $rules[] = ['name' => $name, 'params' => $params];
        }

        return ['names' => $names, 'rules' => $rules];
    }

    /** @return list<string> */
    private function split(string $definition): array
    {
        if (str_starts_with($definition, 'regex:')) {
            return [$definition];
        }

        return array_values(array_filter(
            array_map('trim', explode('|', $definition)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function safeInput(array $data): array
    {
        $sensitive = ['password', 'password_confirmation', 'current_password', '_token', '_csrf'];
        foreach ($sensitive as $key) {
            unset($data[$key]);
        }

        foreach ($data as $key => $value) {
            if (str_contains(strtolower((string) $key), 'password')) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
