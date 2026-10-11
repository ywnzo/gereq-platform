<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublishingCategoryBuilder
{
    private string $label = '';

    private int $sort = 100;

    public function __construct(
        private readonly Publishing $parent,
        private readonly PublishableRegistry $registry,
        private readonly ContainerInterface $container,
        private readonly TranslatorInterface $translator,
        private readonly ?string $moduleId,
        private readonly string $category,
    ) {
        $this->label = $category;
    }

    /** Human label (already translated) or translation key resolved via translator. */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** Translation key — resolved immediately at register time. */
    public function labelKey(string $key): self
    {
        $this->label = $this->translator->trans($key);

        return $this;
    }

    public function sort(int $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Register the category and return the parent Publishing for chaining.
     *
     * @param class-string<PublishableAdapterInterface>|PublishableAdapterInterface $adapter
     */
    public function adapter(string|PublishableAdapterInterface $adapter): Publishing
    {
        if (is_string($adapter)) {
            $resolved = $this->container->get($adapter);
            if (!$resolved instanceof PublishableAdapterInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Publishing adapter "%s" must implement %s.',
                    $adapter,
                    PublishableAdapterInterface::class,
                ));
            }
            $adapter = $resolved;
        }

        $this->registry->register(
            $this->category,
            $this->label,
            $adapter,
            $this->moduleId,
            $this->sort,
        );

        return $this->parent;
    }
}
