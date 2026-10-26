<?php

declare(strict_types=1);

namespace Gereq\Cms;

use Gereq\Module\EnabledModulesProvider;

/** Optional CMS integration available to every module without a CMS dependency. */
final class Cms
{
    /** @var array<string, array{label: string, fields: list<string>, template: string, load: \Closure}> */
    private array $sources = [];
    private ?FormAttachmentStorage $attachments = null;

    /** @var (\Closure(string, string, string): void)|null */
    private ?\Closure $deliveryUpdater = null;

    /** @var array<string, array{label: string, ruleset: string, fields: array<string, string>, handle: \Closure, operations: ?FormActionOperations, afterCommit: ?\Closure}> */
    private array $formActions = [];

    /**
     * Actions are registered by enabled modules; handlers receive validated values only.
     * @param array<string, string> $fields Required input names mapped to control types.
     * @param callable(array<string, string>): array<string, mixed> $handle
     * @param (callable(array<string, mixed>): string)|null $afterCommit Notification side effects run only after commit.
     */
    public function registerFormAction(string $id, string $label, string $ruleset, array $fields, callable $handle, ?FormActionOperations $operations = null, ?callable $afterCommit = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if (isset($this->formActions[$id])) {
            throw new \LogicException('Duplicate CMS form action: ' . $id);
        }
        $this->formActions[$id] = ['label' => $label, 'ruleset' => $ruleset, 'fields' => $fields, 'handle' => \Closure::fromCallable($handle), 'operations' => $operations, 'afterCommit' => $afterCommit === null ? null : \Closure::fromCallable($afterCommit)];
    }

    /** @return list<array{id: string, label: string, ruleset: string, fields: array<string, string>}> */
    public function formActions(): array
    {
        $items = [];
        foreach ($this->formActions as $id => $action) {
            $items[] = ['id' => $id, 'label' => $action['label'], 'ruleset' => $action['ruleset'], 'fields' => $action['fields']];
        }
        return $items;
    }

    /** @return array{label: string, ruleset: string, fields: array<string, string>, handle: \Closure, operations: ?FormActionOperations, afterCommit: ?\Closure}|null */
    public function formAction(string $id): ?array
    {
        return $this->isEnabled() ? ($this->formActions[$id] ?? null) : null;
    }

    /** @param callable(string, string, string): void $updater */
    public function registerFormDeliveryUpdater(callable $updater): void
    {
        $this->deliveryUpdater = \Closure::fromCallable($updater);
    }

    public function updateFormDeliveryStatus(string $action, string $recordId, string $status): void
    {
        if (!in_array($status, ['sent', 'failed', 'queued', 'not_configured'], true)) {
            throw new \InvalidArgumentException('Invalid form notification status.');
        }
        if ($this->deliveryUpdater !== null) {
            ($this->deliveryUpdater)($action, $recordId, $status);
        }
    }

    public function registerAttachmentStorage(FormAttachmentStorage $storage): void
    {
        $this->attachments = $storage;
    }

    /** @return list<array{id: string, filename: string, scan_status: string, route: string, params: array<string, string>}> */
    public function recordAttachments(string $action, string $recordId): array
    {
        return $this->attachments?->forRecord($action, $recordId) ?? [];
    }

    public function removeRecordAttachments(string $action, string $recordId): void
    {
        $this->attachments?->removeForRecord($action, $recordId);
    }

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
