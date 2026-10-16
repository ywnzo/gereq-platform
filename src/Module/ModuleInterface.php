<?php

declare(strict_types=1);

namespace Gereq\Module;

interface ModuleInterface
{
    /** @var list<string> Relative paths to bundled submodules; must match module.php. */
    public const SUBMODULES = [];

    public function id(): string;

    public function version(): string;

    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;
}
