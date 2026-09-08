<?php

declare(strict_types=1);

namespace Gereq\Admin\Form;

use Attribute;

/**
 * Marks an entity for automatic form rendering via forms()->render($entity).
 *
 * Field UI lives on #[FormField] (type, group, choicesFrom, order, …).
 * Twig stays responsible for layout chrome and runtime-only choices (relations).
 *
 * @param list<string> $exclude Property names skipped (default: id)
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FormGenerator
{
    /**
     * @param list<string> $exclude
     */
    public function __construct(
        public readonly array $exclude = ['id'],
        /** Optional ruleset / form name for old() + error() + required merge. */
        public readonly ?string $form = null,
        public readonly int $cols = 2,
    ) {
    }
}
