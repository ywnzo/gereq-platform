<?php

declare(strict_types=1);

namespace Modules\Customers;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Customers\Application\CustomerStatsWidget;
use Modules\Customers\Application\CustomersProvider;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Http\CustomerController;

final class CustomersModule implements ModuleInterface
{
    public function id(): string
    {
        return 'customers';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('customers.view', 'Zobrazit zákazníky', 'Zákazníci');
        $context->permissions()->register('customers.create', 'Vytvořit zákazníka', 'Zákazníci');
        $context->permissions()->register('customers.edit', 'Upravit zákazníka', 'Zákazníci');
        $context->permissions()->register('customers.delete', 'Smazat zákazníka', 'Zákazníci');

        $context->resources()->register(Customer::class);

        $provider = new CustomersProvider($context->get(\Doctrine\ORM\EntityManagerInterface::class));
        $context->capabilities()->register('contacts', $this->id(), $provider);

        $context->router()->group('/admin/customers', function (Router $routes): void {
            $routes->get('', [CustomerController::class, 'index'])
                ->name('admin.customers.index')
                ->permission('customers.view');
            $routes->get('/create', [CustomerController::class, 'create'])
                ->name('admin.customers.create')
                ->permission('customers.create');
            $routes->post('', [CustomerController::class, 'store'])
                ->name('admin.customers.store')
                ->permission('customers.create');
            $routes->get('/{id}', [CustomerController::class, 'show'])
                ->name('admin.customers.show')
                ->permission('customers.view');
            $routes->get('/{id}/edit', [CustomerController::class, 'edit'])
                ->name('admin.customers.edit')
                ->permission('customers.edit');
            $routes->post('/{id}', [CustomerController::class, 'update'])
                ->name('admin.customers.update')
                ->permission('customers.edit');
            $routes->post('/{id}/delete', [CustomerController::class, 'destroy'])
                ->name('admin.customers.destroy')
                ->permission('customers.delete');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'customers',
            label: 'Zákazníci',
            icon: 'users',
            route: 'admin.customers.index',
            permission: 'customers.view',
            order: 20,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'customers.create',
            label: 'Nový zákazník',
            route: 'admin.customers.create',
            permission: 'customers.create',
            parent: 'customers',
            order: 21,
            hidden: true,
        ));

        $context->dashboard()->addWidget(new CustomerStatsWidget(
            $context->get(\Doctrine\ORM\EntityManagerInterface::class)
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.index',
            label: 'Přejít na zákazníky',
            url: '/admin/customers',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['kontakty', 'firmy'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.create',
            label: 'Vytvořit zákazníka',
            url: '/admin/customers/create',
            icon: 'plus',
            permission: 'customers.create',
            group: 'Akce',
        ));

        $this->registerValidation($context);
    }

    private function registerValidation(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $validation = $context->validation();

        $validation->rule(
            'customer_email_available',
            static function (mixed $value, array $params, array $data, array $ctx) use ($em): ?string {
            if (!is_string($value) || $value === '') {
                return null;
            }

            $found = $em->getRepository(Customer::class)->findOneBy(['email' => strtolower(trim($value))]);
            if ($found instanceof Customer && $found->id() !== ($ctx['ignore_id'] ?? null)) {
                return 'Zákazník s tímto e-mailem už existuje.';
            }

            return null;
        });

        $validation->ruleset('customers.save', [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190|customer_email_available',
            'phone' => 'nullable|max:40',
            'company' => 'nullable|max:190',
            'note' => 'nullable|max:20000',
            'status' => 'required|in:active,lead,inactive',
        ]);
    }
}
