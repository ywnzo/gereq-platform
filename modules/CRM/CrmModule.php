<?php

declare(strict_types=1);

namespace Modules\CRM;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventDefinition;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;
use Modules\CRM\Http\ActivityController;
use Modules\CRM\Http\Api\ActivityApiController;
use Modules\CRM\Http\Api\DealApiController;
use Modules\CRM\Http\DealController;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerCreated;
use Twig\Environment;
use Modules\CRM\Application\Widgets\CrmPipelineChartWidget;
use Modules\CRM\Application\Widgets\CrmShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CrmModule implements ModuleInterface
{
    public function id(): string
    {
        return 'crm';
    }

    public function version(): string
    {
        return '1.0.5';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('crm.view', 'perm.crm.view', 'perm.group.crm');
        $context->permissions()->register('crm.deals.manage', 'perm.crm.deals.manage', 'perm.group.crm');
        $context->permissions()->register('crm.activities.manage', 'perm.crm.activities.manage', 'perm.group.crm');

        $context->router()->group('/admin/crm/deals', function (Router $routes): void {
            $routes->get('', [DealController::class, 'index'])
                ->name('admin.crm.deals.index')
                ->permission('crm.view');
            $routes->get('/create', [DealController::class, 'create'])
                ->name('admin.crm.deals.create')
                ->permission('crm.deals.manage');
            $routes->post('', [DealController::class, 'store'])
                ->name('admin.crm.deals.store')
                ->permission('crm.deals.manage');
            $routes->get('/{id}', [DealController::class, 'show'])
                ->name('admin.crm.deals.show')
                ->permission('crm.view');
            $routes->get('/{id}/edit', [DealController::class, 'edit'])
                ->name('admin.crm.deals.edit')
                ->permission('crm.deals.manage');
            $routes->post('/{id}', [DealController::class, 'update'])
                ->name('admin.crm.deals.update')
                ->permission('crm.deals.manage');
            $routes->post('/{id}/stage', [DealController::class, 'updateStage'])
                ->name('admin.crm.deals.stage')
                ->permission('crm.deals.manage');
            $routes->post('/{id}/delete', [DealController::class, 'destroy'])
                ->name('admin.crm.deals.destroy')
                ->permission('crm.deals.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/crm/activities', function (Router $routes): void {
            $routes->get('', [ActivityController::class, 'index'])
                ->name('admin.crm.activities.index')
                ->permission('crm.view');
            $routes->get('/create', [ActivityController::class, 'create'])
                ->name('admin.crm.activities.create')
                ->permission('crm.activities.manage');
            $routes->post('', [ActivityController::class, 'store'])
                ->name('admin.crm.activities.store')
                ->permission('crm.activities.manage');
            $routes->get('/{id}/edit', [ActivityController::class, 'edit'])
                ->name('admin.crm.activities.edit')
                ->permission('crm.activities.manage');
            $routes->post('/{id}', [ActivityController::class, 'update'])
                ->name('admin.crm.activities.update')
                ->permission('crm.activities.manage');
            $routes->post('/{id}/complete', [ActivityController::class, 'complete'])
                ->name('admin.crm.activities.complete')
                ->permission('crm.activities.manage');
            $routes->post('/{id}/delete', [ActivityController::class, 'destroy'])
                ->name('admin.crm.activities.destroy')
                ->permission('crm.activities.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new CrmShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new CrmPipelineChartWidget(
            $context->get(Environment::class),
            $context->get(EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'crm',
            label: 'nav.crm',
            icon: 'briefcase',
            route: 'admin.crm.deals.index',
            permission: 'crm.view',
            order: 25,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'crm.deals',
            label: 'nav.crm.deals',
            route: 'admin.crm.deals.index',
            permission: 'crm.view',
            parent: 'crm',
            order: 26,
        ));
        $context->menu()->add(new MenuItem(
            id: 'crm.activities',
            label: 'nav.crm.activities',
            route: 'admin.crm.activities.index',
            permission: 'crm.view',
            parent: 'crm',
            order: 27,
        ));
        $context->menu()->add(new MenuItem(
            id: 'crm.deals.create',
            label: 'nav.crm.deals.create',
            route: 'admin.crm.deals.create',
            permission: 'crm.deals.manage',
            parent: 'crm',
            order: 28,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'crm.activities.create',
            label: 'nav.crm.activities.create',
            route: 'admin.crm.activities.create',
            permission: 'crm.activities.manage',
            parent: 'crm',
            order: 29,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'crm.deals.index',
            label: 'palette.crm.deals',
            url: '/admin/crm/deals',
            icon: 'briefcase',
            permission: 'crm.view',
            keywords: ['crm', 'pipeline', 'deal'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'crm.activities.index',
            label: 'palette.crm.activities',
            url: '/admin/crm/activities',
            icon: 'check',
            permission: 'crm.view',
            keywords: ['crm', 'úkol', 'aktivita', 'task'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'crm.deals.create',
            label: 'palette.crm.deals.create',
            url: '/admin/crm/deals/create',
            icon: 'plus',
            permission: 'crm.deals.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'crm.activities.create',
            label: 'palette.crm.activities.create',
            url: '/admin/crm/activities/create',
            icon: 'plus',
            permission: 'crm.activities.manage',
            group: 'Akce',
        ));

        $this->registerValidation($context);
        $this->registerHooks($context);
        $this->registerEvents($context);
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('crm.deals.read');
        $context->api()->registerKnownScope('crm.deals.write');
        $context->api()->registerKnownScope('crm.activities.read');
        $context->api()->registerKnownScope('crm.activities.write');

        $context->api()->module('crm', function (ApiModule $api): void {
            $api->get('/deals', [DealApiController::class, 'index'])->scope('crm.deals.read');
            $api->post('/deals', [DealApiController::class, 'store'])->scope('crm.deals.write');
            $api->get('/deals/{id}', [DealApiController::class, 'show'])->scope('crm.deals.read');
            $api->patch('/deals/{id}', [DealApiController::class, 'update'])->scope('crm.deals.write');
            $api->delete('/deals/{id}', [DealApiController::class, 'destroy'])->scope('crm.deals.write');
            $api->post('/deals/{id}/stage', [DealApiController::class, 'updateStage'])->scope('crm.deals.write');

            $api->get('/activities', [ActivityApiController::class, 'index'])->scope('crm.activities.read');
            $api->post('/activities', [ActivityApiController::class, 'store'])->scope('crm.activities.write');
            $api->get('/activities/{id}', [ActivityApiController::class, 'show'])->scope('crm.activities.read');
            $api->patch('/activities/{id}', [ActivityApiController::class, 'update'])->scope('crm.activities.write');
            $api->delete('/activities/{id}', [ActivityApiController::class, 'destroy'])->scope('crm.activities.write');
            $api->post('/activities/{id}/complete', [ActivityApiController::class, 'complete'])->scope('crm.activities.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'deal.created' => 'Obchod vytvořen',
            'deal.updated' => 'Obchod upraven',
            'deal.deleted' => 'Obchod smazán',
            'deal.stage_changed' => 'Změněna fáze obchodu',
            'activity.created' => 'Aktivita vytvořena',
            'activity.updated' => 'Aktivita upravena',
            'activity.deleted' => 'Aktivita smazána',
            'activity.completed' => 'Aktivita dokončena',
            'activity.reopened' => 'Aktivita znovu otevřena',
        ];

        foreach ($events as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $validation = $context->validation();

        $validation->ruleset('crm.deal.save', [
            'customer_id' => 'required',
            'title' => 'required|min:2|max:190',
            'amount' => 'nullable|max:40',
            'currency' => 'nullable|max:3',
            'stage' => 'required|in:lead,qualified,proposal,won,lost',
            'expected_close_at' => 'nullable',
            'note' => 'nullable|max:20000',
        ]);
        $context->forms()->entity('crm.deal.save', \Modules\CRM\Domain\Deal::class);

        $validation->ruleset('crm.activity.save', [
            'customer_id' => 'required',
            'deal_id' => 'nullable',
            'type' => 'required|in:call,email,meeting,task,note',
            'subject' => 'required|min:2|max:190',
            'body' => 'nullable|max:20000',
            'due_at' => 'nullable',
        ]);
        $context->forms()->entity('crm.activity.save', \Modules\CRM\Domain\Activity::class);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $router = $context->router();
        $auth = $context->get(Authenticator::class);

        $context->hooks()->addFilter(
            'customers.show.actions',
            static function (array $actions, Customer $customer) use ($router, $auth): array {
                $user = $auth->user();
                if ($user !== null && $user->can('crm.deals.manage')) {
                    $actions[] = [
                        'id' => 'crm.deal.create',
                        'label' => 'crm.deals.new',
                        'url' => $router->url('admin.crm.deals.create', []) . '?customer=' . urlencode($customer->id()),
                        'class' => 'btn--ghost',
                    ];
                }
                if ($user !== null && $user->can('crm.activities.manage')) {
                    $actions[] = [
                        'id' => 'crm.activity.create',
                        'label' => 'crm.activities.new',
                        'url' => $router->url('admin.crm.activities.create', []) . '?customer=' . urlencode($customer->id()),
                        'class' => 'btn--ghost',
                    ];
                }

                return $actions;
            },
            HookPriority::NORMAL,
        );

        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, Customer $customer) use ($em, $twig, $auth): array {
                if ($auth->user() === null || !$auth->user()->can('crm.view')) {
                    return $sections;
                }

                $deals = $em->getRepository(Deal::class)->findBy(
                    ['customerId' => $customer->id()],
                    ['updatedAt' => 'DESC'],
                    10,
                );
                $activities = $em->getRepository(Activity::class)->findBy(
                    ['customerId' => $customer->id()],
                    ['createdAt' => 'DESC'],
                    10,
                );

                $sections[] = [
                    'id' => 'crm.deals',
                    'label' => 'crm.deals.title',
                    'order' => 20,
                    'html' => $twig->render('@crm/partials/customer_deals.html.twig', [
                        'customer' => $customer,
                        'deals' => $deals,
                        'user' => $auth->user(),
                    ]),
                ];
                $sections[] = [
                    'id' => 'crm.activities',
                    'label' => 'crm.activities.title',
                    'order' => 30,
                    'html' => $twig->render('@crm/partials/customer_activities.html.twig', [
                        'customer' => $customer,
                        'activities' => $activities,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );

        $context->hooks()->addAction(
            'customers.deleting',
            static function (Customer $customer) use ($em): void {
                $deals = $em->getRepository(Deal::class)->findBy(['customerId' => $customer->id()]);
                foreach ($deals as $deal) {
                    $em->remove($deal);
                }
                $activities = $em->getRepository(Activity::class)->findBy(['customerId' => $customer->id()]);
                foreach ($activities as $activity) {
                    $em->remove($activity);
                }
            },
            HookPriority::EARLY,
        );
    }

    private function registerEvents(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);

        $context->events()->listen(CustomerCreated::class, static function (CustomerCreated $event) use ($em): void {
            $activity = new Activity($event->customerId, 'Zákazník vytvořen', 'note');
            $activity->setBody('Automaticky založená aktivita při vytvoření zákazníka.');
            $activity->complete();
            $em->persist($activity);
            $em->flush();
        });
    }
}
