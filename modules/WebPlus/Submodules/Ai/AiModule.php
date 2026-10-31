<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\WebPlus\Submodules\Ai\Application\AiProvider;
use Modules\WebPlus\Submodules\Ai\Http\Public\PublicAiController;
use Modules\WebPlus\Submodules\Ai\Http\ToolController;

final class AiModule implements ModuleInterface
{
    public function id(): string
    {
        return 'webplus_ai';
    }

    public function version(): string
    {
        return '2.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('webplus_ai.view', 'perm.webplus_ai.view', 'perm.group.webplus_ai');
        $context->permissions()->register('webplus_ai.manage', 'perm.webplus_ai.manage', 'perm.group.webplus_ai');

        $provider = $context->get(AiProvider::class);
        $context->capabilities()->register('webplus.ai', $this->id(), $provider);

        $context->settings()->define(
            'webplus_ai.enabled',
            SettingRecord::TYPE_BOOLEAN,
            'webplus_ai.settings.enabled',
            true,
            $this->id(),
            [],
            false,
        );

        $context->router()->group('/admin/webplus-ai', function ($routes): void {
            $routes->get('/tools', [ToolController::class, 'index'])->name('admin.webplus_ai.tools.index')->permission('webplus_ai.view');
            $routes->get('/tools/create', [ToolController::class, 'create'])->name('admin.webplus_ai.tools.create')->permission('webplus_ai.manage');
            $routes->post('/tools', [ToolController::class, 'store'])->name('admin.webplus_ai.tools.store')->permission('webplus_ai.manage');
            $routes->get('/tools/{id}/edit', [ToolController::class, 'edit'])->name('admin.webplus_ai.tools.edit')->permission('webplus_ai.view');
            $routes->post('/tools/{id}', [ToolController::class, 'update'])->name('admin.webplus_ai.tools.update')->permission('webplus_ai.manage');
            $routes->post('/tools/{id}/toggle', [ToolController::class, 'toggle'])->name('admin.webplus_ai.tools.toggle')->permission('webplus_ai.manage');
            $routes->post('/tools/{id}/delete', [ToolController::class, 'destroy'])->name('admin.webplus_ai.tools.destroy')->permission('webplus_ai.manage');
            $routes->get('/settings', [ToolController::class, 'settings'])->name('admin.webplus_ai.settings')->permission('webplus_ai.manage');
            $routes->post('/settings', [ToolController::class, 'saveSettings'])->name('admin.webplus_ai.settings.save')->permission('webplus_ai.manage');
        }, ['middleware' => ['auth'], 'module' => 'webplus_ai']);

        $opts = [PublicAiController::class, 'options'];
        $context->router()->add('/api/public/webplus-ai/tools.json', ['OPTIONS'], $opts)
            ->name('api.public.webplus_ai.tools_json.options');
        $context->router()->add('/api/public/webplus-ai/tools', ['OPTIONS'], $opts)
            ->name('api.public.webplus_ai.tools.options');
        $context->router()->add('/api/public/webplus-ai/ai.txt', ['OPTIONS'], $opts)
            ->name('api.public.webplus_ai.ai_txt.options');
        $context->router()->add('/api/public/webplus-ai/llms-full.txt', ['OPTIONS'], $opts)
            ->name('api.public.webplus_ai.llms_full.options');
        $context->router()->get('/api/public/webplus-ai/tools.json', [PublicAiController::class, 'toolsJson'])
            ->name('api.public.webplus_ai.tools_json');
        $context->router()->get('/api/public/webplus-ai/tools', [PublicAiController::class, 'tools'])
            ->name('api.public.webplus_ai.tools');
        $context->router()->get('/api/public/webplus-ai/ai.txt', [PublicAiController::class, 'aiTxt'])
            ->name('api.public.webplus_ai.ai_txt');
        $context->router()->get('/api/public/webplus-ai/llms-full.txt', [PublicAiController::class, 'llmsFullTxt'])
            ->name('api.public.webplus_ai.llms_full');
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'webplus.ai',
            label: 'nav.webplus_ai.tools',
            route: 'admin.webplus_ai.tools.index',
            permission: 'webplus_ai.view',
            parent: 'webplus',
            order: 56,
        ));
        $context->menu()->add(new MenuItem(
            id: 'webplus.ai.settings',
            label: 'nav.webplus_ai.settings',
            route: 'admin.webplus_ai.settings',
            permission: 'webplus_ai.manage',
            parent: 'webplus',
            order: 57,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'webplus_ai.tools',
            label: 'palette.webplus_ai.tools',
            url: '/admin/webplus-ai/tools',
            icon: 'smart_toy',
            permission: 'webplus_ai.view',
            keywords: ['ai', 'mcp', 'webmcp', 'agent', 'tools', 'web+', 'webplus'],
        ));

        $provider = $context->get(AiProvider::class);

        $context->hooks()->addFilter('webplus.public_site_endpoints', static function (array $endpoints) use ($provider): array {
            if (!$provider->isEnabled()) {
                return $endpoints;
            }
            $endpoints['agent_tools'] = '/api/public/webplus-ai/tools';
            $endpoints['mcp'] = '/tools.json';
            $endpoints['ai_txt'] = '/ai.txt';
            $endpoints['llms_full'] = '/llms-full.txt';

            return $endpoints;
        });

        $context->hooks()->addFilter('webplus.llms_txt', static function (string $body) use ($provider): string {
            if (!$provider->isEnabled()) {
                return $body;
            }
            $section = "## Agent tools (WebMCP)\n"
                . "- [Tool catalog](/tools.json)\n"
                . "- [Full schemas](/llms-full.txt)\n"
                . "- [AI policy](/ai.txt)\n";
            $body = preg_replace(
                '/\n*## Agent tools \(WebMCP\)\n(?:- .+\n)*/u',
                "\n\n",
                $body,
            ) ?? $body;
            $body = rtrim($body);
            if ($body !== '') {
                $body .= "\n\n";
            }

            return $body . $section;
        });
    }
}
