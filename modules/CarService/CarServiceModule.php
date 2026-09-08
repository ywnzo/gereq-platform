<?php

declare(strict_types=1);

namespace Modules\CarService;

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
use Gereq\Setting\SettingRecord;
use Modules\CarService\Application\BranchContext;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Application\LaborRateCatalog;
use Modules\CarService\Application\PdfTemplateCatalog;
use Modules\CarService\Application\Widgets\CarServiceShortcutWidget;
use Modules\CarService\Http\Api\ServiceOrderApiController;
use Modules\CarService\Http\Api\TrackerApiController;
use Modules\CarService\Http\BranchController;
use Modules\CarService\Http\CostController;
use Modules\CarService\Http\ServiceOrderController;
use Modules\CarService\Http\SettingsController;
use Modules\CarService\Http\TrackerController;
use Modules\Customers\Domain\Customer;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Domain\Vehicle;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CarServiceModule implements ModuleInterface
{
    public function id(): string
    {
        return 'carservice';
    }

    public function version(): string
    {
        return '1.13.5';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('carservice.view', 'perm.carservice.view', 'perm.group.carservice');
        $context->permissions()->register('carservice.manage', 'perm.carservice.manage', 'perm.group.carservice');
        $context->permissions()->register('carservice.tracker', 'perm.carservice.tracker', 'perm.group.carservice');

        $provider = new CarServiceProvider(
            $context->get(EntityManagerInterface::class),
            $context->settings(),
            $context->get(FleetProvider::class),
        );
        $context->capabilities()->register('carservice', $this->id(), $provider);
        $context->capabilities()->register(
            'carservice.branch_context',
            $this->id(),
            $context->get(BranchContext::class),
        );

        $context->settings()->define('carservice.job_soft_limit_hours', SettingRecord::TYPE_STRING, 'carservice.settings.job_soft_limit', '8', $this->id(), [], false);
        $context->settings()->define('carservice.default_team', SettingRecord::TYPE_STRING, 'carservice.settings.default_team', 'body_shop', $this->id(), [], false);
        $context->settings()->define('carservice.media_phases', SettingRecord::TYPE_STRING, 'carservice.settings.media_phases', 'before,progress,paint,after', $this->id(), [], false);
        $context->settings()->define('carservice.margin_percent', SettingRecord::TYPE_STRING, 'carservice.settings.margin_percent', '30', $this->id(), [], false);
        foreach (LaborRateCatalog::definitions() as $rate) {
            $context->settings()->define(
                $rate['setting'],
                SettingRecord::TYPE_STRING,
                $rate['label'],
                $rate['default'],
                $this->id(),
                [],
                false,
            );
        }
        $context->settings()->define('carservice.auto_idoklad', SettingRecord::TYPE_BOOLEAN, 'carservice.settings.auto_idoklad', false, $this->id(), [], false);
        $context->settings()->define(
            'carservice.market_sources',
            SettingRecord::TYPE_STRING,
            'carservice.settings.market_sources',
            'heureka,seznam,hledejceny,jama,autodilypro,autokelly',
            $this->id(),
            [],
            false,
        );

        $context->router()->group('/admin/carservice', function (Router $routes): void {
            $routes->get('', [ServiceOrderController::class, 'index'])->name('admin.carservice.index')->permission('carservice.view');
            $routes->get('/create', [ServiceOrderController::class, 'create'])->name('admin.carservice.create')->permission('carservice.manage');
            $routes->post('', [ServiceOrderController::class, 'store'])->name('admin.carservice.store')->permission('carservice.manage');
            $routes->post('/bulk', [ServiceOrderController::class, 'bulk'])->name('admin.carservice.bulk')->permission('carservice.manage');
            $routes->get('/costs', [CostController::class, 'index'])->name('admin.carservice.costs')->permission('carservice.view');
            $routes->post('/costs', [CostController::class, 'store'])->name('admin.carservice.costs.store')->permission('carservice.manage');
            $routes->post('/costs/{id}/delete', [CostController::class, 'destroy'])->name('admin.carservice.costs.destroy')->permission('carservice.manage');
            $routes->get('/branches', [BranchController::class, 'index'])->name('admin.carservice.branches')->permission('carservice.view');
            $routes->get('/branches/create', [BranchController::class, 'create'])->name('admin.carservice.branches.create')->permission('carservice.manage');
            $routes->post('/branches', [BranchController::class, 'store'])->name('admin.carservice.branches.store')->permission('carservice.manage');
            $routes->get('/branches/{id}/edit', [BranchController::class, 'edit'])->name('admin.carservice.branches.edit')->permission('carservice.manage');
            $routes->get('/branches/{id}/logo', [BranchController::class, 'logo'])->name('admin.carservice.branches.logo')->permission('carservice.view');
            $routes->post('/branches/{id}', [BranchController::class, 'update'])->name('admin.carservice.branches.update')->permission('carservice.manage');
            $routes->post('/branches/{id}/delete', [BranchController::class, 'destroy'])->name('admin.carservice.branches.destroy')->permission('carservice.manage');
            $routes->post('/branch-context', [BranchController::class, 'select'])->name('admin.carservice.branch_context')->permission('carservice.view');
            $routes->get('/settings', [SettingsController::class, 'edit'])->name('admin.carservice.settings')->permission('carservice.view');
            $routes->post('/settings', [SettingsController::class, 'update'])->name('admin.carservice.settings.update')->permission('carservice.manage');
            $routes->get('/tracker', [TrackerController::class, 'index'])->name('admin.carservice.tracker')->permission('carservice.tracker');
            $routes->post('/tracker/resolve', [TrackerController::class, 'resolve'])->name('admin.carservice.tracker.resolve')->permission('carservice.tracker');
            $routes->get('/tracker/{id}', [TrackerController::class, 'show'])->name('admin.carservice.tracker.show')->permission('carservice.tracker');
            $routes->post('/tracker/{id}/job-start', [TrackerController::class, 'startJob'])->name('admin.carservice.tracker.job_start')->permission('carservice.tracker');
            $routes->post('/tracker/{id}/job-stop/{jobId}', [TrackerController::class, 'stopJob'])->name('admin.carservice.tracker.job_stop')->permission('carservice.tracker');
            $routes->post('/tracker/{id}/media', [TrackerController::class, 'uploadMedia'])->name('admin.carservice.tracker.media')->permission('carservice.tracker');
            $routes->get('/{id}', [ServiceOrderController::class, 'show'])->name('admin.carservice.show')->permission('carservice.view');
            $routes->get('/{id}/edit', [ServiceOrderController::class, 'edit'])->name('admin.carservice.edit')->permission('carservice.manage');
            $routes->post('/{id}', [ServiceOrderController::class, 'update'])->name('admin.carservice.update')->permission('carservice.manage');
            $routes->post('/{id}/status', [ServiceOrderController::class, 'updateStatus'])->name('admin.carservice.status')->permission('carservice.manage');
            $routes->post('/{id}/unlock', [ServiceOrderController::class, 'unlock'])->name('admin.carservice.unlock')->permission('carservice.manage');
            $routes->post('/{id}/team', [ServiceOrderController::class, 'switchTeam'])->name('admin.carservice.team')->permission('carservice.manage');
            $routes->post('/{id}/jobs', [ServiceOrderController::class, 'storeJob'])->name('admin.carservice.jobs.store')->permission('carservice.manage');
            $routes->post('/{id}/jobs/{jobId}/complete', [ServiceOrderController::class, 'completeJob'])->name('admin.carservice.jobs.complete')->permission('carservice.manage');
            $routes->post('/{id}/media', [ServiceOrderController::class, 'uploadMedia'])->name('admin.carservice.media')->permission('carservice.manage');
            $routes->post('/{id}/finish', [ServiceOrderController::class, 'finish'])->name('admin.carservice.finish')->permission('carservice.manage');
            $routes->post('/{id}/finish/step', [ServiceOrderController::class, 'finishStep'])->name('admin.carservice.finish.step')->permission('carservice.manage');
            $routes->post('/{id}/documents', [ServiceOrderController::class, 'generateDocument'])->name('admin.carservice.documents.generate')->permission('carservice.manage');
            $routes->post('/{id}/intake-protocol', [ServiceOrderController::class, 'updateIntakeProtocol'])->name('admin.carservice.intake_protocol')->permission('carservice.manage');
            $routes->post('/{id}/delete', [ServiceOrderController::class, 'destroy'])->name('admin.carservice.destroy')->permission('carservice.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('carservice.read');
        $context->api()->registerKnownScope('carservice.write');
        $context->api()->registerKnownScope('carservice.tracker');
        $context->api()->module('carservice', function (ApiModule $api): void {
            $api->get('/', [ServiceOrderApiController::class, 'index'])->scope('carservice.read');
            $api->post('/', [ServiceOrderApiController::class, 'store'])->scope('carservice.write');
            $api->get('/{id}', [ServiceOrderApiController::class, 'show'])->scope('carservice.read');
            $api->patch('/{id}', [ServiceOrderApiController::class, 'update'])->scope('carservice.write');
            $api->post('/{id}/team', [ServiceOrderApiController::class, 'switchTeam'])->scope('carservice.write');
            $api->post('/{id}/jobs', [ServiceOrderApiController::class, 'addJob'])->scope('carservice.write');
            $api->post('/{id}/jobs/{jobId}/complete', [ServiceOrderApiController::class, 'completeJob'])->scope('carservice.write');
            $api->post('/{id}/finish', [ServiceOrderApiController::class, 'finish'])->scope('carservice.write');
            $api->post('/tracker/resolve', [TrackerApiController::class, 'resolve'])->scope('carservice.tracker');
            $api->post('/tracker/{id}/jobs', [TrackerApiController::class, 'startJob'])->scope('carservice.tracker');
            $api->post('/tracker/{id}/jobs/{jobId}/complete', [TrackerApiController::class, 'completeJob'])->scope('carservice.tracker');
            $api->post('/tracker/{id}/media', [TrackerApiController::class, 'uploadMedia'])->scope('carservice.tracker');
        });

        foreach ([
            'carservice.order_created' => 'Servisní zakázka vytvořena',
            'carservice.order_updated' => 'Servisní zakázka upravena',
            'carservice.team_switched' => 'Přepnut tým zakázky',
            'carservice.order_finished' => 'Servisní zakázka dokončena',
            'carservice.job_started' => 'Zahájena práce na zakázce',
            'carservice.job_finished' => 'Ukončena práce na zakázce',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true),
            );
        }

        $v = $context->validation();
        $v->ruleset('carservice.save', [
            'vehicle_id' => 'required|max:36',
            'customer_id' => 'nullable|max:36',
            'type' => 'nullable|in:internal_fleet,external_customer,linked',
            'status' => 'nullable|in:intake,in_progress,waiting,ready,done,cancelled',
            'team' => 'nullable|in:body_shop,paint_shop',
            'branch_id' => 'nullable|max:36',
            'note' => 'nullable|max:5000',
            'service_info' => 'nullable|max:5000',
            'payment_type' => 'nullable|in:online,cash',
            'internal_cost' => 'nullable',
            'external_cost' => 'nullable',
            'intake_mileage' => 'nullable|integer',
            'intake_fuel_level' => 'nullable|max:16',
            'intake_at' => 'nullable',
            'handover_at' => 'nullable',
        ]);
        $context->forms()->entity('carservice.save', \Modules\CarService\Domain\ServiceOrder::class);
        $v->ruleset('carservice.finish', [
            'payment_type' => 'nullable|in:online,cash',
            'internal_cost' => 'nullable',
            'external_cost' => 'nullable',
            'service_info' => 'nullable|max:5000',
            'intake_mileage' => 'nullable|integer',
            'intake_fuel_level' => 'nullable|max:16',
            'note' => 'nullable|max:5000',
            'signature' => 'nullable|max:200',
            'signature_service' => 'nullable|max:500',
            'signature_customer' => 'nullable|max:500',
            'sign_mode' => 'nullable|in:manual,digital',
            'signature_provider_png' => 'nullable|max:800000',
            'signature_customer_png' => 'nullable|max:800000',
            'esign_email' => 'nullable|email|max:190',
            'defects' => 'nullable|max:5000',
            'defect_deadline' => 'nullable|max:120',
            'step' => 'required|in:quote,worksheet,cost_sheet,handover,idoklad',
        ]);
        $v->ruleset('carservice.document', [
            'type' => 'required|in:' . implode(',', PdfTemplateCatalog::types()),
            'signature' => 'nullable|max:100',
        ]);
        $v->ruleset('carservice.bulk', [
            'action' => 'required|in:delete',
            'confirmed' => 'nullable',
        ]);
        $v->ruleset('carservice.job', [
            'type' => 'nullable|max:64',
            'note' => 'nullable|max:2000',
            'employee_user_id' => 'nullable|max:36',
        ]);
        $v->ruleset('carservice.cost', [
            'year_month' => 'required|max:7',
            'category' => 'nullable|max:64',
            'amount' => 'required',
            'note' => 'nullable|max:2000',
        ]);
        $v->ruleset('carservice.branch', [
            'name' => 'required|max:120',
            'street' => 'nullable|max:190',
            'city' => 'nullable|max:120',
            'zip' => 'nullable|max:32',
            'country' => 'nullable|max:2',
            'phone' => 'nullable|max:40',
            'email' => 'nullable|max:190',
            'employee_count' => 'nullable|integer',
            'note' => 'nullable|max:5000',
            'active' => 'nullable',
        ]);
        $context->forms()->entity('carservice.branch', \Modules\CarService\Domain\ServiceBranch::class);
        $v->ruleset('carservice.settings', [
            'job_soft_limit_hours' => 'required',
            'default_team' => 'required|in:body_shop,paint_shop',
            'media_phases' => 'required|max:255',
            'margin_percent' => 'required',
            'labor_rate_default' => 'required',
            'labor_rate_body_shop' => 'nullable',
            'labor_rate_paint_shop' => 'nullable',
            'auto_idoklad' => 'nullable',
            'market_sources' => 'nullable',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new CarServiceShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->hooks()->addFilter('admin.topbar.context', function (string $html) use ($context): string {
            $auth = $context->get(\Gereq\Auth\Authenticator::class);
            $user = $auth->user();
            if (!$user instanceof \Gereq\User\Entity\User || !$user->can('carservice.view')) {
                return $html;
            }
            $branchContext = $context->get(BranchContext::class);
            if (!$branchContext->hasBranches()) {
                return $html;
            }
            $request = $context->get(\Symfony\Component\HttpFoundation\RequestStack::class)->getCurrentRequest();
            $redirectUrl = $request instanceof \Symfony\Component\HttpFoundation\Request
                ? $request->getRequestUri()
                : '';
            $fragment = $context->get(Environment::class)->render('@carservice/partials/topbar_branch.html.twig', [
                'branchOptions' => $branchContext->selectOptions(
                    $context->get(TranslatorInterface::class)->trans('carservice.branch_switch.all'),
                ),
                'selectedBranchId' => $branchContext->branchId() ?? '',
                'selectUrl' => $context->router()->url('admin.carservice.branch_context'),
                'redirectUrl' => $redirectUrl,
            ]);

            return $html . $fragment;
        });

        $renderDefaultBranchField = static function (?\Gereq\User\Entity\User $targetUser, string $fieldId) use ($context): string {
            $branchContext = $context->get(BranchContext::class);
            if (!$branchContext->hasBranches()) {
                return '';
            }
            $translator = $context->get(TranslatorInterface::class);

            return $context->get(Environment::class)->render('@carservice/partials/user_default_branch.html.twig', [
                'branchOptions' => $branchContext->selectOptions(
                    $translator->trans('carservice.user.default_branch.none'),
                ),
                'selectedDefaultBranchId' => $targetUser instanceof \Gereq\User\Entity\User
                    ? ($branchContext->defaultBranchId($targetUser) ?? '')
                    : '',
                'fieldId' => $fieldId,
            ]);
        };

        $context->hooks()->addFilter('admin.profile.form_fields', function (string $html) use ($context, $renderDefaultBranchField): string {
            $user = $context->get(\Gereq\Auth\Authenticator::class)->user();
            if (!$user instanceof \Gereq\User\Entity\User || !$user->can('carservice.view')) {
                return $html;
            }

            return $html . $renderDefaultBranchField($user, 'profile-carservice-default-branch');
        });

        $context->hooks()->addAction('admin.profile.saving', function (\Gereq\User\Entity\User $user, \Symfony\Component\HttpFoundation\Request $request) use ($context): void {
            $branchContext = $context->get(BranchContext::class);
            if (!$branchContext->hasBranches() || !$request->request->has('carservice_default_branch_id')) {
                return;
            }
            $branchId = trim((string) $request->request->get('carservice_default_branch_id', ''));
            $branchContext->setDefaultBranchId($user, $branchId !== '' ? $branchId : null);
        });

        $context->hooks()->addFilter('admin.users.form_fields', function (string $html, mixed $item = null) use ($context, $renderDefaultBranchField): string {
            $actor = $context->get(\Gereq\Auth\Authenticator::class)->user();
            if (!$actor instanceof \Gereq\User\Entity\User || !$actor->can('users.edit')) {
                return $html;
            }
            $target = $item instanceof \Gereq\User\Entity\User ? $item : null;

            return $html . $renderDefaultBranchField($target, 'user-carservice-default-branch');
        });

        $context->hooks()->addAction('admin.users.saving', function (\Gereq\User\Entity\User $user, \Symfony\Component\HttpFoundation\Request $request) use ($context): void {
            $branchContext = $context->get(BranchContext::class);
            if (!$branchContext->hasBranches()) {
                return;
            }
            if (!$request->request->has('carservice_default_branch_id')) {
                return;
            }
            $branchId = trim((string) $request->request->get('carservice_default_branch_id', ''));
            $branchContext->setDefaultBranchId($user, $branchId !== '' ? $branchId : null);
        });

        $context->menu()->add(new MenuItem(
            id: 'carservice',
            label: 'nav.carservice',
            icon: 'build',
            route: 'admin.carservice.index',
            permission: 'carservice.view',
            order: 35,
            group: 'Provoz',
        ));
        $context->menu()->add(new MenuItem(
            id: 'carservice.tracker',
            label: 'nav.carservice.tracker',
            route: 'admin.carservice.tracker',
            permission: 'carservice.tracker',
            parent: 'carservice',
            order: 36,
        ));
        $context->menu()->add(new MenuItem(
            id: 'carservice.branches',
            label: 'nav.carservice.branches',
            route: 'admin.carservice.branches',
            permission: 'carservice.view',
            parent: 'carservice',
            order: 37,
        ));
        $context->menu()->add(new MenuItem(
            id: 'carservice.costs',
            label: 'nav.carservice.costs',
            route: 'admin.carservice.costs',
            permission: 'carservice.view',
            parent: 'carservice',
            order: 38,
        ));
        $context->menu()->add(new MenuItem(
            id: 'carservice.settings',
            label: 'nav.carservice.settings',
            route: 'admin.carservice.settings',
            permission: 'carservice.view',
            parent: 'settings',
            order: 20,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'carservice.index',
            label: 'palette.carservice.index',
            url: '/admin/carservice',
            icon: 'build',
            permission: 'carservice.view',
            keywords: ['servis', 'dílna', 'zakázka', 'carservice', 'lakovna'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'carservice.tracker',
            label: 'palette.carservice.tracker',
            url: '/admin/carservice/tracker',
            icon: 'build',
            permission: 'carservice.tracker',
            keywords: ['tracker', 'qr', 'spz', 'dílna'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'carservice.branches',
            label: 'palette.carservice.branches',
            url: '/admin/carservice/branches',
            icon: 'build',
            permission: 'carservice.view',
            keywords: ['pobočka', 'pobočky', 'branch', 'servis'],
        ));

        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $fleet = null;
        if ($context->capabilities()->has('fleet')) {
            $cap = $context->capabilities()->get('fleet');
            $fleet = $cap instanceof FleetProvider ? $cap : null;
        }
        $provider = new CarServiceProvider($em, $context->settings(), $fleet);

        $context->adminSearch()->add(
            'carservice.orders',
            'order.id, order.note, order.service_info, vehicle.plate',
            static function (string $q, int $limit) use ($provider, $fleet): array {
                $hits = [];
                foreach ($provider->searchOrders($q, $limit) as $order) {
                    $vehicleLabel = $order->vehicleId();
                    if ($fleet instanceof FleetProvider) {
                        $v = $fleet->find($order->vehicleId());
                        if ($v !== null) {
                            $vehicleLabel = $v->label();
                        }
                    }
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'carservice.order.' . $order->id(),
                        label: $vehicleLabel,
                        url: '/admin/carservice/' . $order->id(),
                        group: 'carservice.title',
                        subtitle: substr($order->id(), 0, 8) . ' · ' . $order->status(),
                        icon: 'build',
                        keywords: array_values(array_filter([$order->id(), $order->note(), $order->serviceInfo()])),
                    );
                }

                return $hits;
            },
            permission: 'carservice.view',
            group: 'carservice.title',
            icon: 'build',
        );

        $context->adminSearch()->add(
            'carservice.branches',
            'branch.name, branch.city, branch.street',
            static function (string $q, int $limit) use ($em): array {
                $qb = $em->getRepository(\Modules\CarService\Domain\ServiceBranch::class)->createQueryBuilder('b');
                $qb->where('LOWER(b.name) LIKE :q OR LOWER(COALESCE(b.city, \'\')) LIKE :q OR LOWER(COALESCE(b.street, \'\')) LIKE :q')
                    ->setParameter('q', '%' . mb_strtolower($q) . '%')
                    ->orderBy('b.name', 'ASC')
                    ->setMaxResults($limit);
                $hits = [];
                foreach ($qb->getQuery()->getResult() as $branch) {
                    if (!$branch instanceof \Modules\CarService\Domain\ServiceBranch) {
                        continue;
                    }
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'carservice.branch.' . $branch->id(),
                        label: $branch->name(),
                        url: '/admin/carservice/branches/' . $branch->id() . '/edit',
                        group: 'carservice.branches.title',
                        subtitle: $branch->addressLabel() ?: null,
                        icon: 'build',
                        keywords: array_values(array_filter([$branch->name(), $branch->city(), $branch->street()])),
                    );
                }

                return $hits;
            },
            permission: 'carservice.view',
            group: 'carservice.branches.title',
            icon: 'build',
        );

        if (class_exists(Customer::class)) {
            $context->hooks()->addFilter('customers.show.sections', static function (array $sections, Customer $customer) use ($twig, $auth, $provider, $em, $fleet): array {
                if ($auth->user() === null || !$auth->user()->can('carservice.view')) {
                    return $sections;
                }
                $orders = $provider->listForCustomer($customer->id());
                $vehicles = [];
                foreach ($orders as $order) {
                    $vid = $order->vehicleId();
                    if (isset($vehicles[$vid])) {
                        continue;
                    }
                    if ($fleet instanceof FleetProvider) {
                        $v = $fleet->find($vid);
                        if ($v !== null) {
                            $vehicles[$vid] = $v;
                        }
                    } elseif (class_exists(Vehicle::class)) {
                        $v = $em->find(Vehicle::class, $vid);
                        if ($v instanceof Vehicle) {
                            $vehicles[$vid] = $v;
                        }
                    }
                }
                $sections[] = [
                    'id' => 'carservice.orders',
                    'label' => 'carservice.partial.customer_title',
                    'order' => 46,
                    'html' => $twig->render('@carservice/partials/customer_orders.html.twig', [
                        'customer' => $customer,
                        'orders' => $orders,
                        'vehicles' => $vehicles,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            }, HookPriority::NORMAL);
        }

        if (class_exists(Vehicle::class)) {
            $context->hooks()->addFilter('fleet.vehicle.show.sections', static function (array $sections, Vehicle $vehicle) use ($twig, $auth, $provider, $em): array {
                if ($auth->user() === null || !$auth->user()->can('carservice.view')) {
                    return $sections;
                }
                $orders = $provider->listForVehicle($vehicle->id());
                $customers = [];
                if (class_exists(Customer::class)) {
                    foreach ($orders as $order) {
                        $cid = $order->customerId();
                        if ($cid === null || isset($customers[$cid])) {
                            continue;
                        }
                        $c = $em->find(Customer::class, $cid);
                        if ($c instanceof Customer) {
                            $customers[$cid] = $c;
                        }
                    }
                }
                $sections[] = [
                    'id' => 'carservice.orders',
                    'label' => 'carservice.partial.vehicle_title',
                    'order' => 20,
                    'html' => $twig->render('@carservice/partials/vehicle_orders.html.twig', [
                        'vehicle' => $vehicle,
                        'orders' => $orders,
                        'customers' => $customers,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            }, HookPriority::NORMAL);
        }

        // iDoklad faktura se zakládá v finish wizardu — ne automaticky z eventu.
    }
}
