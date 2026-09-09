<?php

declare(strict_types=1);

namespace Gereq\Api\OpenApi;

/**
 * Převod GEREQ validation rulesetů na OpenAPI 3 JSON Schema (properties).
 */
final class ValidationRulesToSchema
{
    /**
     * @param array<string, mixed> $rules
     * @return array{type: string, required?: list<string>, properties: array<string, mixed>, additionalProperties: bool}
     */
    public function convert(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $parts = $this->parts($rule);
            $schema = $this->fieldSchema($parts);
            $properties[$field] = $schema;
            if (in_array('required', $parts, true) && !in_array('nullable', $parts, true)) {
                $required[] = $field;
            }
        }

        $out = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => true,
        ];
        if ($required !== []) {
            $out['required'] = $required;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function parts(mixed $rule): array
    {
        if (is_array($rule)) {
            $flat = [];
            foreach ($rule as $item) {
                if (is_string($item)) {
                    foreach ($this->parts($item) as $p) {
                        $flat[] = $p;
                    }
                }
            }

            return $flat;
        }

        if (!is_string($rule) || $rule === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $rule)), static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param list<string> $parts
     * @return array<string, mixed>
     */
    private function fieldSchema(array $parts): array
    {
        $schema = ['type' => 'string'];
        $nullable = in_array('nullable', $parts, true);

        foreach ($parts as $part) {
            if ($part === 'required' || $part === 'nullable' || $part === 'string' || $part === 'filled') {
                continue;
            }
            if ($part === 'integer' || $part === 'int') {
                $schema['type'] = 'integer';
                continue;
            }
            if ($part === 'numeric' || $part === 'float' || $part === 'decimal') {
                $schema['type'] = 'number';
                continue;
            }
            if ($part === 'boolean' || $part === 'bool') {
                $schema['type'] = 'boolean';
                continue;
            }
            if ($part === 'array') {
                $schema['type'] = 'array';
                $schema['items'] = ['type' => 'string'];
                continue;
            }
            if ($part === 'email') {
                $schema['format'] = 'email';
                continue;
            }
            if ($part === 'url') {
                $schema['format'] = 'uri';
                continue;
            }
            if ($part === 'uuid') {
                $schema['format'] = 'uuid';
                continue;
            }
            if ($part === 'date' || $part === 'datetime' || $part === 'date_format:Y-m-d') {
                $schema['format'] = str_contains($part, 'datetime') ? 'date-time' : 'date';
                continue;
            }
            if (str_starts_with($part, 'min:')) {
                $n = (int) substr($part, 4);
                if (($schema['type'] ?? '') === 'integer' || ($schema['type'] ?? '') === 'number') {
                    $schema['minimum'] = $n;
                } else {
                    $schema['minLength'] = $n;
                }
                continue;
            }
            if (str_starts_with($part, 'max:')) {
                $n = (int) substr($part, 4);
                if (($schema['type'] ?? '') === 'integer' || ($schema['type'] ?? '') === 'number') {
                    $schema['maximum'] = $n;
                } else {
                    $schema['maxLength'] = $n;
                }
                continue;
            }
            if (str_starts_with($part, 'in:')) {
                $vals = array_values(array_filter(array_map('trim', explode(',', substr($part, 3)))));
                if ($vals !== []) {
                    $schema['enum'] = $vals;
                }
            }
        }

        if ($nullable) {
            $type = $schema['type'] ?? 'string';
            $schema['type'] = [$type, 'null'];
        }

        return $schema;
    }
}
