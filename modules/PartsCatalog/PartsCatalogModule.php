<?php

declare(strict_types=1);

namespace Modules\PartsCatalog;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Auth\Authenticator;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\CarService\Domain\ServiceOrder;
use Modules\PartsCatalog\Application\PartsCatalogFacade;
use Modules\PartsCatalog\Domain\OrderPart;
use Modules\PartsCatalog\Http\CatalogController;
use Twig\Environment;

final class PartsCatalogModule implements ModuleInterface
{
    public function id(): string
    {
        return 'partscatalog';
    }

    public function version(): string
    {
        return '1.13.11';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('partscatalog.view', 'perm.partscatalog.view', 'perm.group.partscatalog');
        $context->permissions()->register('partscatalog.manage', 'perm.partscatalog.manage', 'perm.group.partscatalog');

        $context->capabilities()->register('partscatalog', $this->id(), $context->get(PartsCatalogFacade::class));

        $context->settings()->define('partscatalog.jama.base_url', SettingRecord::TYPE_STRING, 'partscatalog.settings.jama_base_url', 'https://www.autodily-jama.cz', $this->id(), [], false);

        $context->router()->group('/admin/parts-catalog', function (Router $routes): void {
            $routes->get('', [CatalogController::class, 'browse'])->name('admin.partscatalog.browse')->permission('partscatalog.view');
            $routes->get('/settings', [CatalogController::class, 'settings'])->name('admin.partscatalog.settings')->permission('partscatalog.manage');
            $routes->post('/settings', [CatalogController::class, 'updateSettings'])->name('admin.partscatalog.settings.update')->permission('partscatalog.manage');
            $routes->get('/detail', [CatalogController::class, 'detail'])->name('admin.partscatalog.detail')->permission('partscatalog.view');
            $routes->post('/attach', [CatalogController::class, 'attach'])->name('admin.partscatalog.attach')->permission('partscatalog.manage');
            $routes->post('/parts/{id}/delete', [CatalogController::class, 'detach'])->name('admin.partscatalog.detach')->permission('partscatalog.manage');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'partscatalog',
            label: 'nav.partscatalog',
            icon: 'package',
            route: 'admin.partscatalog.browse',
            permission: 'partscatalog.view',
            parent: 'carservice',
            order: 54,
        ));
        $context->menu()->add(new MenuItem(
            id: 'partscatalog.settings',
            label: 'nav.partscatalog.settings',
            icon: 'settings',
            route: 'admin.partscatalog.settings',
            permission: 'partscatalog.manage',
            parent: 'carservice',
            order: 55,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'partscatalog.browse',
            label: 'palette.partscatalog.browse',
            url: '/admin/parts-catalog',
            icon: 'package',
            permission: 'partscatalog.view',
            keywords: ['díly', 'katalog', 'autodoc', 'tecdoc', 'oem', 'náhradní'],
        ));

        $this->registerHooks($context);
    }

    private function registerHooks(ModuleContext $context): void
    {
        if (!class_exists(ServiceOrder::class)) {
            return;
        }

        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $router = $context->get(Router::class);

        $context->hooks()->addFilter(
            'carservice.order.show.sections',
            static function (array $sections, ServiceOrder $order) use ($em, $twig, $auth, $router): array {
                if ($auth->user() === null || !$auth->user()->can('partscatalog.view')) {
                    return $sections;
                }
                $parts = $em->getRepository(OrderPart::class)->findBy(
                    ['orderId' => $order->id()],
                    ['createdAt' => 'DESC'],
                );
                $sections[] = [
                    'id' => 'partscatalog',
                    'label' => 'partscatalog.partial.title',
                    'order' => 55,
                    'html' => $twig->render('@partscatalog/partials/order_parts.html.twig', [
                        'order' => $order,
                        'parts' => $parts,
                        'user' => $auth->user(),
                        'editable' => $order->isOpen(),
                        'browseUrl' => $router->url('admin.partscatalog.browse') . '?order_id=' . rawurlencode($order->id()),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );

        $context->hooks()->addFilter(
            'carservice.idoklad.invoice_items',
            static function (array $items, string $orderId) use ($em): array {
                if ($orderId === '') {
                    return $items;
                }
                /** @var list<OrderPart> $parts */
                $parts = $em->getRepository(OrderPart::class)->findBy(
                    ['orderId' => $orderId],
                    ['createdAt' => 'ASC'],
                );
                foreach ($parts as $part) {
                    if (!$part instanceof OrderPart) {
                        continue;
                    }
                    $name = trim($part->nameCs());
                    if ($part->oem()) {
                        $name .= ' · ' . $part->oem();
                    }
                    if ($part->brand()) {
                        $name .= ' (' . $part->brand() . ')';
                    }
                    $items[] = [
                        'Name' => $name !== '' ? $name : 'Náhradní díl',
                        'Amount' => max(0.01, (float) $part->qty()),
                        'Unit' => 'ks',
                        'UnitPrice' => (float) ($part->unitPrice() ?? 0),
                    ];
                }

                return $items;
            },
            HookPriority::NORMAL,
        );
    }
}
