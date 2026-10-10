<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Gereq\Customers\Application\StoreAuthService;
use Gereq\Customers\Http\Api\StoreAuthApiController;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;

/** Storefront auth routes, validation rulesets, and related platform events. */
final class CustomersStoreAuthRegistrar
{
    public function __construct(private readonly string $moduleId = 'customers')
    {
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register('store_auth', $this->moduleId, $context->get(StoreAuthService::class));

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
                (new EventDefinition($type, $this->moduleId))->description($desc)->webhookEnabled(true)
            );
        }
    }
}
