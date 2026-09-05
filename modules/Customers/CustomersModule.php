<?php

declare(strict_types=1);

namespace Modules\Customers;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Customers\Application\CustomerStatsWidget;
use Modules\Customers\Application\CustomersProvider;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAddress;
use Modules\Customers\Domain\CustomerContact;
use Modules\Customers\Domain\CustomerNote;
use Modules\Customers\Domain\CustomerRelation;
use Modules\Customers\Domain\CustomerTagLink;
use Modules\Customers\Http\Api\CustomerActivityApiController;
use Modules\Customers\Http\Api\CustomerApiController;
use Modules\Customers\Http\Api\CustomerTagApiController;
use Modules\Customers\Http\Api\StoreAuthApiController;
use Modules\Customers\Http\CustomerController;
use Modules\Customers\Application\StoreAuthService;

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
        $context->permissions()->register('customers.view', 'perm.customers.view', 'perm.group.customers');
        $context->permissions()->register('customers.create', 'perm.customers.create', 'perm.group.customers');
        $context->permissions()->register('customers.edit', 'perm.customers.edit', 'perm.group.customers');
        $context->permissions()->register('customers.delete', 'perm.customers.delete', 'perm.group.customers');

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

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerStorefrontAuth($context);
    }

    private function registerStorefrontAuth(ModuleContext $context): void
    {
        $context->capabilities()->register('store_auth', $this->id(), $context->get(StoreAuthService::class));

        // Public storefront endpoints (no admin session / API token required)
        $context->router()->group('/api/v1/store/auth', function (Router $routes): void {
            $routes->post('/register', [StoreAuthApiController::class, 'register'])->name('api.store.auth.register');
            $routes->post('/soft-register', [StoreAuthApiController::class, 'softRegister'])->name('api.store.auth.soft_register');
            $routes->post('/complete', [StoreAuthApiController::class, 'complete'])->name('api.store.auth.complete');
            $routes->post('/login', [StoreAuthApiController::class, 'login'])->name('api.store.auth.login');
            $routes->get('/me', [StoreAuthApiController::class, 'me'])->name('api.store.auth.me');
        });

        $v = $context->validation();
        $v->ruleset('customers.store.register', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:8|max:120',
            'name' => 'nullable|max:180',
            'phone' => 'nullable|max:40',
        ]);
        $v->ruleset('customers.store.soft_register', [
            'email' => 'required|email|max:190',
            'name' => 'nullable|max:180',
            'phone' => 'nullable|max:40',
        ]);
        $v->ruleset('customers.store.complete', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:8|max:120',
            'name' => 'nullable|max:180',
        ]);
        $v->ruleset('customers.store.login', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:1|max:120',
        ]);

        foreach ([
            'customer.soft_registered' => 'Soft registrace zákazníka',
            'customer.registered' => 'Registrace zákazníka',
            'customer.registration_completed' => 'Dokončení soft registrace',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'customers',
            label: 'nav.customers',
            icon: 'users',
            route: 'admin.customers.index',
            permission: 'customers.view',
            order: 20,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'customers.create',
            label: 'nav.customers.create',
            route: 'admin.customers.create',
            permission: 'customers.create',
            parent: 'customers',
            order: 21,
            hidden: true,
        ));

        $context->dashboard()->addWidget(new CustomerStatsWidget(
            $context->get(\Doctrine\ORM\EntityManagerInterface::class),
            $context->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.index',
            label: 'palette.customers.index',
            url: '/admin/customers',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['kontakty', 'firmy', 'customers'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.create',
            label: 'palette.customers.create',
            url: '/admin/customers/create',
            icon: 'plus',
            permission: 'customers.create',
            group: 'Akce',
        ));

        $this->registerValidation($context);
        $this->registerHooks($context);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);

        $context->hooks()->addAction(
            'customers.deleting',
            static function (Customer $customer) use ($em): void {
                $id = $customer->id();
                foreach ($em->getRepository(CustomerAddress::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerContact::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerNote::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerTagLink::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                $relations = $em->getRepository(CustomerRelation::class)->createQueryBuilder('r')
                    ->where('r.fromCustomerId = :id OR r.toCustomerId = :id')
                    ->setParameter('id', $id)
                    ->getQuery()
                    ->getResult();
                foreach ($relations as $row) {
                    $em->remove($row);
                }
            },
            HookPriority::EARLY,
        );
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->module('customers', function (ApiModule $api): void {
            $api->get('/', [CustomerApiController::class, 'index'])->scope('customers.read');
            $api->post('/', [CustomerApiController::class, 'store'])->scope('customers.write');
            $api->get('/{id}', [CustomerApiController::class, 'show'])->scope('customers.read');
            $api->patch('/{id}', [CustomerApiController::class, 'update'])->scope('customers.write');
            $api->delete('/{id}', [CustomerApiController::class, 'destroy'])->scope('customers.write');
            $api->post('/{id}/archive', [CustomerApiController::class, 'archive'])->scope('customers.write');
            $api->post('/{id}/restore', [CustomerApiController::class, 'restore'])->scope('customers.write');

            $api->get('/{id}/addresses', [CustomerApiController::class, 'addressesIndex'])->scope('customers.read');
            $api->post('/{id}/addresses', [CustomerApiController::class, 'addressesStore'])->scope('customers.write');
            $api->get('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesShow'])->scope('customers.read');
            $api->patch('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesUpdate'])->scope('customers.write');
            $api->delete('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesDestroy'])->scope('customers.write');

            $api->get('/{id}/contacts', [CustomerApiController::class, 'contactsIndex'])->scope('customers.read');
            $api->post('/{id}/contacts', [CustomerApiController::class, 'contactsStore'])->scope('customers.write');
            $api->get('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsShow'])->scope('customers.read');
            $api->patch('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsUpdate'])->scope('customers.write');
            $api->delete('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsDestroy'])->scope('customers.write');

            $api->get('/{id}/notes', [CustomerApiController::class, 'notesIndex'])->scope('customers.read');
            $api->post('/{id}/notes', [CustomerApiController::class, 'notesStore'])->scope('customers.write');
            $api->get('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesShow'])->scope('customers.read');
            $api->patch('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesUpdate'])->scope('customers.write');
            $api->delete('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesDestroy'])->scope('customers.write');

            $api->get('/{id}/relations', [CustomerApiController::class, 'relationsIndex'])->scope('customers.read');
            $api->post('/{id}/relations', [CustomerApiController::class, 'relationsStore'])->scope('customers.write');
            $api->delete('/{id}/relations/{relationId}', [CustomerApiController::class, 'relationsDestroy'])->scope('customers.write');

            $api->post('/{id}/tags', [CustomerTagApiController::class, 'attach'])->scope('customers.write');
            $api->delete('/{id}/tags/{tagId}', [CustomerTagApiController::class, 'detach'])->scope('customers.write');

            $api->get('/{id}/activity', [CustomerActivityApiController::class, 'index'])->scope('customers.read');
        });

        $context->api()->module('customer-tags', function (ApiModule $api): void {
            $api->get('/', [CustomerTagApiController::class, 'index'])->scope('customers.read');
            $api->post('/', [CustomerTagApiController::class, 'store'])->scope('customers.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'customer.created' => 'Zákazník vytvořen',
            'customer.updated' => 'Zákazník upraven',
            'customer.deleted' => 'Zákazník smazán',
            'customer.archived' => 'Zákazník archivován',
            'customer.restored' => 'Zákazník obnoven z archivu',
            'customer.address.created' => 'Adresa zákazníka vytvořena',
            'customer.address.updated' => 'Adresa zákazníka upravena',
            'customer.address.deleted' => 'Adresa zákazníka smazána',
            'customer.contact.created' => 'Kontakt zákazníka vytvořen',
            'customer.contact.updated' => 'Kontakt zákazníka upraven',
            'customer.contact.deleted' => 'Kontakt zákazníka smazán',
            'customer.note.created' => 'Poznámka zákazníka vytvořena',
            'customer.note.updated' => 'Poznámka zákazníka upravena',
            'customer.note.deleted' => 'Poznámka zákazníka smazána',
            'customer.tag.attached' => 'Štítek přiřazen zákazníkovi',
            'customer.tag.detached' => 'Štítek odebrán zákazníkovi',
            'customer.relation.created' => 'Vztah mezi zákazníky vytvořen',
            'customer.relation.deleted' => 'Vztah mezi zákazníky smazán',
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
            'status' => 'required|in:active,lead,inactive,archived',
        ]);

        $validation->ruleset('customers.address.save', [
            'type' => 'required|in:billing,shipping,other',
            'line1' => 'required|min:1|max:190',
            'line2' => 'nullable|max:190',
            'city' => 'required|min:1|max:120',
            'zip' => 'required|min:1|max:32',
            'country' => 'required|min:2|max:2',
            'is_primary' => 'nullable|boolean',
        ]);

        $validation->ruleset('customers.contact.save', [
            'name' => 'required|min:2|max:180',
            'email' => 'nullable|email|max:180',
            'phone' => 'nullable|max:40',
            'role' => 'nullable|max:120',
            'is_primary' => 'nullable|boolean',
        ]);

        $validation->ruleset('customers.note.save', [
            'body' => 'required|min:1|max:20000',
        ]);

        $validation->ruleset('customers.tag.save', [
            'name' => 'required|min:1|max:120',
            'slug' => 'nullable|max:120',
            'color' => 'nullable|max:32',
        ]);

        $validation->ruleset('customers.tag.attach', [
            'tag_id' => 'nullable|max:36',
            'slug' => 'nullable|max:120',
        ]);

        $validation->ruleset('customers.relation.save', [
            'to_customer_id' => 'required|max:36',
            'type' => 'required|in:parent,child,partner,other',
        ]);
    }
}
