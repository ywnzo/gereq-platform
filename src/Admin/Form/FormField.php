<?php

declare(strict_types=1);

namespace Gereq\Admin\Form;

use Attribute;

/**
 * Form metadata on an entity property (required, label, type for FormGenerator).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class FormField
{
    /**
     * @param array<string, mixed> $options Extra UI options (placeholder, maxlength, step, class→extra, searchable, …)
     * @param list<array{value?: string, label?: string}|string> $choices Static select options (prefer choicesFrom)
     */
    public function __construct(
        public readonly bool $required = false,
        /** Override request/form field name (default: Doctrine column name or snake_case property). */
        public readonly ?string $name = null,
        public readonly string $label = '',
        /** text|email|number|password|url|textarea|editor|select|checkbox|switch|color|hidden */
        public readonly string $type = 'text',
        public readonly array $options = [],
        public readonly array $choices = [],
        /**
         * Entity class constant name with list of values (e.g. 'STATUSES').
         * Resolved as ClassName::CONST → choice values.
         */
        public readonly string $choicesFrom = '',
        /** Trans key prefix for choice labels: prefix + value (e.g. 'listings.status.'). */
        public readonly string $choiceLabel = '',
        /** Optional empty option label (literal or trans key), value ''. */
        public readonly string $emptyLabel = '',
        public readonly string $hint = '',
        public readonly string $extra = '',
        /** Tab / section key for forms()->render(..., { group: 'basic' }) */
        public readonly string $group = '',
        /** Raw HTML attributes string for the control (e.g. data-ares-fill) */
        public readonly string $attrs = '',
        /** Sort within group (lower first). 0 = declaration order among other zeros. */
        public readonly int $order = 0,
    ) {
    }
}
