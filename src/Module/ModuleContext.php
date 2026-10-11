<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Resource\ResourceRegistrar;
use Gereq\Admin\Search\AdminSearchRegistry;
use Gereq\Api\ApiRegistry;
use Gereq\Automation\WorkflowVariableRegistry;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\Event\EventRegistry;
use Gereq\Hook\HookManager;
use Gereq\Http\Router;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\Publishing;
use Gereq\Schedule\Schedule;
use Gereq\Setting\Settings;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Psr\Container\ContainerInterface;

final class ModuleContext
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly HookManager $hooks,
        private readonly EventBus $events,
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly CapabilityRegistry $capabilities,
        private readonly PermissionRegistry $permissions,
        private readonly Settings $settings,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $commandPalette,
        private readonly AdminSearchRegistry $adminSearch,
        private readonly ResourceRegistrar $resources,
        private readonly Repository $config,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $eventRegistry,
        private readonly PublishableRegistry $publishables,
        private readonly WorkflowVariableRegistry $workflowVariables,
        private readonly Schedule $schedule,
    ) {
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function hooks(): HookManager
    {
        return $this->hooks;
    }

    public function events(): EventBus
    {
        return $this->events;
    }

    public function platformEvents(): EventRegistry
    {
        return $this->eventRegistry;
    }

    public function workflowVariables(): WorkflowVariableRegistry
    {
        return $this->workflowVariables;
    }

    public function api(): ApiRegistry
    {
        return $this->api;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function menu(): Menu
    {
        return $this->menu;
    }

    public function admin(): self
    {
        return $this;
    }

    public function capabilities(): CapabilityRegistry
    {
        return $this->capabilities;
    }

    public function permissions(): PermissionRegistry
    {
        return $this->permissions;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function dashboard(): Dashboard
    {
        return $this->dashboard;
    }

    public function commandPalette(): CommandPalette
    {
        return $this->commandPalette;
    }

    public function adminSearch(): AdminSearchRegistry
    {
        return $this->adminSearch;
    }

    public function resources(): ResourceRegistrar
    {
        return $this->resources;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function publishables(): PublishableRegistry
    {
        return $this->publishables;
    }

    /**
     * Fluent publishable registration bound to a module id.
     * Prefer this over publishables()->register() in new code.
     */
    public function publishing(string $moduleId): Publishing
    {
        return new Publishing(
            $this->publishables,
            $this->container,
            $this->container->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $moduleId,
        );
    }

    public function schedule(): Schedule
    {
        return $this->schedule;
    }

    public function validation(): ValidationRegistry
    {
        return $this->container->get(ValidationRegistry::class);
    }

    public function forms(): \Gereq\Admin\Form\Forms
    {
        return $this->container->get(\Gereq\Admin\Form\Forms::class);
    }
}
