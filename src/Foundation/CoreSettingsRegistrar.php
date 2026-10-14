<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use Gereq\I18n\LocaleCatalog;
use Gereq\Publish\Adapter\BrandingPublishAdapter;
use Gereq\Publish\ContentVersion;
use Gereq\Publish\PublishableRegistry;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CoreSettingsRegistrar
{
    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly Settings $settings,
        private readonly ValidationRegistry $validation,
        private readonly LocaleCatalog $locales,
        private readonly PublishableRegistry $publishables,
        private readonly BrandingPublishAdapter $brandingPublishAdapter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->settings();
        $this->publishables();
        $this->validation();
    }

    private function publishables(): void
    {
        $this->publishables->register(
            'branding',
            $this->translator->trans('publish.category.branding'),
            $this->brandingPublishAdapter,
            null,
            10,
        );
    }


    private function permissions(): void
    {
        $items = [
            ['users.view', 'perm.users.view', 'perm.group.users'],
            ['users.create', 'perm.users.create', 'perm.group.users'],
            ['users.edit', 'perm.users.edit', 'perm.group.users'],
            ['users.delete', 'perm.users.delete', 'perm.group.users'],
            ['users.manage', 'perm.users.manage', 'perm.group.users'],
            ['roles.view', 'perm.roles.view', 'perm.group.roles'],
            ['roles.manage', 'perm.roles.manage', 'perm.group.roles'],
            ['modules.manage', 'perm.modules.manage', 'perm.group.system'],
            ['settings.manage', 'perm.settings.manage', 'perm.group.system'],
            ['audit.view', 'perm.audit.view', 'perm.group.system'],
            ['developer.api.view', 'perm.developer.api.view', 'perm.group.developer'],
            ['developer.tokens.manage', 'perm.developer.tokens.manage', 'perm.group.developer'],
            ['developer.events.view', 'perm.developer.events.view', 'perm.group.developer'],
        ];

        foreach ($items as [$name, $label, $group]) {
            $this->permissions->register($name, $label, $group);
        }
    }

    private function settings(): void
    {
        $appName = (string) config('app.name', 'GEREQ Platform');
        $this->settings->define('app.name', SettingRecord::TYPE_STRING, 'settings.app.name', $appName);
        $this->settings->define(
            'app.locale',
            SettingRecord::TYPE_STRING,
            'settings.app.locale',
            (string) config('app.locale', $this->locales->fallback()),
            null,
            $this->locales->all(),
        );
        $this->settings->define('admin.theme', SettingRecord::TYPE_STRING, 'settings.admin.theme', 'system', null, [
            'system' => 'settings.theme.system',
            'light' => 'settings.theme.light',
            'dark' => 'settings.theme.dark',
        ]);
        $this->settings->define(
            \Gereq\Http\Middleware\AutoUpdateModulesMiddleware::SETTING_ENABLED,
            SettingRecord::TYPE_BOOLEAN,
            'settings.admin.modules.auto_update',
            false,
        );
        $this->settings->define(
            \Gereq\Admin\Dashboard\Dashboard::LAYOUT_SETTING,
            SettingRecord::TYPE_JSON,
            'settings.dashboard.layout',
            [],
        );
        $this->settings->define(
            ContentVersion::SETTING_KEY,
            SettingRecord::TYPE_STRING,
            'settings.app.content_version',
            ContentVersion::DEFAULT,
        );

        // Storefront source + rebuild (local path or user@host:/path).
        $this->settings->define(
            \Gereq\Http\StorefrontRebuild::SETTING_PATH,
            SettingRecord::TYPE_STRING,
            'settings.storefront.path',
            '',
        );
        $this->settings->define(
            \Gereq\Http\StorefrontRebuild::SETTING_COMMAND,
            SettingRecord::TYPE_STRING,
            'settings.storefront.build_command',
            \Gereq\Http\StorefrontRebuild::DEFAULT_COMMAND,
        );

        // Public storefront response cache (file or redis).
        $this->settings->define(
            \Gereq\Cache\StoreFrontCache::SETTING_ENABLED,
            SettingRecord::TYPE_BOOLEAN,
            'settings.storefront.cache_enabled',
            (bool) config('cache.storefront.enabled', true),
        );
        $this->settings->define(
            \Gereq\Cache\StoreFrontCache::SETTING_DRIVER,
            SettingRecord::TYPE_STRING,
            'settings.storefront.cache_driver',
            (string) config('cache.storefront.driver', 'file'),
            null,
            [
                'file' => 'settings.storefront.cache_driver.file',
                'redis' => 'settings.storefront.cache_driver.redis',
            ],
        );
        $this->settings->define(
            \Gereq\Cache\StoreFrontCache::SETTING_TTL,
            SettingRecord::TYPE_INTEGER,
            'settings.storefront.cache_ttl',
            (int) config('cache.storefront.ttl', 300),
        );

        // White-label branding (admin + public site).
        $this->settings->define('brand.name', SettingRecord::TYPE_STRING, 'settings.brand.name', '');
        $this->settings->define('brand.subtitle', SettingRecord::TYPE_STRING, 'settings.brand.subtitle', '');
        $this->settings->define('brand.logo_url', SettingRecord::TYPE_STRING, 'settings.brand.logo_url', '');
        $this->settings->define('brand.favicon_url', SettingRecord::TYPE_STRING, 'settings.brand.favicon_url', '');
        $this->settings->define('brand.color_signal', SettingRecord::TYPE_STRING, 'settings.brand.color_signal', '#d7ff45');
        $this->settings->define('brand.color_ink', SettingRecord::TYPE_STRING, 'settings.brand.color_ink', '#0a0b0a');
        $this->settings->define('brand.color_cream', SettingRecord::TYPE_STRING, 'settings.brand.color_cream', '#f5f5ef');
        $this->settings->define('brand.site_primary', SettingRecord::TYPE_STRING, 'settings.brand.site_primary', '#17375a');
        $this->settings->define('brand.site_name', SettingRecord::TYPE_STRING, 'settings.brand.site_name', '');
        $this->settings->define('brand.site_mark', SettingRecord::TYPE_STRING, 'settings.brand.site_mark', '');
        $this->settings->define('brand.tagline', SettingRecord::TYPE_STRING, 'settings.brand.tagline', '');
        $this->settings->define('brand.footnote', SettingRecord::TYPE_STRING, 'settings.brand.footnote', '');
        $this->settings->define(
            'brand.custom_css',
            SettingRecord::TYPE_STRING,
            'settings.brand.custom_css',
            "/* Admin CSS — override tokens or add rules */\n:root {\n  /* --primary: #47c2ff; */\n}\n",
        );
    }

    private function validation(): void
    {
        $this->validation->ruleset('auth.login', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:1',
            'remember' => 'nullable|boolean',
        ]);
        $this->validation->ruleset('design.demo', [
            'name' => 'required|min:2|max:80',
            'email' => 'required|email',
            'phone' => 'required|phone',
            'website' => 'nullable|url',
            'note' => 'required|min:12|max:500',
            'comment' => 'nullable|max:120',
            'role' => 'required|in:admin,editor,viewer',
            'terms' => 'accepted',
        ]);
        $this->validation->ruleset('roles.save', [
            'name' => 'required|alpha_dash|min:2|max:80',
            'label' => 'required|min:1|max:180',
            'description' => 'nullable|max:2000',
        ]);
        $this->validation->ruleset('roles.update', [
            'label' => 'required|min:1|max:180',
            'description' => 'nullable|max:2000',
        ]);
        $this->validation->ruleset('roles.bulk', [
            'action' => 'required|in:delete',
            'confirmed' => 'nullable',
        ]);
    }
}
