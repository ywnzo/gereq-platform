<?php

declare(strict_types=1);

namespace Gereq\Cms;

use Gereq\Module\EnabledModulesProvider;

/** Optional CMS integration available to every module without a CMS dependency. */
final class Cms
{
    /** @var array<string, array{label: string, fields: list<string>, template: string, load: \Closure}> */
    private array $sources = [];

    public function __construct(private readonly EnabledModulesProvider $modules)
    {
    }

    public function isEnabled(): bool
    {
        return in_array('cms', $this->modules->enabledIds(), true);
    }

    /**
     * The loader must return public, serialized data only and respect publication visibility.
     * @param list<string> $fields
     * @param callable(int): list<array<string, mixed>> $load
     */
    public function register(string $id, string $label, array $fields, callable $load, string $template = ''): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if (isset($this->sources[$id])) {
            throw new \LogicException('Duplicate CMS source: ' . $id);
        }
        $this->sources[$id] = [
            'label' => $label,
            'fields' => $fields,
            'template' => $template,
            'load' => \Closure::fromCallable($load),
        ];
    }

    /** @return list<array{id: string, label: string, fields: list<string>, template: string}> */
    public function catalog(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $items = [];
        foreach ($this->sources as $id => $source) {
            $items[] = ['id' => $id, 'label' => $source['label'], 'fields' => $source['fields'], 'template' => $source['template']];
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function items(string $id, int $limit): array
    {
        if (!$this->isEnabled() || !isset($this->sources[$id])) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $source = $this->sources[$id];
        $items = [];
        foreach (array_slice(($source['load'])($limit), 0, $limit) as $row) {
            $items[] = array_intersect_key($row, array_flip($source['fields']));
        }
        return $items;
    }
}
