<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Gereq\Api\ApiModule;
use Gereq\Automation\WorkflowVariableDefinition;
use Gereq\Customers\Http\Api\CustomerActivityApiController;
use Gereq\Customers\Http\Api\CustomerApiController;
use Gereq\Customers\Http\Api\CustomerTagApiController;
use Gereq\Customers\Http\CustomerController;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;

/** Admin + public API routes and platform event catalog for customers. */
final class CustomersRoutesRegistrar
{
    public function __construct(private readonly string $moduleId = 'customers')
    {
    }

    public function register(ModuleContext $context): void
    {
        $this->adminRoutes($context);
        $this->api($context);
        $this->platformEvents($context);
        $this->workflowVariables($context);
    }

    private function adminRoutes(ModuleContext $context): void
    {
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
    }

    private function api(ModuleContext $context): void
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

    private function platformEvents(ModuleContext $context): void
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
                (new EventDefinition($type, $this->moduleId))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function workflowVariables(ModuleContext $context): void
    {
        $context->workflowVariables()->registerMany(
            (new WorkflowVariableDefinition('customer.id', $this->moduleId))
                ->withLabel('ID zákazníka')
                ->withDescription('Unikátní identifikátor zákazníka.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.name', $this->moduleId))
                ->withLabel('Jméno zákazníka')
                ->withDescription('Zobrazené jméno zákazníka.')
                ->withGroup('customer')
                ->withExample('Jan Novák'),
            (new WorkflowVariableDefinition('customer.email', $this->moduleId))
                ->withLabel('E-mail zákazníka')
                ->withDescription('Primární e-mailová adresa.')
                ->withGroup('customer')
                ->withExample('jan@firma.cz'),
            (new WorkflowVariableDefinition('customer.phone', $this->moduleId))
                ->withLabel('Telefon zákazníka')
                ->withDescription('Kontaktní telefon.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.company', $this->moduleId))
                ->withLabel('Firma')
                ->withDescription('Název firmy zákazníka.')
                ->withGroup('customer'),
            (new WorkflowVariableDefinition('customer.ico', $this->moduleId))
                ->withLabel('IČO')
                ->withDescription('Identifikační číslo organizace.')
                ->withGroup('customer'),
        );
    }
}
