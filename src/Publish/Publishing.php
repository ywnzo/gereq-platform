<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fluent module-facing API for registering publishable categories.
 *
 * Usage:
 *   $context->publishing($this->id())
 *       ->category('listings')
 *       ->labelKey('publish.category.listings')
 *       ->sort(40)
 *       ->adapter(ListingPublishAdapter::class);
 */
final class Publishing
{
    public function __construct(
        private readonly PublishableRegistry $registry,
        private readonly ContainerInterface $container,
        private readonly TranslatorInterface $translator,
        private readonly ?string $moduleId,
    ) {
    }

    public function category(string $category): PublishingCategoryBuilder
    {
        return new PublishingCategoryBuilder(
            $this,
            $this->registry,
            $this->container,
            $this->translator,
            $this->moduleId,
            $category,
        );
    }
}
