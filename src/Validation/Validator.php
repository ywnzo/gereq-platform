<?php

declare(strict_types=1);

namespace Gereq\Validation;

use Gereq\Hook\HookManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Validator
{
    public function __construct(
        private readonly ValidationRegistry $registry,
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
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

            throw new ValidationException(
                $normalized,
                $this->safeInput($data),
                $form,
                $this->translator->trans('validation.form_failed'),
            );
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
            'required' => $this->isEmpty($value) ? $this->t('validation.required') : null,
            'filled' => $this->isEmpty($value) ? $this->t('validation.filled') : null,
            'email' => $this->isEmpty($value) || filter_var((string) $value, FILTER_VALIDATE_EMAIL)
                ? null
                : $this->t('validation.email'),
            'phone' => $this->phone($value),
            'url' => $this->isEmpty($value) || filter_var((string) $value, FILTER_VALIDATE_URL)
                ? null
                : $this->t('validation.url'),
            'numeric' => $this->isEmpty($value) || is_numeric($value) ? null : $this->t('validation.numeric'),
            'integer' => $this->isEmpty($value) || filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null
                : $this->t('validation.integer'),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', ''], true)
                ? null
                : $this->t('validation.boolean'),
            'accepted' => in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true)
                ? null
                : $this->t('validation.accepted'),
            'min' => $this->min($value, (int) ($params[0] ?? 0)),
            'max' => $this->max($value, (int) ($params[0] ?? 0)),
            'between' => $this->between($value, (int) ($params[0] ?? 0), (int) ($params[1] ?? 0)),
            'in' => $this->isEmpty($value) || in_array((string) $value, $params, true)
                ? null
                : $this->t('validation.in'),
            'not_in' => $this->isEmpty($value) || !in_array((string) $value, $params, true)
                ? null
                : $this->t('validation.not_in'),
            'confirmed' => (string) $value === (string) ($data[$field . '_confirmation'] ?? '')
                ? null
                : $this->t('validation.confirmed'),
            'same' => (string) $value === (string) ($data[$params[0] ?? ''] ?? '')
                ? null
                : $this->t('validation.same'),
            'different' => (string) $value !== (string) ($data[$params[0] ?? ''] ?? '')
                ? null
                : $this->t('validation.different'),
            'regex' => $this->regex($value, $params[0] ?? ''),
            'date' => $this->isEmpty($value) || strtotime((string) $value) !== false
                ? null
                : $this->t('validation.date'),
            'alpha' => $this->isEmpty($value) || preg_match('/^[\p{L}]+$/u', (string) $value)
                ? null
                : $this->t('validation.alpha'),
            'alpha_dash' => $this->isEmpty($value) || preg_match('/^[\p{L}0-9_-]+$/u', (string) $value)
                ? null
                : $this->t('validation.alpha_dash'),
            'json' => $this->isEmpty($value) || $this->isJson((string) $value)
                ? null
                : $this->t('validation.json'),
            default => throw new \InvalidArgumentException(
                $this->t('validation.unknown_rule', ['%rule%' => $rule]),
            ),
        };
    }

    /** @param array<string, string|int|float> $parameters */
    private function t(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters);
    }

    private function phone(mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $normalized = preg_replace('/[\s\-().]/', '', (string) $value) ?? '';
        if (str_starts_with($normalized, '00420')) {
            $normalized = '+' . substr($normalized, 2);
        }

        // CZ: +420 + 9 digits (first 2–9 — mobile/landline, not 0/1)
        if (preg_match('/^\+420[2-9]\d{8}$/', $normalized) === 1) {
            return null;
        }

        return $this->t('validation.phone');
    }

    private function min(mixed $value, int $min): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_numeric($value) && !is_string($value)) {
            return (float) $value >= $min
                ? null
                : $this->t('validation.min.numeric', ['%min%' => $min]);
        }

        $length = mb_strlen((string) $value);

        return $length >= $min
            ? null
            : $this->t('validation.min.string', ['%min%' => $min]);
    }

    private function max(mixed $value, int $max): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_numeric($value) && !is_string($value)) {
            return (float) $value <= $max
                ? null
                : $this->t('validation.max.numeric', ['%max%' => $max]);
        }

        $length = mb_strlen((string) $value);

        return $length <= $max
            ? null
            : $this->t('validation.max.string', ['%max%' => $max]);
    }

    private function between(mixed $value, int $min, int $max): ?string
    {
        return $this->min($value, $min) ?? $this->max($value, $max);
    }

    private function regex(mixed $value, string $pattern): ?string
    {
        if ($this->isEmpty($value) || $pattern === '') {
            return $pattern === '' ? $this->t('validation.regex.invalid') : null;
        }

        $ok = @preg_match($pattern, (string) $value);
        if ($ok === false) {
            return $this->t('validation.regex.invalid');
        }

        return $ok === 1 ? null : $this->t('validation.regex.format');
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
            $params = $paramString === '' ? [] : $this->parseParams($name, $paramString);
            $names[] = $name;
            $rules[] = ['name' => $name, 'params' => $params];
        }

        return ['names' => $names, 'rules' => $rules];
    }

    /**
     * Split rule parameters. For in/not_in, ";" separates list items so values may contain commas
     * (e.g. in:index,follow;index,nofollow).
     *
     * @return list<string>
     */
    private function parseParams(string $ruleName, string $paramString): array
    {
        $delimiter = in_array($ruleName, ['in', 'not_in'], true) && str_contains($paramString, ';')
            ? ';'
            : ',';

        return array_values(array_filter(
            array_map('trim', explode($delimiter, $paramString)),
            static fn (string $item): bool => $item !== '',
        ));
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
