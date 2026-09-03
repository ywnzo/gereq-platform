<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Http\AuditController;
use Gereq\Admin\Http\AuthController;
use Gereq\Admin\Http\DashboardController;
use Gereq\Admin\Http\ModuleController;
use Gereq\Admin\Http\ProfileController;
use Gereq\Admin\Http\RoleController;
use Gereq\Admin\Http\SettingsController;
use Gereq\Admin\Http\UserController;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\HealthController;
use Gereq\Http\DesignController;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;

final class CoreRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly Settings $settings,
        private readonly CommandPalette $palette,
        private readonly ValidationRegistry $validation,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->settings();
        $this->validation();
        $this->routes();
        $this->menu();
        $this->commands();
    }

    private function permissions(): void
    {
        $items = [
            ['users.view', 'Zobrazit uživatele', 'Uživatelé'],
            ['users.create', 'Vytvořit uživatele', 'Uživatelé'],
            ['users.edit', 'Upravit uživatele', 'Uživatelé'],
            ['users.delete', 'Smazat uživatele', 'Uživatelé'],
            ['users.manage', 'Spravovat uživatele', 'Uživatelé'],
            ['roles.view', 'Zobrazit role', 'Role'],
            ['roles.manage', 'Spravovat role', 'Role'],
            ['modules.manage', 'Spravovat moduly', 'Systém'],
            ['settings.manage', 'Spravovat nastavení', 'Systém'],
            ['audit.view', 'Zobrazit audit log', 'Systém'],
        ];

        foreach ($items as [$name, $label, $group]) {
            $this->permissions->register($name, $label, $group);
        }
    }

    private function settings(): void
    {
        $this->settings->define('app.name', SettingRecord::TYPE_STRING, 'Název aplikace', 'GEREQ Platform');
        $this->settings->define('admin.theme', SettingRecord::TYPE_STRING, 'Motiv', 'system');
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
            'phone' => 'required|regex:/^\+?[0-9 ]{9,16}$/',
            'website' => 'nullable|url',
            'note' => 'required|min:12|max:500',
            'comment' => 'nullable|max:120',
            'role' => 'required|in:admin,editor,viewer',
            'terms' => 'accepted',
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
            $router->post('/logout', [AuthController::class, 'logout'])->name('admin.logout')->middleware('auth');

            $router->get('/forgot-password', [AuthController::class, 'forgot'])->name('admin.password.forgot')->middleware('guest');
            $router->post('/forgot-password', [AuthController::class, 'sendReset'])->name('admin.password.email')->middleware('guest');
            $router->get('/reset-password/{token}', [AuthController::class, 'reset'])->name('admin.password.reset')->middleware('guest');
            $router->post('/reset-password/{token}', [AuthController::class, 'updatePassword'])->name('admin.password.update')->middleware('guest');

            $router->get('', [DashboardController::class, 'index'])->name('admin.dashboard')->middleware('auth');

            $router->get('/users', [UserController::class, 'index'])->name('admin.users.index')->middleware('auth')->permission('users.view');
            $router->get('/users/create', [UserController::class, 'create'])->name('admin.users.create')->middleware('auth')->permission('users.create');
            $router->post('/users', [UserController::class, 'store'])->name('admin.users.store')->middleware('auth')->permission('users.create');
            $router->get('/users/{id}/edit', [UserController::class, 'edit'])->name('admin.users.edit')->middleware('auth')->permission('users.edit');
            $router->post('/users/{id}', [UserController::class, 'update'])->name('admin.users.update')->middleware('auth')->permission('users.edit');
            $router->post('/users/{id}/delete', [UserController::class, 'destroy'])->name('admin.users.destroy')->middleware('auth')->permission('users.delete');

            $router->get('/roles', [RoleController::class, 'index'])->name('admin.roles.index')->middleware('auth')->permission('roles.view');
            $router->get('/roles/{id}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit')->middleware('auth')->permission('roles.manage');
            $router->post('/roles/{id}', [RoleController::class, 'update'])->name('admin.roles.update')->middleware('auth')->permission('roles.manage');

            $router->get('/modules', [ModuleController::class, 'index'])->name('admin.modules.index')->middleware('auth')->permission('modules.manage');
            $router->get('/modules/{id}', [ModuleController::class, 'show'])->name('admin.modules.show')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/enable', [ModuleController::class, 'enable'])->name('admin.modules.enable')->middleware('auth')->permission('modules.manage');
            $router->post('/modules/{id}/disable', [ModuleController::class, 'disable'])->name('admin.modules.disable')->middleware('auth')->permission('modules.manage');

            $router->get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index')->middleware('auth')->permission('settings.manage');
            $router->post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update')->middleware('auth')->permission('settings.manage');

            $router->get('/audit', [AuditController::class, 'index'])->name('admin.audit.index')->middleware('auth')->permission('audit.view');
            $router->get('/profile', [ProfileController::class, 'edit'])->name('admin.profile')->middleware('auth');
            $router->post('/profile', [ProfileController::class, 'update'])->name('admin.profile.update')->middleware('auth');
        });

        $this->router->get('/api/v1/health', [HealthController::class, 'show'])->name('api.health');
    }

    private function menu(): void
    {
        $this->menu->add(new MenuItem(id: 'dashboard', label: 'Přehled', icon: 'home', route: 'admin.dashboard', order: 10, group: 'Hlavní'));
        $this->menu->add(new MenuItem(id: 'users', label: 'Uživatelé', icon: 'users', route: 'admin.users.index', permission: 'users.view', order: 80, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'roles', label: 'Role', icon: 'shield', route: 'admin.roles.index', permission: 'roles.view', order: 81, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'modules', label: 'Moduly', icon: 'modules', route: 'admin.modules.index', permission: 'modules.manage', order: 90, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'audit', label: 'Audit', icon: 'activity', route: 'admin.audit.index', permission: 'audit.view', order: 91, group: 'Systém'));
        $this->menu->add(new MenuItem(id: 'settings', label: 'Nastavení', icon: 'settings', route: 'admin.settings.index', permission: 'settings.manage', order: 100, group: 'Systém'));
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand('go-dashboard', 'Přejít na přehled', '/admin', 'home', keywords: ['home', 'dashboard']));
        $this->palette->add(new PaletteCommand('go-users', 'Přejít na uživatele', '/admin/users', 'users', 'users.view'));
        $this->palette->add(new PaletteCommand('go-modules', 'Otevřít moduly', '/admin/modules', 'modules', 'modules.manage'));
        $this->palette->add(new PaletteCommand('go-settings', 'Otevřít nastavení', '/admin/settings', 'settings', 'settings.manage', keywords: ['konfigurace']));
        $this->palette->add(new PaletteCommand('go-profile', 'Můj profil', '/admin/profile', 'user'));
        $this->palette->add(new PaletteCommand('logout', 'Odhlásit se', '/admin/logout', 'logout', group: 'Účet'));
    }
}
