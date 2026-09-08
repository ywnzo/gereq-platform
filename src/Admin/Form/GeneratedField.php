<?php

declare(strict_types=1);

namespace Gereq\Admin\Form;

/**
 * One field produced by FormGenerator introspection.
 */
final class GeneratedField
{
    /**
     * @param array<string, mixed> $options
     * @param list<array{value: string, label: string}> $choices
     */
    public function __construct(
        public readonly string $name,
        public readonly string $property,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly mixed $value,
        public readonly array $options = [],
        public readonly array $choices = [],
        public readonly string $extra = '',
        public readonly ?string $hint = null,
        public readonly string $group = '',
        public readonly string $attrs = '',
        public readonly int $order = 0,
    ) {
    }
}
