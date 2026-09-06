<?php

declare(strict_types=1);

namespace Modules\iDoklad;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Auth\Authenticator;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Customers\Domain\Customer;
use Modules\iDoklad\Application\Widgets\iDokladShortcutWidget;
use Modules\iDoklad\Application\iDokladService;
use Modules\iDoklad\Domain\iDokladLink;
use Modules\iDoklad\Http\iDokladCustomerController;
use Modules\iDoklad\Http\iDokladSettingsController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class iDokladModule implements ModuleInterface
{
    public function id(): string
    {
        return 'idoklad';
    }

    public function version(): string
    {
        return '1.1.8';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('idoklad.view', 'perm.idoklad.view', 'perm.group.idoklad');
        $context->permissions()->register('idoklad.manage', 'perm.idoklad.manage', 'perm.group.idoklad');
        $context->permissions()->register('idoklad.sync', 'perm.idoklad.sync', 'perm.group.idoklad');

        $context->capabilities()->register('idoklad', $this->id(), $context->get(iDokladService::class));

        $context->settings()->define('idoklad.enabled', SettingRecord::TYPE_BOOLEAN, 'idoklad.settings.enabled', false, $this->id());
        $context->settings()->define('idoklad.client_id', SettingRecord::TYPE_STRING, 'idoklad.settings.client_id', '', $this->id());
        $context->settings()->define('idoklad.client_secret', SettingRecord::TYPE_ENCRYPTED, 'idoklad.settings.client_secret', '', $this->id());
        $context->settings()->define('idoklad.lists', SettingRecord::TYPE_JSON, 'idoklad.settings.lists', [], $this->id());

        $context->router()->group('/admin/integrations/idoklad', function (Router $routes): void {
            $routes->get('', [iDokladSettingsController::class, 'edit'])
                ->name('admin.idoklad.settings')
                ->permission('idoklad.view');
            $routes->post('', [iDokladSettingsController::class, 'update'])
                ->name('admin.idoklad.settings.update')
                ->permission('idoklad.manage');
            $routes->post('/sync', [iDokladSettingsController::class, 'sync'])
                ->name('admin.idoklad.sync')
                ->permission('idoklad.sync');

            $routes->post('/customers/{id}/sync', [iDokladCustomerController::class, 'sync'])
                ->name('admin.idoklad.customers.sync')
                ->permission('idoklad.manage');
            $routes->post('/customers/{id}/attach', [iDokladCustomerController::class, 'attach'])
                ->name('admin.idoklad.customers.attach')
                ->permission('idoklad.manage');
            $routes->post('/customers/{id}/unlink', [iDokladCustomerController::class, 'unlink'])
                ->name('admin.idoklad.customers.unlink')
                ->permission('idoklad.manage');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new iDokladShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'idoklad',
            label: 'nav.idoklad',
            icon: 'receipt',
            route: 'admin.idoklad.settings',
            permission: 'idoklad.view',
            order: 78,
            group: 'Integrace',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'idoklad.settings',
            label: 'palette.idoklad.settings',
            url: '/admin/integrations/idoklad',
            icon: 'receipt',
            permission: 'idoklad.view',
            keywords: ['idoklad', 'iDoklad', 'faktura', 'účetnictví', 'invoice'],
        ));

        $this->registerCustomerHooks($context);
    }

    private function registerCustomerHooks(ModuleContext $context): void
    {
        if (!class_exists(Customer::class)) {
            return;
        }

        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $idoklad = $context->get(iDokladService::class);

        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, Customer $customer) use ($twig, $auth, $idoklad): array {
                if ($auth->user() === null || !$auth->user()->can('idoklad.view')) {
                    return $sections;
                }

                $link = $idoklad->findLink('customer', $customer->id(), 'contact');
                $remote = null;
                if ($link !== null && $idoklad->isEnabled()) {
                    $partnerId = (int) $link->iDokladId();
                    if ($partnerId > 0) {
                        try {
                            $remote = $idoklad->getContact($partnerId);
                            if (empty($remote['isSucceed'])) {
                                $remote = null;
                            }
                        } catch (\Throwable) {
                            $remote = null;
                        }
                    }
                }

                $sections[] = [
                    'id' => 'idoklad.partner',
                    'label' => 'idoklad.customer.section_title',
                    'order' => 90,
                    'html' => $twig->render('@idoklad/partials/customer_partner.html.twig', [
                        'customer' => $customer,
                        'user' => $auth->user(),
                        'enabled' => $idoklad->isEnabled(),
                        'link' => $link,
                        'remote' => $remote,
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );

        $context->hooks()->addAction(
            'customers.deleting',
            static function (Customer $customer) use ($em): void {
                $links = $em->getRepository(iDokladLink::class)->findBy([
                    'entityType' => 'customer',
                    'entityId' => $customer->id(),
                ]);
                foreach ($links as $link) {
                    $em->remove($link);
                }
            },
            HookPriority::EARLY,
        );
    }
}
