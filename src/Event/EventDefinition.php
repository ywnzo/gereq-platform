<?php

declare(strict_types=1);

namespace Gereq\Event;

final class EventDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly string $module,
        public readonly int $version = 1,
        public readonly string $description = '',
        public readonly bool $webhookEnabled = true,
    ) {
    }

    public static function make(string $type): self
    {
        return new self($type, 'unknown');
    }

    public function withModule(string $module): self
    {
        return new self($this->type, $module, $this->version, $this->description, $this->webhookEnabled);
    }

    public function version(int $version): self
    {
        return new self($this->type, $this->module, $version, $this->description, $this->webhookEnabled);
    }

    public function description(string $description): self
    {
        return new self($this->type, $this->module, $this->version, $description, $this->webhookEnabled);
    }

    public function webhookEnabled(bool $enabled = true): self
    {
        return new self($this->type, $this->module, $this->version, $this->description, $enabled);
    }
}
