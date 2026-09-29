<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui\Table;

/**
 * Declarative data-table column for ui()->table().
 *
 * @phpstan-type CellType 'text'|'badge'|'html'|'user'|'mute'|'num'
 */
final class TableColumn
{
    /**
     * @param CellType $cell
     * @param (callable(mixed): string)|null $render
     * @param (callable(mixed): string)|null $tone
     * @param array<string, mixed> $meta Extra keys for specialized cells (user initials_key, …)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $sortable = false,
        public readonly string $cell = 'text',
        public readonly ?string $class = null,
        public readonly bool $numeric = false,
        public readonly mixed $render = null,
        public readonly mixed $tone = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array{
     *     sortable?: bool,
     *     cell?: CellType,
     *     class?: string|null,
     *     numeric?: bool,
     *     render?: callable(mixed): string,
     *     tone?: callable(mixed): string,
     *     meta?: array<string, mixed>
     * } $options
     */
    public static function make(string $key, string $label, array $options = []): self
    {
        $cell = (string) ($options['cell'] ?? 'text');
        if (!in_array($cell, ['text', 'badge', 'html', 'user', 'mute', 'num'], true)) {
            $cell = 'text';
        }

        return new self(
            key: $key,
            label: $label,
            sortable: (bool) ($options['sortable'] ?? false),
            cell: $cell,
            class: isset($options['class']) ? (string) $options['class'] : null,
            numeric: (bool) ($options['numeric'] ?? ($cell === 'num')),
            render: $options['render'] ?? null,
            tone: $options['tone'] ?? null,
            meta: is_array($options['meta'] ?? null) ? $options['meta'] : [],
        );
    }

    /** @return list<string> */
    public function thClasses(): array
    {
        $classes = [];
        if ($this->class !== null && $this->class !== '') {
            $classes[] = $this->class;
        }
        if ($this->numeric) {
            $classes[] = 'is-num';
        }

        return $classes;
    }

    public function value(mixed $row): mixed
    {
        if (is_array($row)) {
            return $row[$this->key] ?? null;
        }
        if (is_object($row)) {
            $method = $this->key;
            if (method_exists($row, $method)) {
                return $row->{$method}();
            }
            $getter = 'get' . str_replace('_', '', ucwords($this->key, '_'));
            if (method_exists($row, $getter)) {
                return $row->{$getter}();
            }
            if (isset($row->{$this->key})) {
                return $row->{$this->key};
            }
        }

        return null;
    }
}
