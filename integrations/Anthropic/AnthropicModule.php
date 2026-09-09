<?php

declare(strict_types=1);

namespace Integrations\Anthropic;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;

/**
 * Marks the Anthropic AI provider as an installable plug-in.
 * Built-in drivers are registered by modules/Ai; enabling this package documents intent / future overrides.
 */
final class AnthropicModule implements ModuleInterface
{
    public function id(): string
    {
        return 'anthropic';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
