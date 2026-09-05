<?php

declare(strict_types=1);

namespace Modules\Orders;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Customers\Domain\Customer;
use Modules\Orders\Application\InvoiceIssuingService;
use Modules\Orders\Application\OrdersProvider;
use Modules\Orders\Domain\Order;
use Modules\Orders\Http\Api\InvoiceApiController;
use Modules\Orders\Http\Api\OrderApiController;
use Modules\Orders\Http\InvoiceController;
use Modules\Orders\Http\OrderController;
use Modules\Orders\Http\OrderShipmentController;
use Modules\Orders\Application\OrderMailer;
use Modules\Orders\Application\Widgets\InvoicesShortcutWidget;
use Modules\Orders\Application\Widgets\OrderCreateHeroWidget;
use Modules\Orders\Application\Widgets\OrderRevenueChartWidget;
use Modules\Orders\Application\Widgets\OrderRevenueWidget;
use Modules\Orders\Application\Widgets\OrderStatsWidget;
use Modules\Orders\Application\Widgets\OrderStatusChartWidget;
use Modules\Orders\Application\Widgets\OrdersShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrdersModule implements ModuleInterface
{
    public function id(): string
    {
        return 'orders';
    }

    public function version(): string
    {
        return '1.2.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('orders.view', 'perm.orders.view', 'perm.group.orders');
        $context->permissions()->register('orders.manage', 'perm.orders.manage', 'perm.group.orders');
        $context->permissions()->register('invoices.view', 'perm.invoices.view', 'perm.group.orders');
        $context->permissions()->register('invoices.manage', 'perm.invoices.manage', 'perm.group.orders');

        $context->capabilities()->register('orders', $this->id(), new OrdersProvider($context->get(EntityManagerInterface::class)));

        $context->settings()->define('orders.number_prefix', SettingRecord::TYPE_STRING, 'orders.settings.number_prefix', 'ORD-', $this->id());
        $context->settings()->define('invoices.number_prefix', SettingRecord::TYPE_STRING, 'orders.settings.invoice_prefix', 'INV-', $this->id());
        $context->settings()->define('invoices.seller_name', SettingRecord::TYPE_STRING, 'orders.settings.seller_name', '', $this->id());
        $context->settings()->define('invoices.seller_ico', SettingRecord::TYPE_STRING, 'orders.settings.seller_ico', '', $this->id());
        $context->settings()->define('invoices.seller_dic', SettingRecord::TYPE_STRING, 'orders.settings.seller_dic', '', $this->id());
        $context->settings()->define('invoices.seller_address', SettingRecord::TYPE_STRING, 'orders.settings.seller_address', '', $this->id());
        $context->settings()->define('invoices.seller_email', SettingRecord::TYPE_STRING, 'orders.settings.seller_email', '', $this->id());
        $context->settings()->define('invoices.auto_issue_on_paid', SettingRecord::TYPE_BOOLEAN, 'orders.settings.auto_issue_on_paid', true, $this->id());

        $context->router()->group('/admin/orders', function (Router $routes): void {
            $routes->get('', [OrderController::class, 'index'])->name('admin.orders.index')->permission('orders.view');
            $routes->get('/create', [OrderController::class, 'create'])->name('admin.orders.create')->permission('orders.manage');
            $routes->post('', [OrderController::class, 'store'])->name('admin.orders.store')->permission('orders.manage');
            $routes->post('/bulk', [OrderController::class, 'bulk'])->name('admin.orders.bulk')->permission('orders.manage');
            $routes->get('/{id}', [OrderController::class, 'show'])->name('admin.orders.show')->permission('orders.view');
            $routes->get('/{id}/edit', [OrderController::class, 'edit'])->name('admin.orders.edit')->permission('orders.manage');
            $routes->post('/{id}', [OrderController::class, 'update'])->name('admin.orders.update')->permission('orders.manage');
            $routes->post('/{id}/delete', [OrderController::class, 'destroy'])->name('admin.orders.destroy')->permission('orders.manage');
            $routes->post('/{id}/confirm', [OrderController::class, 'confirm'])->name('admin.orders.confirm')->permission('orders.manage');
            $routes->post('/{id}/fulfill', [OrderController::class, 'fulfill'])->name('admin.orders.fulfill')->permission('orders.manage');
            $routes->post('/{id}/cancel', [OrderController::class, 'cancel'])->name('admin.orders.cancel')->permission('orders.manage');
            $routes->post('/{id}/lines', [OrderController::class, 'addLine'])->name('admin.orders.lines.add')->permission('orders.manage');
            $routes->post('/{id}/shipment/create', [OrderShipmentController::class, 'create'])->name('admin.orders.shipment.create')->permission('orders.manage');
            $routes->post('/{id}/shipment/sync', [OrderShipmentController::class, 'sync'])->name('admin.orders.shipment.sync')->permission('orders.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/invoices', function (Router $routes): void {
            $routes->get('', [InvoiceController::class, 'index'])->name('admin.invoices.index')->permission('invoices.view');
            $routes->get('/{id}', [InvoiceController::class, 'show'])->name('admin.invoices.show')->permission('invoices.view');
            $routes->post('/from-order/{orderId}', [InvoiceController::class, 'createFromOrder'])->name('admin.invoices.from_order')->permission('invoices.manage');
            $routes->post('/{id}/issue', [InvoiceController::class, 'issue'])->name('admin.invoices.issue')->permission('invoices.manage');
            $routes->post('/{id}/payments', [InvoiceController::class, 'recordPayment'])->name('admin.invoices.pay')->permission('invoices.manage');
            $routes->get('/{id}/pdf', [InvoiceController::class, 'pdf'])->name('admin.invoices.pdf')->permission('invoices.view');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(id: 'orders', label: 'nav.orders', icon: 'cart', route: 'admin.orders.index', permission: 'orders.view', order: 30, group: 'Obchod'));
        $context->menu()->add(new MenuItem(id: 'orders.invoices', label: 'nav.invoices', route: 'admin.invoices.index', permission: 'invoices.view', parent: 'orders', order: 31));
        $context->menu()->add(new MenuItem(id: 'orders.create', label: 'nav.orders.create', route: 'admin.orders.create', permission: 'orders.manage', parent: 'orders', order: 32, hidden: true));

        $context->commandPalette()->add(new PaletteCommand(id: 'orders.index', label: 'palette.orders.index', url: '/admin/orders', icon: 'cart', permission: 'orders.view', keywords: ['objednávka', 'order', 'invoice']));
        $context->commandPalette()->add(new PaletteCommand(id: 'invoices.index', label: 'palette.invoices.index', url: '/admin/invoices', icon: 'receipt', permission: 'invoices.view', keywords: ['faktura', 'invoice']));

        $twig = $context->get(Environment::class);
        $em = $context->get(EntityManagerInterface::class);
        $translator = $context->get(TranslatorInterface::class);
        $router = $context->get(Router::class);
        $context->dashboard()->addWidget(new OrderStatsWidget($twig, $em, $translator, $router));
        $context->dashboard()->addWidget(new OrderRevenueWidget($twig, $em, $translator));
        $context->dashboard()->addWidget(new OrderRevenueChartWidget($twig, $em, $translator, $router));
        $context->dashboard()->addWidget(new OrderStatusChartWidget($twig, $em, $translator, $router));
        $context->dashboard()->addWidget(new OrderCreateHeroWidget($twig, $translator, $router));
        $context->dashboard()->addWidget(new OrdersShortcutWidget($twig, $translator, $router));
        $context->dashboard()->addWidget(new InvoicesShortcutWidget($twig, $translator, $router));

        $this->registerHooks($context);
        $this->registerPaidInvoiceHook($context);
        $this->registerOrderMailHooks($context);
    }

    private function registerOrderMailHooks(ModuleContext $context): void
    {
        $mailer = $context->get(OrderMailer::class);
        $em = $context->get(EntityManagerInterface::class);

        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope) use ($mailer, $em): void {
                $map = [
                    'order.confirmed' => 'confirmed',
                    'order.fulfilled' => 'shipped',
                ];
                if (!isset($map[$envelope->type])) {
                    return;
                }
                $orderId = (string) ($envelope->resource['id'] ?? $envelope->data['id'] ?? '');
                if ($orderId === '') {
                    return;
                }
                $order = $em->find(Order::class, $orderId);
                if (!$order instanceof Order) {
                    return;
                }
                $customer = $em->find(Customer::class, $order->customerId());
                if ($customer instanceof Customer) {
                    $mailer->sendStatus($order, $customer, $map[$envelope->type]);
                }
            },
            HookPriority::NORMAL,
        );
    }

    private function registerPaidInvoiceHook(ModuleContext $context): void
    {
        $issuer = $context->get(InvoiceIssuingService::class);
        $em = $context->get(EntityManagerInterface::class);
        $settings = $context->get(\Gereq\Setting\Settings::class);

        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope) use ($issuer, $em, $settings): void {
                if ($envelope->type !== 'payment.intent_paid') {
                    return;
                }
                if (!filter_var($settings->get('invoices.auto_issue_on_paid', true), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }
                $orderId = (string) ($envelope->data['order_id'] ?? '');
                if ($orderId === '') {
                    return;
                }
                $order = $em->find(Order::class, $orderId);
                if ($order instanceof Order) {
                    $issuer->issueForOrder($order, true);
                }
            },
            HookPriority::NORMAL,
        );
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('orders.read');
        $context->api()->registerKnownScope('orders.write');
        $context->api()->registerKnownScope('invoices.read');
        $context->api()->registerKnownScope('invoices.write');

        $context->api()->module('orders', function (ApiModule $api): void {
            $api->get('/', [OrderApiController::class, 'index'])->scope('orders.read');
            $api->post('/', [OrderApiController::class, 'store'])->scope('orders.write');
            $api->get('/{id}', [OrderApiController::class, 'show'])->scope('orders.read');
            $api->patch('/{id}', [OrderApiController::class, 'update'])->scope('orders.write');
            $api->delete('/{id}', [OrderApiController::class, 'destroy'])->scope('orders.write');
            $api->post('/{id}/confirm', [OrderApiController::class, 'confirm'])->scope('orders.write');
            $api->post('/{id}/fulfill', [OrderApiController::class, 'fulfill'])->scope('orders.write');
            $api->post('/{id}/cancel', [OrderApiController::class, 'cancel'])->scope('orders.write');
            $api->post('/{id}/lines', [OrderApiController::class, 'addLine'])->scope('orders.write');
        });

        $context->api()->module('invoices', function (ApiModule $api): void {
            $api->get('/', [InvoiceApiController::class, 'index'])->scope('invoices.read');
            $api->post('/', [InvoiceApiController::class, 'store'])->scope('invoices.write');
            $api->get('/{id}', [InvoiceApiController::class, 'show'])->scope('invoices.read');
            $api->post('/{id}/issue', [InvoiceApiController::class, 'issue'])->scope('invoices.write');
            $api->post('/{id}/payments', [InvoiceApiController::class, 'pay'])->scope('invoices.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'order.created' => 'Objednávka vytvořena',
            'order.updated' => 'Objednávka upravena',
            'order.confirmed' => 'Objednávka potvrzena',
            'order.fulfilled' => 'Objednávka splněna',
            'order.cancelled' => 'Objednávka zrušena',
            'invoice.issued' => 'Faktura vystavena',
            'invoice.paid' => 'Faktura uhrazena',
        ] as $type => $description) {
            $context->platformEvents()->register((new EventDefinition($type, $this->id()))->description($description)->webhookEnabled(true));
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $v = $context->validation();
        $v->ruleset('orders.bulk', [
            'action' => 'required|max:32',
            'confirmed' => 'nullable',
        ]);
        $v->ruleset('orders.save', [
            'customer_id' => 'required|max:36',
            'currency' => 'nullable|max:3',
            'note' => 'nullable|max:20000',
        ]);
        $v->ruleset('orders.line.save', [
            'product_id' => 'nullable|max:36',
            'title' => 'nullable|max:190',
            'qty' => 'required',
            'unit_price' => 'required',
            'tax_rate' => 'nullable',
        ]);
        $v->ruleset('orders.payment.save', [
            'amount' => 'required',
            'method' => 'required|max:64',
            'paid_at' => 'nullable',
        ]);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $provider = new OrdersProvider($em);

        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, Customer $customer) use ($twig, $auth, $provider): array {
                if ($auth->user() === null || !$auth->user()->can('orders.view')) {
                    return $sections;
                }
                $orders = $provider->forCustomer($customer->id());
                $sections[] = [
                    'id' => 'orders.list',
                    'label' => 'orders.title',
                    'order' => 35,
                    'html' => $twig->render('@orders/partials/customer_orders.html.twig', [
                        'customer' => $customer,
                        'orders' => $orders,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );
    }
}
