<?php

declare(strict_types=1);

namespace Gereq\Admin\Form;

use Attribute;

/**
 * Binds an entity class to one or more form / ruleset names
 * so forms()->requiredFields('customers.save') also reads FormField / AdminField.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class FormEntity
{
    public function __construct(
        public readonly string $form,
    ) {
    }
}
