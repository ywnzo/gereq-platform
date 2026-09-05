<?php

declare(strict_types=1);

namespace Gereq\Validation;

use Gereq\Hook\HookManager;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ValidationRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $rulesets = [];

    /** @var array<string, callable(mixed, list<string>, array<string, mixed>, array<string, mixed>): ?string> */
    private array $rules = [];

    /** @var array<string, list<callable(array<string, mixed>, array<string, string>, array<string, mixed>): array<string, string>>> */
    private array $after = [];

    public function __construct(
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $rules
     * @param callable|null $after
     */
    public function ruleset(string $name, array $rules, ?callable $after = null): self
    {
        $this->rulesets[$name] = $rules;
        if ($after !== null) {
            $this->after($name, $after);
        }

        return $this;
    }

    /**
     * @param callable(mixed, list<string>, array<string, mixed>, array<string, mixed>): ?string $callback
     */
    public function rule(string $name, callable $callback): self
    {
        $this->rules[$name] = $callback;

        return $this;
    }

    /**
     * @param callable $callback
     */
    public function after(string $ruleset, callable $callback): self
    {
        $this->after[$ruleset][] = $callback;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->rulesets[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rulesFor(string $name): array
    {
        return $this->rulesets[$name] ?? [];
    }

    /**
     * @return callable(mixed, list<string>, array<string, mixed>, array<string, mixed>): ?string|null
     */
    public function customRule(string $name): ?callable
    {
        return $this->rules[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validate(string $name, array $data, array $context = []): array
    {
        $rules = $this->rulesets[$name] ?? null;
        if ($rules === null) {
            throw new \InvalidArgumentException(
                $this->translator->trans('validation.ruleset_missing', ['%name%' => $name]),
            );
        }

        $rules = $this->hooks->filter('validation.ruleset', $rules, $name, $data, $context);
        if (!is_array($rules)) {
            $rules = $this->rulesets[$name];
        }

        $validator = new Validator($this, $this->hooks, $this->translator);
        $errors = [];
        $validated = [];

        try {
            $validated = $validator->validate($data, $rules, $context, $name);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        foreach ($this->after[$name] ?? [] as $callback) {
            $errors = $callback($data, $errors, $context);
        }
        $errors = $this->hooks->filter('validation.errors', $errors, $name, $data, $context);
        if (!is_array($errors)) {
            $errors = [];
        }

        if ($errors === []) {
            return $validated !== [] ? $validated : $data;
        }

        /** @var array<string, string> $normalized */
        $normalized = [];
        foreach ($errors as $field => $message) {
            $normalized[(string) $field] = (string) $message;
        }

        throw new ValidationException(
            $normalized,
            $data,
            $name,
            $this->translator->trans('validation.form_failed'),
        );
    }
}
