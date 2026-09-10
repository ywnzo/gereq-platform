<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Dashboard\Widgets\ActivityChartWidget;
use Gereq\Admin\Dashboard\Widgets\ActivityWidget;
use Gereq\Admin\Dashboard\Widgets\ModulesStatsWidget;
use Gereq\Admin\Dashboard\Widgets\SearchHeroWidget;
use Gereq\Admin\Dashboard\Widgets\SettingsShortcutWidget;
use Gereq\Admin\Dashboard\Widgets\UsersStatsWidget;
use Gereq\Admin\Http\AresController;
use Gereq\Admin\Http\AuditController;
use Gereq\Admin\Http\AuthController;
use Gereq\Admin\Http\SecurityController;
use Gereq\Admin\Http\DashboardController;
use Gereq\Api\PublicAresController;
use Gereq\Admin\Http\Developer\ApiTokenController;
use Gereq\Admin\Http\Developer\DeveloperController;
use Gereq\Admin\Http\ModuleController;
use Gereq\Admin\Http\ProfileController;
use Gereq\Admin\Http\RoleController;
use Gereq\Admin\Http\SearchController;
use Gereq\Admin\Http\SettingsController;
use Gereq\Admin\Http\SetupController;
use Gereq\Admin\Http\UserController;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Admin\Search\AdminSearchHit;
use Gereq\Admin\Search\AdminSearchRegistry;
use Gereq\Api\HealthController;
use Gereq\Api\OpenApiController;
use Gereq\Api\PublicBrandingController;
use Gereq\Auth\Authenticator;
use Gereq\Http\BrandAssetController;
use Gereq\Http\DesignController;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Module\ModuleManager;
use Gereq\Publish\Adapter\BrandingPublishAdapter;
use Gereq\Publish\ContentVersion;
use Gereq\Publish\Http\PublishController;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CoreRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly Settings $settings,
        private readonly CommandPalette $palette,
        private readonly AdminSearchRegistry $adminSearch,
        private readonly ValidationRegistry $validation,
        private readonly LocaleCatalog $locales,
        private readonly Dashboard $dashboard,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ModuleManager $modules,
        private readonly Authenticator $auth,
        private readonly PublishableRegistry $publishables,
        private readonly BrandingPublishAdapter $brandingPublishAdapter,
        private readonly StagingStore $stagingStore,
        private readonly \Gereq\Hook\HookManager $hooks,
        private readonly \Gereq\View\TwigRuntime $twigRuntime,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->settings();
        $this->publishables();
        $this->validation();
        $this->routes();
        $this->menu();
        $this->commands();
        $this->adminSearch();
        $this->dashboard();
        $this->topbarPublishBadge();
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

    private function topbarPublishBadge(): void
    {
        $this->hooks->addFilter('admin.topbar.center', function (string $html): string {
            try {
                $count = $this->stagingStore->count();
            } catch (\Throwable) {
                $count = 0;
            }
            if ($count < 1) {
                return $html;
            }

            $partial = $this->twig->render('admin/partials/publish_badge.html.twig', [
                'pending_count' => $count,
                'pending_url' => $this->router->url('admin.publish.pending'),
                'publish_url' => $this->router->url('admin.publish.run'),
            ]);

            return $html . $partial;
        });
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

    private function routes(): void
    {
        $this->router->get('/', [DashboardController::class, 'home'])->name('home');
        $this->router->get('/design.html', [DesignController::class, 'show'])->name('design');
        $this->router->post('/design.html', [DesignController::class, 'submit'])->name('design.submit');

        $this->router->group('/admin', function (Router $router): void {
            $router->get('/login', [AuthController::class, 'show'])->name('admin.login')->middleware('guest');
            $router->post('/login', [AuthController::class, 'login'])->name('admin.login.submit')->middleware('guest');
            $router->get('/login/mfa', [AuthController::class, 'mfaShow'])->name('admin.login.mfa')->middleware('guest');
            $router->post('/login/mfa', [AuthController::class, 'mfaVerify'])->name('admin.login.mfa.verify')->middleware('guest');
            $router->post('/login/mfa/cancel', [AuthController::class, 'mfaCancel'])->name('admin.login.mfa.cancel')->middleware('guest');
            $router->post('/login/mfa/passkey/options', [AuthController::class, 'mfaPasskeyOptions'])->name('admin.login.mfa.passkey.options')->middleware('guest');
            $router->post('/login/mfa/passkey/verify', [AuthController::class, 'mfaPasskeyVerify'])->name('admin.login.mfa.passkey.verify')->middleware('guest');
            $router->post('/login/passkey/options', [AuthController::class, 'passkeyLoginOptions'])->name('admin.login.passkey.options')->middleware('guest');
            $router->post('/login/passkey/verify', [AuthController::class, 'passkeyLoginVerify'])->name('admin.login.passkey.verify')->middleware('guest');
            $router->post('/logout', [AuthController::class, 'logout'])->name('admin.logout')->middleware('auth');

            $router->get('/setup', [SetupController::class, 'show'])->name('admin.setup')->middleware('auth');
            $router->get('/setup/{step}', [SetupController::class, 'show'])->name('admin.setup.step')->middleware('auth');
            $router->post('/setup/welcome', [SetupController::class, 'saveWelcome'])->name('admin.setup.welcome')->middleware('auth');
            $router->post('/setup/account', [SetupController::class, 'saveAccount'])->name('admin.setup.account')->middleware('auth');
            $router->post('/setup/company', [SetupController::class, 'saveCompany'])->name('admin.setup.company')->middleware('auth');
            $router->post('/setup/shop', [SetupController::class, 'saveShop'])->name('admin.setup.shop')->middleware('auth');
            $router->post('/setup/finish', [SetupController::class, 'finish'])->name('admin.setup.finish')->middleware('auth');

            $router->get('/forgot-password', [AuthController::class, 'forgot'])->name('admin.password.forgot')->middleware('guest');
            $router->post('/forgot-password', [AuthController::class, 'sendReset'])->name('admin.password.email')->middleware('guest');
            $router->get('/reset-password/{token}', [AuthController::class, 'reset'])->name('admin.password.reset')->middleware('guest');
            $router->post('/reset-password/{token}', [AuthController::class, 'updatePassword'])->name('admin.password.update')->middleware('guest');

            $router->get('', [DashboardController::class, 'index'])->name('admin.dashboard')->middleware('auth');

            $router->get('/users', [UserController::class, 'index'])->name('admin.users.index')->middleware('auth')->permission('users.view');
            $router->get('/users/create', [UserController::class, 'create'])->name('admin.users.create')->middleware('auth')->permission('users.create');
            $router->post('/users', [UserController::class, 'store'])->name('admin.users.store')->middleware('auth')->permission('users.create');
            $router->get('/users/roles', [RoleController::class, 'index'])->name('admin.users.roles')->middleware('auth')->permission('roles.view');
            $router->get('/users/{id}/edit', [UserController::class, 'edit'])->name('admin.users.edit')->middleware('auth')->permission('users.edit');
            $router->post('/users/{id}', [UserController::class, 'update'])->name('admin.users.update')->middleware('auth')->permission('users.edit');
            $router->get('/users/{id}/avatar', [UserController::class, 'avatar'])->name('admin.users.avatar')->middleware('auth')->permission('users.view');
            $router->get('/users/{id}/banner', [UserController::class, 'banner'])->name('admin.users.banner')->middleware('auth')->permission('users.view');
            $router->post('/users/{id}/delete', [UserController::class, 'destroy'])->name('admin.users.destroy')->middleware('auth')->permission('users.delete');
            $router->post('/users/{id}/mfa/reset', [UserController::class, 'resetMfa'])->name('admin.users.mfa.reset')->middleware('auth')->permission('users.edit');

            $router->get('/roles', [RoleController::class, 'index'])->name('admin.roles.index')->middleware('auth')->permission('roles.view');
            $router->get('/roles/create', [RoleController::class, 'create'])->name('admin.roles.create')->middleware('auth')->permission('roles.manage');
            $router->post('/roles', [RoleController::class, 'store'])->name('admin.roles.store')->middleware('auth')->permission('roles.manage');
            $router->post('/roles/bulk', [RoleController::class, 'bulk'])->name('admin.roles.bulk')->middleware('auth')->permission('roles.manage');
            $router->get('/roles/{id}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit')->middleware('auth')->permission('roles.manage');
            $router->post('/roles/{id}', [RoleController::class, 'update'])->name('admin.roles.update')->middleware('auth')->permission('roles.manage');
            $router->post('/roles/{id}/delete', [RoleController::class, 'destroy'])->name('admin.roles.destroy')->middleware('auth')->permission('roles.manage');

            $router->get('/modules', [ModuleController::class, 'index'])->name('admin.modules.index')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/bulk', [ModuleController::class, 'bulk'])->name('admin.modules.bulk')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/groups/{group}/enable', [ModuleController::class, 'enableGroup'])->name('admin.modules.group.enable')->middleware('auth')->permission('modules.manage');
            $router->get('/modules/{id}', [ModuleController::class, 'show'])->name('admin.modules.show')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/versions/refresh', [ModuleController::class, 'fetchVersions'])->name('admin.modules.versions.refresh')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/versions/{version}/fetch', [ModuleController::class, 'fetchVersion'])->name('admin.modules.versions.fetch')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/versions/{version}/activate', [ModuleController::class, 'activateVersion'])->name('admin.modules.versions.activate')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/enable', [ModuleController::class, 'enable'])->name('admin.modules.enable')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/pause', [ModuleController::class, 'pause'])->name('admin.modules.pause')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/disable', [ModuleController::class, 'disable'])->name('admin.modules.disable')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/update', [ModuleController::class, 'update'])->name('admin.modules.update')->middleware('auth')->permission('modules.manage');

            $router->get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index')->middleware('auth')->permission('settings.manage');
            $router->post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update')->middleware('auth')->permission('settings.manage');
            $router->get('/publish/pending', [PublishController::class, 'pending'])->name('admin.publish.pending')->middleware('auth')->permission('settings.manage');
            $router->post('/publish', [PublishController::class, 'publish'])->name('admin.publish.run')->middleware('auth')->permission('settings.manage');
            $router->get('/publish/releases', [PublishController::class, 'releases'])->name('admin.publish.releases')->middleware('auth')->permission('settings.manage');
            $router->get('/settings/audit', [AuditController::class, 'index'])->name('admin.audit.index')->middleware('auth')->permission('audit.view');
            $router->get('/audit', [AuditController::class, 'index'])->middleware('auth')->permission('audit.view');
            $router->get('/profile', [ProfileController::class, 'edit'])->name('admin.profile')->middleware('auth');
            $router->post('/profile', [ProfileController::class, 'update'])->name('admin.profile.update')->middleware('auth');
            $router->post('/profile/mfa/totp/start', [SecurityController::class, 'totpStart'])->name('admin.profile.mfa.totp.start')->middleware('auth');
            $router->post('/profile/mfa/totp/confirm', [SecurityController::class, 'totpConfirm'])->name('admin.profile.mfa.totp.confirm')->middleware('auth');
            $router->post('/profile/mfa/totp/disable', [SecurityController::class, 'totpDisable'])->name('admin.profile.mfa.totp.disable')->middleware('auth');
            $router->post('/profile/mfa/recovery', [SecurityController::class, 'recoveryRegenerate'])->name('admin.profile.mfa.recovery')->middleware('auth');
            $router->post('/profile/passkeys/options', [SecurityController::class, 'passkeyOptions'])->name('admin.profile.passkeys.options')->middleware('auth');
            $router->post('/profile/passkeys/register', [SecurityController::class, 'passkeyRegister'])->name('admin.profile.passkeys.register')->middleware('auth');
            $router->post('/profile/passkeys/{id}/delete', [SecurityController::class, 'passkeyDelete'])->name('admin.profile.passkeys.delete')->middleware('auth');

            $router->get('/developer/api', [DeveloperController::class, 'api'])->name('admin.developer.api')->middleware('auth')->permission('developer.api.view');
            $router->post('/developer/api/tester-token', [DeveloperController::class, 'testerToken'])->name('admin.developer.api.tester_token')->middleware('auth')->permission('developer.api.view');
            $router->get('/developer/events', [DeveloperController::class, 'events'])->name('admin.developer.events')->middleware('auth')->permission('developer.events.view');
            $router->get('/developer/tokens', [ApiTokenController::class, 'index'])->name('admin.developer.tokens.index')->middleware('auth')->permission('developer.tokens.manage');
            $router->get('/developer/tokens/create', [ApiTokenController::class, 'create'])->name('admin.developer.tokens.create')->middleware('auth')->permission('developer.tokens.manage');
            $router->post('/developer/tokens', [ApiTokenController::class, 'store'])->name('admin.developer.tokens.store')->middleware('auth')->permission('developer.tokens.manage');
            $router->post('/developer/tokens/{id}/revoke', [ApiTokenController::class, 'revoke'])->name('admin.developer.tokens.revoke')->middleware('auth')->permission('developer.tokens.manage');

            $router->get('/ares/subjects', [AresController::class, 'subjects'])->name('admin.ares.subjects')->middleware('auth');
            $router->get('/ares/addresses', [AresController::class, 'addresses'])->name('admin.ares.addresses')->middleware('auth');
            $router->get('/search', SearchController::class)->name('admin.search')->middleware('auth');
        });

        $this->router->get('/api/v1/health', [HealthController::class, 'show'])->name('api.health');
        $this->router->get('/api/openapi.json', [OpenApiController::class, 'show'])->name('api.openapi');

        $this->router->add('/api/public/ares/addresses', ['OPTIONS'], [PublicAresController::class, 'options'])
            ->name('api.public.ares.addresses.options');
        $this->router->get('/api/public/ares/addresses', [PublicAresController::class, 'addresses'])
            ->name('api.public.ares.addresses');

        $this->router->add('/api/public/branding', ['OPTIONS'], [PublicBrandingController::class, 'options'])
            ->name('api.public.branding.options');
        $this->router->get('/api/public/branding', [PublicBrandingController::class, 'show'])
            ->name('api.public.branding');

        $this->router->get('/brand/asset/{name}', [BrandAssetController::class, 'show'])
            ->name('brand.asset');
    }

    private function menu(): void
    {
        $this->menu->add(new MenuItem(id: 'dashboard', label: 'nav.overview', icon: 'home', route: 'admin.dashboard', order: 10, group: 'Hlavní'));
        $this->menu->add(new MenuItem(id: 'developer', label: 'nav.developer', icon: 'key', route: 'admin.developer.api', permission: 'developer.api.view', order: 110, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'developer.api', label: 'nav.api', route: 'admin.developer.api', permission: 'developer.api.view', parent: 'developer', order: 10, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'developer.tokens', label: 'nav.api_tokens', route: 'admin.developer.tokens.index', permission: 'developer.tokens.manage', parent: 'developer', order: 20, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'developer.events', label: 'nav.events', route: 'admin.developer.events', permission: 'developer.events.view', parent: 'developer', order: 30, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'users', label: 'nav.users', icon: 'manage_accounts', route: 'admin.users.index', permission: 'users.view', order: 80, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'roles', label: 'nav.roles', route: 'admin.users.roles', permission: 'roles.view', parent: 'users', order: 10));
        $this->menu->add(new MenuItem(id: 'modules', label: 'nav.modules', icon: 'modules', route: 'admin.modules.index', permission: 'modules.manage', order: 90, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'settings', label: 'nav.settings', icon: 'settings', route: 'admin.settings.index', permission: 'settings.manage', order: 100, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'audit', label: 'nav.audit', icon: 'activity', route: 'admin.audit.index', permission: 'audit.view', parent: 'settings', order: 10));
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand('go-dashboard', 'palette.go_dashboard', '/admin', 'home', keywords: ['home', 'dashboard']));
        $this->palette->add(new PaletteCommand('go-users', 'palette.go_users', '/admin/users', 'users', 'users.view'));
        $this->palette->add(new PaletteCommand('go-roles', 'palette.go_roles', '/admin/users/roles', 'shield', 'roles.view', keywords: ['role', 'opravneni', 'permissions']));
        $this->palette->add(new PaletteCommand('go-modules', 'palette.go_modules', '/admin/modules', 'modules', 'modules.manage'));
        $this->palette->add(new PaletteCommand('go-settings', 'palette.go_settings', '/admin/settings', 'settings', 'settings.manage', keywords: ['konfigurace', 'settings']));
        $this->palette->add(new PaletteCommand('go-audit', 'palette.go_audit', '/admin/settings/audit', 'activity', 'audit.view', keywords: ['audit', 'log', 'historie']));
        $this->palette->add(new PaletteCommand('go-profile', 'palette.go_profile', '/admin/profile', 'user'));
        $this->palette->add(new PaletteCommand('logout', 'palette.logout', '/admin/logout', 'logout', group: 'Účet'));
    }

    private function adminSearch(): void
    {
        $em = $this->em;
        $this->adminSearch->add(
            'users',
            'user.id, user.name, user.email',
            static function (string $q, int $limit) use ($em): array {
                $qb = $em->getRepository(\Gereq\User\Entity\User::class)
                    ->createQueryBuilder('u')
                    ->orderBy('u.name', 'ASC')
                    ->setMaxResults($limit);
                self_repair_search($qb, 'u', ['id', 'name', 'email'], $q);
                /** @var list<\Gereq\User\Entity\User> $users */
                $users = $qb->getQuery()->getResult();
                $hits = [];
                foreach ($users as $user) {
                    $hits[] = new AdminSearchHit(
                        id: 'user.' . $user->id(),
                        label: $user->name(),
                        url: '/admin/users/' . $user->id() . '/edit',
                        group: 'nav.users',
                        subtitle: $user->email(),
                        icon: 'user',
                        keywords: [$user->id(), $user->email()],
                    );
                }

                return $hits;
            },
            permission: 'users.view',
            group: 'nav.users',
            icon: 'manage_accounts',
        );
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new ModulesStatsWidget($this->twig, $this->modules, $this->translator, $this->router));
        $this->dashboard->addWidget(new UsersStatsWidget($this->twig, $this->em, $this->translator, $this->router));
        $this->dashboard->addWidget(new ActivityWidget($this->twig, $this->em, $this->translator, $this->router, $this->twigRuntime));
        $this->dashboard->addWidget(new ActivityChartWidget($this->twig, $this->em, $this->translator, $this->router));
        $this->dashboard->addWidget(new SettingsShortcutWidget($this->twig, $this->translator, $this->router));
        $this->dashboard->addWidget(new SearchHeroWidget($this->twig, $this->translator));
    }
}
