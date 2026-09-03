<?php

declare(strict_types=1);

namespace Gereq\Module;

interface ModuleInterface
{
    public function id(): string;

    public function version(): string;

    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;
}
