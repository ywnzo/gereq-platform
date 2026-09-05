<?php
declare(strict_types=1);
namespace Modules\Projects;
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
use Modules\Customers\Domain\Customer;
use Modules\Projects\Application\ProjectsProvider;
use Modules\Projects\Http\Api\ProjectApiController;
use Modules\Projects\Http\ProjectController;
use Modules\Projects\Http\TaskController;
use Twig\Environment;
use Modules\Projects\Application\Widgets\ProjectsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProjectsModule implements ModuleInterface
{
    public function id(): string { return 'projects'; }
    public function version(): string { return '1.0.0'; }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('projects.view', 'perm.projects.view', 'perm.group.projects');
        $context->permissions()->register('projects.manage', 'perm.projects.manage', 'perm.group.projects');
        $context->capabilities()->register('projects', $this->id(), new ProjectsProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/projects', function (Router $routes): void {
            $routes->get('', [ProjectController::class, 'index'])->name('admin.projects.index')->permission('projects.view');
            $routes->get('/create', [ProjectController::class, 'create'])->name('admin.projects.create')->permission('projects.manage');
            $routes->post('', [ProjectController::class, 'store'])->name('admin.projects.store')->permission('projects.manage');
            $routes->get('/{id}', [ProjectController::class, 'show'])->name('admin.projects.show')->permission('projects.view');
            $routes->get('/{id}/edit', [ProjectController::class, 'edit'])->name('admin.projects.edit')->permission('projects.manage');
            $routes->post('/{id}', [ProjectController::class, 'update'])->name('admin.projects.update')->permission('projects.manage');
            $routes->post('/{id}/status', [ProjectController::class, 'updateStatus'])->name('admin.projects.status')->permission('projects.manage');
            $routes->post('/{id}/delete', [ProjectController::class, 'destroy'])->name('admin.projects.destroy')->permission('projects.manage');
            $routes->post('/{id}/tasks', [TaskController::class, 'store'])->name('admin.projects.tasks.store')->permission('projects.manage');
            $routes->post('/{id}/tasks/{taskId}', [TaskController::class, 'update'])->name('admin.projects.tasks.update')->permission('projects.manage');
            $routes->post('/{id}/tasks/{taskId}/delete', [TaskController::class, 'destroy'])->name('admin.projects.tasks.destroy')->permission('projects.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('projects.read');
        $context->api()->registerKnownScope('projects.write');
        $context->api()->module('projects', function (ApiModule $api): void {
            $api->get('/', [ProjectApiController::class, 'index'])->scope('projects.read');
            $api->post('/', [ProjectApiController::class, 'store'])->scope('projects.write');
            $api->get('/{id}', [ProjectApiController::class, 'show'])->scope('projects.read');
            $api->patch('/{id}', [ProjectApiController::class, 'update'])->scope('projects.write');
            $api->delete('/{id}', [ProjectApiController::class, 'destroy'])->scope('projects.write');
            $api->get('/{id}/tasks', [ProjectApiController::class, 'tasksIndex'])->scope('projects.read');
            $api->post('/{id}/tasks', [ProjectApiController::class, 'tasksStore'])->scope('projects.write');
            $api->patch('/{id}/tasks/{taskId}', [ProjectApiController::class, 'tasksUpdate'])->scope('projects.write');
        });

        foreach ([
            'project.created'=>'Projekt vytvořen','project.updated'=>'Projekt upraven','project.deleted'=>'Projekt smazán',
            'project.status_changed'=>'Změněn stav projektu',
            'task.created'=>'Úkol vytvořen','task.updated'=>'Úkol upraven','task.completed'=>'Úkol dokončen','task.assigned'=>'Úkol přiřazen',
        ] as $type=>$desc) {
            $context->platformEvents()->register((new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true));
        }

        $v = $context->validation();
        $v->ruleset('projects.save', [
            'customer_id'=>'required|max:36','name'=>'required|min:2|max:190','status'=>'nullable|in:planning,active,done,cancelled',
            'start_at'=>'nullable','due_at'=>'nullable','owner_user_id'=>'nullable|max:36','deal_id'=>'nullable|max:36',
        ]);
        $v->ruleset('projects.task.save', [
            'title'=>'required|min:1|max:190','status'=>'nullable|in:todo,doing,done','assignee_user_id'=>'nullable|max:36','due_at'=>'nullable',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ProjectsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(id:'projects', label:'nav.projects', icon:'clipboard', route:'admin.projects.index', permission:'projects.view', order:40, group:'Práce'));
        $context->menu()->add(new MenuItem(id:'projects.create', label:'nav.projects.create', route:'admin.projects.create', permission:'projects.manage', parent:'projects', order:41, hidden:true));
        $context->commandPalette()->add(new PaletteCommand(id:'projects.index', label:'palette.projects.index', url:'/admin/projects', icon:'clipboard', permission:'projects.view', keywords:['projekt','project','task']));

        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $provider = new ProjectsProvider($em);
        $context->hooks()->addFilter('customers.show.sections', static function (array $sections, Customer $customer) use ($twig, $auth, $provider): array {
            if ($auth->user() === null || !$auth->user()->can('projects.view')) return $sections;
            $sections[] = [
                'id' => 'projects.list', 'label' => 'projects.title', 'order' => 45,
                'html' => $twig->render('@projects/partials/customer_projects.html.twig', [
                    'customer' => $customer, 'projects' => $provider->forCustomer($customer->id()), 'user' => $auth->user(),
                ]),
            ];
            return $sections;
        }, HookPriority::NORMAL);
    }
}