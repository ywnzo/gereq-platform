<?php

declare(strict_types=1);

namespace Gereq\Publish;

final class PublishableRegistry
{
    /** @var array<string, PublishableDefinition> */
    private array $definitions = [];

    /** @var array<string, string> settings key → category */
    private array $settingsKeyMap = [];

    public function register(
        string $category,
        string $label,
        PublishableAdapterInterface $adapter,
        ?string $module = null,
        int $sort = 100,
    ): void {
        $this->definitions[$category] = new PublishableDefinition($category, $label, $adapter, $module, $sort);

        foreach ($adapter->settingsKeys() as $key) {
            $this->settingsKeyMap[$key] = $category;
        }
    }

    public function has(string $category): bool
    {
        return isset($this->definitions[$category]);
    }

    public function get(string $category): PublishableDefinition
    {
        if (!isset($this->definitions[$category])) {
            throw new \RuntimeException(sprintf('Publishable category "%s" is not registered.', $category));
        }

        return $this->definitions[$category];
    }

    public function adapter(string $category): PublishableAdapterInterface
    {
        return $this->get($category)->adapter;
    }

    public function isSettingsKey(string $key): bool
    {
        return isset($this->settingsKeyMap[$key]);
    }

    public function categoryForSettingsKey(string $key): ?string
    {
        return $this->settingsKeyMap[$key] ?? null;
    }

    /** @return list<PublishableDefinition> */
    public function all(): array
    {
        $items = array_values($this->definitions);
        usort($items, static fn (PublishableDefinition $a, PublishableDefinition $b): int => $a->sort <=> $b->sort ?: $a->category <=> $b->category);

        return $items;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_map(static fn (PublishableDefinition $d): string => $d->category, $this->all());
    }

    /** @return list<PublishableDefinition> */
    public function forModule(string $moduleId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PublishableDefinition $d): bool => $d->module === $moduleId,
        ));
    }
}
