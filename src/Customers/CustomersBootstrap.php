<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Automation\WorkflowVariableDefinition;
use Gereq\Event\EventDefinition;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Publish\Adapter\CustomerPublishAdapter;
use Gereq\Customers\Application\Widgets\CustomerCreateHeroWidget;
use Gereq\Customers\Application\Widgets\CustomerErasureWidget;
use Gereq\Customers\Application\Widgets\CustomerGrowthChartWidget;
use Gereq\Customers\Application\Widgets\CustomerStatsWidget;
use Gereq\Customers\Application\Widgets\CustomersShortcutWidget;
use Gereq\Customers\Application\CustomersProvider;
use Gereq\Customers\Application\CustomerCounters;
use Gereq\Event\EventEnvelope;
use Gereq\Notifications\Application\NotifierBridge;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAddress;
use Gereq\Customers\Domain\CustomerContact;
use Gereq\Customers\Domain\CustomerNote;
use Gereq\Customers\Domain\CustomerRelation;
use Gereq\Customers\Domain\CustomerTagLink;
use Gereq\Customers\Http\Api\CustomerActivityApiController;
use Gereq\Customers\Http\Api\CustomerApiController;
use Gereq\Customers\Http\Api\CustomerTagApiController;
use Gereq\Customers\Http\Api\StoreAuthApiController;
use Gereq\Customers\Http\CustomerController;
use Gereq\Customers\Application\StoreAuthService;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomersBootstrap implements ModuleInterface
{
    public function id(): string
    {
        return 'core';
    }

    public function version(): string
    {
        return '1.99.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('customers.view', 'perm.customers.view', 'perm.group.customers');
        $context->permissions()->register('customers.create', 'perm.customers.create', 'perm.group.customers');
        $context->permissions()->register('customers.edit', 'perm.customers.edit', 'perm.group.customers');
        $context->permissions()->register('customers.delete', 'perm.customers.delete', 'perm.group.customers');

        $context->resources()->register(Customer::class);

        $provider = $context->get(CustomersProvider::class);
        $context->capabilities()->register('contacts', $this->id(), $provider);

        $translator = $context->get(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $context->publishables()->register(
            'customers',
            $translator->trans('publish.category.customers'),
            $context->get(CustomerPublishAdapter::class),
            $this->id(),
            60,
        );

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
            $routes->post('/bulk', [CustomerController::class, 'bulk'])
                ->name('admin.customers.bulk');
            $routes->get('/{id}', [CustomerController::class, 'show'])
                ->name('admin.customers.show')
                ->permission('customers.view');
            $routes->get('/{id}/edit', [CustomerController::class, 'edit'])
                ->name('admin.customers.edit')
                ->permission('customers.edit');
            $routes->get('/{id}/avatar', [CustomerController::class, 'avatar'])
                ->name('admin.customers.avatar')
                ->permission('customers.view');
            $routes->post('/{id}', [CustomerController::class, 'update'])
                ->name('admin.customers.update')
                ->permission('customers.edit');
            $routes->post('/{id}/delete', [CustomerController::class, 'destroy'])
                ->name('admin.customers.destroy')
                ->permission('customers.delete');
            $routes->post('/{id}/addresses', [CustomerController::class, 'storeAddress'])
                ->name('admin.customers.addresses.store')
                ->permission('customers.edit');
            $routes->post('/{id}/addresses/{addressId}/delete', [CustomerController::class, 'destroyAddress'])
                ->name('admin.customers.addresses.destroy')
                ->permission('customers.edit');
            $routes->post('/{id}/contacts', [CustomerController::class, 'storeContact'])
                ->name('admin.customers.contacts.store')
                ->permission('customers.edit');
            $routes->post('/{id}/contacts/{contactId}/delete', [CustomerController::class, 'destroyContact'])
                ->name('admin.customers.contacts.destroy')
                ->permission('customers.edit');
            $routes->post('/{id}/notes', [CustomerController::class, 'storeNote'])
                ->name('admin.customers.notes.store')
                ->permission('customers.edit');
            $routes->post('/{id}/notes/{noteId}/delete', [CustomerController::class, 'destroyNote'])
                ->name('admin.customers.notes.destroy')
                ->permission('customers.edit');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerStorefrontAuth($context);
        $this->registerWorkflowVariables($context);
    }

    private function registerWorkflowVariables(ModuleContext $context): void
    {
        $context->workflowVariables()->registerMany(
            (new WorkflowVariableDefinition('customer.id', $this->id()))
                ->withLabel('ID zákazníka')
                ->withDescription('Unikátní identifikátor zákazníka.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.name', $this->id()))
                ->withLabel('Jméno zákazníka')
                ->withDescription('Zobrazené jméno zákazníka.')
                ->withGroup('customer')
                ->withExample('Jan Novák'),
            (new WorkflowVariableDefinition('customer.email', $this->id()))
                ->withLabel('E-mail zákazníka')
                ->withDescription('Primární e-mailová adresa.')
                ->withGroup('customer')
                ->withExample('jan@firma.cz'),
            (new WorkflowVariableDefinition('customer.phone', $this->id()))
                ->withLabel('Telefon zákazníka')
                ->withDescription('Kontaktní telefon.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.company', $this->id()))
                ->withLabel('Firma')
                ->withDescription('Název firmy zákazníka.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.ico', $this->id()))
                ->withLabel('IČO')
                ->withDescription('Identifikační číslo organizace.')
                ->withGroup('customer'),
        );
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
            $routes->post('/logout', [StoreAuthApiController::class, 'logout'])->name('api.store.auth.logout');
            $routes->get('/me', [StoreAuthApiController::class, 'me'])->name('api.store.auth.me');
            $routes->patch('/me', [StoreAuthApiController::class, 'update'])->name('api.store.auth.me.update');
            $routes->post('/me/erasure', [StoreAuthApiController::class, 'erasure'])->name('api.store.auth.me.erasure');
            $routes->delete('/me', [StoreAuthApiController::class, 'destroy'])->name('api.store.auth.me.destroy');
        });

        $v = $context->validation();
        $v->ruleset('customers.store.register', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:8|max:120',
            'name' => 'nullable|max:180',
            'phone' => 'nullable|phone|max:40',
            'region' => 'nullable|max:80',
            'locality' => 'nullable|max:180',
            'newsletter' => 'nullable|boolean',
            'categories' => 'nullable',
        ]);
        $v->ruleset('customers.store.soft_register', [
            'email' => 'required|email|max:190',
            'name' => 'nullable|max:180',
            'phone' => 'nullable|phone|max:40',
        ]);
        $v->ruleset('customers.store.complete', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:8|max:120',
            'name' => 'nullable|max:180',
            'completion_token' => 'required|string|min:20|max:800',
        ]);
        $v->ruleset('customers.store.login', [
            'email' => 'required|email|max:190',
            'password' => 'required|min:1|max:120',
        ]);
        $v->ruleset('customers.store.update', [
            'name' => 'nullable|max:180',
            'phone' => 'nullable|phone|max:40',
            'current_password' => 'nullable|min:1|max:120',
            'password' => 'nullable|min:8|max:120',
            'region' => 'nullable|max:80',
            'locality' => 'nullable|max:180',
            'newsletter' => 'nullable|boolean',
            'categories' => 'nullable',
        ]);

        foreach ([
            'customer.soft_registered' => 'Soft registrace zákazníka',
            'customer.registered' => 'Registrace zákazníka',
            'customer.registration_completed' => 'Dokončení soft registrace',
            'customer.erasure_requested' => 'Žádost o výmaz osobních údajů',
            'customer.store_deleted' => 'Smazání storefront účtu',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }
    }

    public function boot(ModuleContext $context): void
    {
        $erasureCount = 0;
        try {
            $erasureCount = $context->get(CustomerCounters::class)->erasure();
        } catch (\Throwable) {
        }
        $erasureBadge = $erasureCount > 0 ? (string) $erasureCount : null;
        $context->menu()->add(new MenuItem(
            id: 'customers',
            label: 'nav.customers',
            icon: 'users',
            route: 'admin.customers.index',
            permission: 'customers.view',
            order: 20,
            group: 'Hlavní',
            badge: $erasureBadge,
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
        $context->menu()->add(new MenuItem(
            id: 'customers.erasure',
            label: 'nav.customers.erasure',
            url: '/admin/customers?erasure=1',
            permission: 'customers.view',
            parent: 'customers',
            order: 22,
            badge: $erasureBadge,
        ));

        $twig = $context->get(\Twig\Environment::class);
        $translator = $context->get(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $router = $context->get(\Gereq\Http\Router::class);
        $context->dashboard()->addWidget($context->get(CustomerStatsWidget::class));
        $context->dashboard()->addWidget($context->get(CustomerErasureWidget::class));
        $context->dashboard()->addWidget($context->get(CustomerGrowthChartWidget::class));
        $context->dashboard()->addWidget(new CustomerCreateHeroWidget($twig, $translator, $router));
        $context->dashboard()->addWidget(new CustomersShortcutWidget($twig, $translator, $router));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.index',
            label: 'palette.customers.index',
            url: '/admin/customers',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['kontakty', 'firmy', 'customers'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.erasure',
            label: 'palette.customers.erasure',
            url: '/admin/customers?erasure=1',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['gdpr', 'vymaz', 'smazani', 'erasure', 'soukromi'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.create',
            label: 'palette.customers.create',
            url: '/admin/customers/create',
            icon: 'plus',
            permission: 'customers.create',
            group: 'Akce',
        ));

        $customers = $context->get(CustomersProvider::class);
        $context->adminSearch()->add(
            'customers',
            'customer.id, customer.name, customer.email, customer.company',
            static function (string $q, int $limit) use ($customers): array {
                $hits = [];
                foreach ($customers->search($q, $limit) as $contact) {
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'customer.' . $contact->id,
                        label: $contact->name,
                        url: '/admin/customers/' . $contact->id,
                        group: 'nav.customers',
                        subtitle: $contact->email ?: $contact->company,
                        icon: 'users',
                        keywords: array_values(array_filter([$contact->email, $contact->company])),
                    );
                }

                return $hits;
            },
            permission: 'customers.view',
            group: 'nav.customers',
            icon: 'users',
        );

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

        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (mixed $envelope) use ($context): void {
                if (!$envelope instanceof EventEnvelope || $envelope->type !== 'customer.erasure_requested') {
                    return;
                }
                $id = (string) ($envelope->resource['id'] ?? $envelope->data['customer_id'] ?? '');
                $email = (string) ($envelope->data['email'] ?? '');
                $name = (string) ($envelope->data['name'] ?? '');
                $who = $name !== '' ? $name . ($email !== '' ? ' (' . $email . ')' : '') : $email;
                $translator = $context->get(TranslatorInterface::class);
                $context->get(NotifierBridge::class)->notifyAdmins(
                    'customer.erasure_requested',
                    $translator->trans('customers.erasure.notify.title'),
                    $translator->trans('customers.erasure.notify.body', ['%who%' => $who !== '' ? $who : $id]),
                    [
                        'resource' => $envelope->resource,
                        'event_id' => $envelope->id,
                        'url' => $id !== '' ? '/admin/customers/' . $id : '/admin/customers?erasure=1',
                    ],
                );
            },
            HookPriority::NORMAL,
        );
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->module('customers', function (ApiModule $api): void {
            $api->get('/', [CustomerApiController::class, 'index'])->scope('customers.read')
                ->summary('List customers');
            $api->post('/', [CustomerApiController::class, 'store'])->scope('customers.write')
                ->summary('Create customer')->ruleset('customers.save');
            $api->get('/{id}', [CustomerApiController::class, 'show'])->scope('customers.read')
                ->summary('Get customer');
            $api->patch('/{id}', [CustomerApiController::class, 'update'])->scope('customers.write')
                ->summary('Update customer')->ruleset('customers.save');
            $api->delete('/{id}', [CustomerApiController::class, 'destroy'])->scope('customers.write')
                ->summary('Delete customer');
            $api->post('/{id}/archive', [CustomerApiController::class, 'archive'])->scope('customers.write')
                ->summary('Archive customer');
            $api->post('/{id}/restore', [CustomerApiController::class, 'restore'])->scope('customers.write')
                ->summary('Restore customer');

            $api->get('/{id}/addresses', [CustomerApiController::class, 'addressesIndex'])->scope('customers.read')
                ->summary('List customer addresses');
            $api->post('/{id}/addresses', [CustomerApiController::class, 'addressesStore'])->scope('customers.write')
                ->summary('Create customer address')->ruleset('customers.address.save');
            $api->get('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesShow'])
                ->scope('customers.read')->summary('Get customer address');
            $api->patch('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesUpdate'])
                ->scope('customers.write')->summary('Update customer address')
                ->ruleset('customers.address.save');
            $api->delete('/{id}/addresses/{addressId}', [CustomerApiController::class, 'addressesDestroy'])
                ->scope('customers.write')->summary('Delete customer address');

            $api->get('/{id}/contacts', [CustomerApiController::class, 'contactsIndex'])->scope('customers.read')
                ->summary('List customer contacts');
            $api->post('/{id}/contacts', [CustomerApiController::class, 'contactsStore'])->scope('customers.write')
                ->summary('Create customer contact')->ruleset('customers.contact.save');
            $api->get('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsShow'])
                ->scope('customers.read')->summary('Get customer contact');
            $api->patch('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsUpdate'])
                ->scope('customers.write')->summary('Update customer contact')
                ->ruleset('customers.contact.save');
            $api->delete('/{id}/contacts/{contactId}', [CustomerApiController::class, 'contactsDestroy'])
                ->scope('customers.write')->summary('Delete customer contact');

            $api->get('/{id}/notes', [CustomerApiController::class, 'notesIndex'])->scope('customers.read')
                ->summary('List customer notes');
            $api->post('/{id}/notes', [CustomerApiController::class, 'notesStore'])->scope('customers.write')
                ->summary('Create customer note')->ruleset('customers.note.save');
            $api->get('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesShow'])->scope('customers.read')
                ->summary('Get customer note');
            $api->patch('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesUpdate'])->scope('customers.write')
                ->summary('Update customer note')->ruleset('customers.note.save');
            $api->delete('/{id}/notes/{noteId}', [CustomerApiController::class, 'notesDestroy'])
                ->scope('customers.write')->summary('Delete customer note');

            $api->get('/{id}/relations', [CustomerApiController::class, 'relationsIndex'])->scope('customers.read')
                ->summary('List customer relations');
            $api->post('/{id}/relations', [CustomerApiController::class, 'relationsStore'])->scope('customers.write')
                ->summary('Create customer relation')->ruleset('customers.relation.save');
            $api->delete('/{id}/relations/{relationId}', [CustomerApiController::class, 'relationsDestroy'])
                ->scope('customers.write')->summary('Delete customer relation');

            $api->post('/{id}/tags', [CustomerTagApiController::class, 'attach'])->scope('customers.write')
                ->summary('Attach tag')->ruleset('customers.tag.attach');
            $api->delete('/{id}/tags/{tagId}', [CustomerTagApiController::class, 'detach'])->scope('customers.write')
                ->summary('Detach tag');

            $api->get('/{id}/activity', [CustomerActivityApiController::class, 'index'])->scope('customers.read')
                ->summary('Customer activity feed');
        });

        $context->api()->module('customer-tags', function (ApiModule $api): void {
            $api->get('/', [CustomerTagApiController::class, 'index'])->scope('customers.read')
                ->summary('List customer tags');
            $api->post('/', [CustomerTagApiController::class, 'store'])->scope('customers.write')
                ->summary('Create customer tag')->ruleset('customers.tag.save');
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
        $translator = $context->get(TranslatorInterface::class);
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
            }
        );

        $validation->ruleset('customers.save', [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190|customer_email_available',
            'phone' => 'nullable|phone|max:40',
            'is_company' => 'nullable|boolean',
            'company' => 'nullable|max:190',
            'ico' => 'nullable|max:12',
            'dic' => 'nullable|max:20',
            'company_address' => 'nullable|max:255',
            'note' => 'nullable|max:20000',
            'status' => 'required|in:active,lead,inactive,archived',
        ]);
        $validation->after('customers.save', static function (array $data, array $errors) use ($translator): array {
            $isCompany = in_array($data['is_company'] ?? null, [true, 1, '1', 'on', 'yes', 'true'], true);
            if (!$isCompany) {
                return $errors;
            }
            $ico = preg_replace('/\s+/', '', (string) ($data['ico'] ?? '')) ?? '';
            if ($ico === '') {
                $errors['ico'] = $translator->trans('validation.required');
            } elseif (preg_match('/^\d{8}$/', $ico) !== 1) {
                $errors['ico'] = $translator->trans('customers.validation.ico');
            }
            $company = trim((string) ($data['company'] ?? ''));
            if ($company === '') {
                $errors['company'] = $translator->trans('validation.required');
            }

            return $errors;
        });
        $context->forms()->entity('customers.save', \Gereq\Customers\Domain\Customer::class);

        $validation->ruleset('customers.bulk', [
            'action' => 'required|in:activate,lead,deactivate,archive,delete',
            'confirmed' => 'nullable',
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
            'phone' => 'nullable|phone|max:40',
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
