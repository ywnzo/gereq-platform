<?php

declare(strict_types=1);

namespace Modules\Cookie;

use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Cookie\Application\CookieConfig;
use Modules\Cookie\Http\CookieSettingsController;
use Modules\Cookie\Http\PublicCookieController;
use Modules\Cookie\Publish\CookieSettingsPublishAdapter;

final class CookieModule implements ModuleInterface
{
    public function id(): string
    {
        return 'cookie';
    }

    public function version(): string
    {
        return '0.2.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('cookie.manage', 'perm.cookie.manage', 'perm.group.cookie');
        $context->capabilities()->register('cookie', $this->id(), $context->get(CookieConfig::class));
        foreach (CookieConfig::definitions() as $key => $definition) {
            $context->settings()->define($key, $definition['type'], $key, $definition['default'], $this->id(), [], false);
        }
        $context->publishing($this->id())->category('cookie.settings')->labelKey('publish.category.cookie')->sort(55)->adapter(CookieSettingsPublishAdapter::class);
        $rules = [];
        foreach (CookieConfig::definitions() as $key => $definition) {
            $field = str_replace('cookie.', '', $key);
            $rules[$field] = match ($definition['type']) {
                'boolean' => 'required|boolean',
                'integer' => 'required|integer|min:1|max:365',
                default => 'required|max:2000',
            };
        }
        foreach (['accent', 'background', 'color'] as $color) {
            $rules[$color] = 'required|regex:~^#[a-fA-F0-9]{6}$~';
        }
        $rules['storage_key'] = 'required|max:64|regex:~^[a-zA-Z0-9_.-]+$~';
        $rules['policy_path'] = 'required|max:500|regex:~^/(?!/)[^\\\\\\s]*$~';
        $rules['position'] = 'required|in:left,right';
        $rules['style'] = 'required|in:card,compact,square,banner';
        $rules['icon'] = 'nullable|max:16';
        $rules['image'] = ['nullable', 'regex:~^[a-f0-9]{32}\.(png|jpg|webp|gif)$~'];
        $context->validation()->ruleset('cookie.settings', $rules);
        $context->router()->group('/admin/cookie', function (Router $routes): void {
            $routes->get('/preview', [CookieSettingsController::class, 'preview'])->name('admin.cookie.preview')->permission('cookie.manage');
            $routes->get('/preview-config', [CookieSettingsController::class, 'previewConfig'])->name('admin.cookie.preview_config')->permission('cookie.manage');
            $routes->get('', [CookieSettingsController::class, 'index'])->name('admin.cookie.index')->permission('cookie.manage');
            $routes->post('', [CookieSettingsController::class, 'save'])->name('admin.cookie.save')->permission('cookie.manage');
        }, ['middleware' => ['auth'], 'module' => 'cookie']);
        foreach (['/api/public/cookie/config', '/api/public/webplus/cookies'] as $path) {
            $context->router()->get($path, [PublicCookieController::class, 'config'])->name($path === '/api/public/cookie/config' ? 'api.public.cookie.config' : 'api.public.webplus.cookies');
            $context->router()->add($path, ['OPTIONS'], [PublicCookieController::class, 'options']);
        }
        $context->router()->get('/api/public/cookie/image/{name}', [PublicCookieController::class, 'image'])->name('api.public.cookie.image');
        $context->router()->get('/api/public/cookie/widget.js', [PublicCookieController::class, 'widget'])->name('api.public.cookie.widget');
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(id: 'cookie', label: 'nav.cookie', icon: 'cookie', route: 'admin.cookie.index', permission: 'cookie.manage', order: 63));
        $context->hooks()->addFilter('storefront.spa_html', static function (string $html) use ($context): string {
            if (str_contains($html, 'data-gereq-cookie')) {
                return $html;
            }
            $src = htmlspecialchars($context->router()->url('api.public.cookie.widget') . '?v=0.2.0', ENT_QUOTES);
            $snippet = '<script defer data-gereq-cookie src="' . $src . '"></script>';

            return str_contains($html, '</body>') ? str_replace('</body>', $snippet . '</body>', $html) : $html . $snippet;
        });
    }
}
