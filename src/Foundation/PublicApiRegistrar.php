<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use Gereq\Api\HealthController;
use Gereq\Api\OpenApiController;
use Gereq\Api\PublicAresController;
use Gereq\Api\PublicBrandingController;
use Gereq\Api\PublicGereqSecurityStubController;
use Gereq\Http\BrandAssetController;
use Gereq\Http\Router;
use Gereq\Module\ModuleManager;

final class PublicApiRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly ModuleManager $modules,
    ) {
    }

    public function register(): void
    {
        $this->router->get('/api/v1/health', [HealthController::class, 'show'])->name('api.health');
        $this->router->get('/api/openapi.json', [OpenApiController::class, 'show'])->name('api.openapi');

        $this->router->add('/api/public/ares/addresses', ['OPTIONS'], [PublicAresController::class, 'options'])
            ->name('api.public.ares.addresses.options');
        $this->router->get('/api/public/ares/addresses', [PublicAresController::class, 'addresses'])
            ->name('api.public.ares.addresses');

        $this->router->add('/api/public/branding', ['OPTIONS'], [PublicBrandingController::class, 'options'])
            ->name('api.public.branding.options');
        $this->router->get('/api/public/branding', [PublicBrandingController::class, 'show'])
            ->name('api.public.branding');

        // When Gereq is off, still answer the storefront probe with 200 + enabled:false
        // so the browser console does not log a 404 (Lighthouse errors-in-console).
        if (!$this->modules->isEnabled('gereq')) {
            $stub = PublicGereqSecurityStubController::class;
            $this->router->add('/api/public/gereq/security', ['OPTIONS'], [$stub, 'options'])
                ->name('api.public.gereq.security.options');
            $this->router->get('/api/public/gereq/security', [$stub, 'security'])
                ->name('api.public.gereq.security');
        }

        $this->router->get('/brand/asset/{name}', [BrandAssetController::class, 'show'])
            ->name('brand.asset');
        $this->router->get('/brand/admin-favicon', [BrandAssetController::class, 'admin'])
            ->name('brand.admin_favicon');
    }
}
