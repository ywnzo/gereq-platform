<?php

declare(strict_types=1);

namespace Modules\Listings;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Publish\Adapter\BrokerPublishAdapter;
use Gereq\Publish\Adapter\CityPublishAdapter;
use Gereq\Publish\Adapter\ListingPublishAdapter;
use Modules\Listings\Application\BrokersProvider;
use Modules\Listings\Application\CitiesProvider;
use Modules\Listings\Application\ListingsProvider;
use Modules\Listings\Application\NearLocationsProvider;
use Modules\Listings\Http\BrokerController;
use Modules\Listings\Http\CityController;
use Modules\Listings\Http\ListingController;
use Modules\Listings\Http\NearLocationController;
use Modules\Listings\Http\Public\PublicBrokersController;
use Modules\Listings\Http\Public\PublicCitiesController;
use Modules\Listings\Http\Public\PublicListingsController;
use Modules\Listings\Application\Widgets\ListingStatusChartWidget;
use Modules\Listings\Application\Widgets\ListingsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ListingsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'listings';
    }

    public function version(): string
    {
        return '1.2.8';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('listings.view', 'perm.listings.view', 'perm.group.listings');
        $context->permissions()->register('listings.manage', 'perm.listings.manage', 'perm.group.listings');
        $context->capabilities()->register('listings', $this->id(), $context->get(ListingsProvider::class));
        $context->capabilities()->register('brokers', $this->id(), $context->get(BrokersProvider::class));
        $context->capabilities()->register('near_locations', $this->id(), $context->get(NearLocationsProvider::class));
        $context->capabilities()->register('cities', $this->id(), $context->get(CitiesProvider::class));

        $translator = $context->get(TranslatorInterface::class);
        $context->publishables()->register(
            'listings',
            $translator->trans('publish.category.listings'),
            $context->get(ListingPublishAdapter::class),
            $this->id(),
            40,
        );
        $context->publishables()->register(
            'listings.brokers',
            $translator->trans('publish.category.brokers'),
            $context->get(BrokerPublishAdapter::class),
            $this->id(),
            45,
        );
        $context->publishables()->register(
            'listings.cities',
            $translator->trans('publish.category.cities'),
            $context->get(CityPublishAdapter::class),
            $this->id(),
            46,
        );

        $context->router()->group('/admin/listings', function (Router $routes): void {
            $routes->get('', [ListingController::class, 'index'])->name('admin.listings.index')->permission('listings.view');
            $routes->get('/create', [ListingController::class, 'create'])->name('admin.listings.create')->permission('listings.manage');
            $routes->post('', [ListingController::class, 'store'])->name('admin.listings.store')->permission('listings.manage');
            $routes->post('/bulk', [ListingController::class, 'bulk'])->name('admin.listings.bulk')->permission('listings.manage');

            $routes->get('/brokers', [BrokerController::class, 'index'])->name('admin.listings.brokers.index')->permission('listings.view');
            $routes->get('/brokers/create', [BrokerController::class, 'create'])->name('admin.listings.brokers.create')->permission('listings.manage');
            $routes->post('/brokers', [BrokerController::class, 'store'])->name('admin.listings.brokers.store')->permission('listings.manage');
            $routes->get('/brokers/{id}', [BrokerController::class, 'show'])->name('admin.listings.brokers.show')->permission('listings.view');
            $routes->get('/brokers/{id}/edit', [BrokerController::class, 'edit'])->name('admin.listings.brokers.edit')->permission('listings.manage');
            $routes->post('/brokers/{id}', [BrokerController::class, 'update'])->name('admin.listings.brokers.update')->permission('listings.manage');
            $routes->post('/brokers/{id}/toggle-active', [BrokerController::class, 'toggleActive'])->name('admin.listings.brokers.toggle_active')->permission('listings.manage');
            $routes->post('/brokers/{id}/delete', [BrokerController::class, 'destroy'])->name('admin.listings.brokers.destroy')->permission('listings.manage');

            $routes->get('/near-locations', [NearLocationController::class, 'index'])->name('admin.listings.near.index')->permission('listings.view');
            $routes->get('/near-locations/create', [NearLocationController::class, 'create'])->name('admin.listings.near.create')->permission('listings.manage');
            $routes->post('/near-locations', [NearLocationController::class, 'store'])->name('admin.listings.near.store')->permission('listings.manage');
            $routes->get('/near-locations/{id}', [NearLocationController::class, 'show'])->name('admin.listings.near.show')->permission('listings.view');
            $routes->get('/near-locations/{id}/edit', [NearLocationController::class, 'edit'])->name('admin.listings.near.edit')->permission('listings.manage');
            $routes->post('/near-locations/{id}', [NearLocationController::class, 'update'])->name('admin.listings.near.update')->permission('listings.manage');
            $routes->post('/near-locations/{id}/toggle-active', [NearLocationController::class, 'toggleActive'])->name('admin.listings.near.toggle_active')->permission('listings.manage');
            $routes->post('/near-locations/{id}/delete', [NearLocationController::class, 'destroy'])->name('admin.listings.near.destroy')->permission('listings.manage');

            $routes->get('/cities', [CityController::class, 'index'])->name('admin.listings.cities.index')->permission('listings.view');
            $routes->get('/cities/create', [CityController::class, 'create'])->name('admin.listings.cities.create')->permission('listings.manage');
            $routes->post('/cities', [CityController::class, 'store'])->name('admin.listings.cities.store')->permission('listings.manage');
            $routes->get('/cities/{id}', [CityController::class, 'show'])->name('admin.listings.cities.show')->permission('listings.view');
            $routes->get('/cities/{id}/edit', [CityController::class, 'edit'])->name('admin.listings.cities.edit')->permission('listings.manage');
            $routes->post('/cities/{id}', [CityController::class, 'update'])->name('admin.listings.cities.update')->permission('listings.manage');
            $routes->post('/cities/{id}/toggle-active', [CityController::class, 'toggleActive'])->name('admin.listings.cities.toggle_active')->permission('listings.manage');
            $routes->post('/cities/{id}/delete', [CityController::class, 'destroy'])->name('admin.listings.cities.destroy')->permission('listings.manage');

            $routes->get('/{id}', [ListingController::class, 'show'])->name('admin.listings.show')->permission('listings.view');
            $routes->get('/{id}/edit', [ListingController::class, 'edit'])->name('admin.listings.edit')->permission('listings.manage');
            $routes->post('/{id}', [ListingController::class, 'update'])->name('admin.listings.update')->permission('listings.manage');
            $routes->post('/{id}/publish', [ListingController::class, 'togglePublish'])->name('admin.listings.publish')->permission('listings.manage');
            $routes->post('/{id}/feature', [ListingController::class, 'toggleFeature'])->name('admin.listings.feature')->permission('listings.manage');
            $routes->post('/{id}/images', [ListingController::class, 'storeImage'])->name('admin.listings.images.store')->permission('listings.manage');
            $routes->post('/{id}/images/attach', [ListingController::class, 'attachImage'])->name('admin.listings.images.attach')->permission('listings.manage');
            $routes->post('/{id}/images/url', [ListingController::class, 'storeUrlImage'])->name('admin.listings.images.url')->permission('listings.manage');
            $routes->post('/{id}/images/{imageId}/primary', [ListingController::class, 'setPrimaryImage'])->name('admin.listings.images.primary')->permission('listings.manage');
            $routes->post('/{id}/images/{imageId}/move', [ListingController::class, 'moveImage'])->name('admin.listings.images.move')->permission('listings.manage');
            $routes->post('/{id}/images/{imageId}/delete', [ListingController::class, 'destroyImage'])->name('admin.listings.images.destroy')->permission('listings.manage');
            $routes->post('/{id}/near-locations', [ListingController::class, 'attachNearLocation'])->name('admin.listings.near_links.store')->permission('listings.manage');
            $routes->post('/{id}/near-locations/{linkId}', [ListingController::class, 'updateNearLocation'])->name('admin.listings.near_links.update')->permission('listings.manage');
            $routes->post('/{id}/near-locations/{linkId}/delete', [ListingController::class, 'destroyNearLocation'])->name('admin.listings.near_links.destroy')->permission('listings.manage');
            $routes->post('/{id}/delete', [ListingController::class, 'destroy'])->name('admin.listings.destroy')->permission('listings.manage');
        }, ['middleware' => ['auth']]);

        $public = [PublicListingsController::class, 'options'];
        $context->router()->add('/api/public/listings', ['OPTIONS'], $public)->name('api.public.listings.options');
        $context->router()->add('/api/public/listings/{slug}', ['OPTIONS'], $public)->name('api.public.listings.slug.options');
        $context->router()->add('/api/public/inquiries', ['OPTIONS'], $public)->name('api.public.inquiries.options');
        $context->router()->add('/api/public/brokers', ['OPTIONS'], [PublicBrokersController::class, 'options'])->name('api.public.brokers.options');
        $context->router()->add('/api/public/brokers/{id}', ['OPTIONS'], [PublicBrokersController::class, 'options'])->name('api.public.brokers.id.options');
        $context->router()->add('/api/public/cities', ['OPTIONS'], [PublicCitiesController::class, 'options'])->name('api.public.cities.options');

        $context->router()->get('/api/public/listings', [PublicListingsController::class, 'index'])
            ->name('api.public.listings.index');
        $context->router()->get('/api/public/listings/{slug}', [PublicListingsController::class, 'show'])
            ->name('api.public.listings.show');
        $context->router()->post('/api/public/inquiries', [PublicListingsController::class, 'inquire'])
            ->name('api.public.inquiries.store');
        $context->router()->get('/api/public/brokers', [PublicBrokersController::class, 'index'])
            ->name('api.public.brokers.index');
        $context->router()->get('/api/public/brokers/{id}', [PublicBrokersController::class, 'show'])
            ->name('api.public.brokers.show');
        $context->router()->get('/api/public/cities', [PublicCitiesController::class, 'index'])
            ->name('api.public.cities.index');

        $context->platformEvents()->register(
            (new EventDefinition('listing.inquiry', $this->id()))
                ->description('Poptávka z veřejného webu')
                ->webhookEnabled(true)
        );
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ListingsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new ListingStatusChartWidget(
            $context->get(Environment::class),
            $context->get(\Doctrine\ORM\EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings',
            label: 'nav.listings',
            icon: 'home',
            route: 'admin.listings.index',
            permission: 'listings.view',
            order: 28,
            group: 'Reality',
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.properties',
            label: 'nav.listings.properties',
            route: 'admin.listings.index',
            permission: 'listings.view',
            parent: 'listings',
            order: 29,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.brokers',
            label: 'nav.listings.brokers',
            route: 'admin.listings.brokers.index',
            permission: 'listings.view',
            parent: 'listings',
            order: 30,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.near',
            label: 'nav.listings.near',
            route: 'admin.listings.near.index',
            permission: 'listings.view',
            parent: 'listings',
            order: 31,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.cities',
            label: 'nav.listings.cities',
            route: 'admin.listings.cities.index',
            permission: 'listings.view',
            parent: 'listings',
            order: 32,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.create',
            label: 'nav.listings.create',
            route: 'admin.listings.create',
            permission: 'listings.manage',
            parent: 'listings',
            order: 33,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.brokers.create',
            label: 'nav.listings.brokers.create',
            route: 'admin.listings.brokers.create',
            permission: 'listings.manage',
            parent: 'listings',
            order: 34,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.near.create',
            label: 'nav.listings.near.create',
            route: 'admin.listings.near.create',
            permission: 'listings.manage',
            parent: 'listings',
            order: 35,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'listings.cities.create',
            label: 'nav.listings.cities.create',
            route: 'admin.listings.cities.create',
            permission: 'listings.manage',
            parent: 'listings',
            order: 36,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.index',
            label: 'palette.listings.index',
            url: '/admin/listings',
            icon: 'home',
            permission: 'listings.view',
            keywords: ['listing', 'nabídka', 'nemovitost', 'reality'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.create',
            label: 'palette.listings.create',
            url: '/admin/listings/create',
            icon: 'plus',
            permission: 'listings.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.brokers.index',
            label: 'palette.listings.brokers',
            url: '/admin/listings/brokers',
            icon: 'users',
            permission: 'listings.view',
            keywords: ['broker', 'makléř', 'reality', 'agent'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.brokers.create',
            label: 'palette.listings.brokers.create',
            url: '/admin/listings/brokers/create',
            icon: 'plus',
            permission: 'listings.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.near.index',
            label: 'palette.listings.near',
            url: '/admin/listings/near-locations',
            icon: 'building',
            permission: 'listings.view',
            keywords: ['near', 'blízko', 'úřad', 'škola', 'doprava', 'poi'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.near.create',
            label: 'palette.listings.near.create',
            url: '/admin/listings/near-locations/create',
            icon: 'plus',
            permission: 'listings.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.cities.index',
            label: 'palette.listings.cities',
            url: '/admin/listings/cities',
            icon: 'building',
            permission: 'listings.view',
            keywords: ['city', 'město', 'lokalita', 'lokality'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'listings.cities.create',
            label: 'palette.listings.cities.create',
            url: '/admin/listings/cities/create',
            icon: 'plus',
            permission: 'listings.manage',
            group: 'Akce',
        ));

        $this->registerValidation($context);
    }

    private function registerValidation(ModuleContext $context): void
    {
        $validation = $context->validation();

        $validation->ruleset('listings.save', [
            'title' => 'required|min:1|max:190',
            'slug' => 'nullable|max:190',
        ]);
        $context->forms()->entity('listings.save', \Modules\Listings\Domain\Listing::class);

        $validation->ruleset('listings.image.upload', [
            'alt' => 'nullable|max:255',
            'title' => 'nullable|max:190',
        ]);

        $validation->ruleset('listings.image.attach', [
            'media_asset_id' => 'required|min:36|max:36',
            'alt' => 'nullable|max:255',
        ]);

        $validation->ruleset('listings.image.url', [
            'external_url' => 'required|max:500',
            'alt' => 'nullable|max:255',
        ]);

        $validation->ruleset('listings.brokers.save', [
            'first_name' => 'required|min:1|max:120',
            'last_name' => 'required|min:1|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|max:64',
            'position' => 'nullable|max:120',
            'skills' => 'nullable|max:5000',
            'description' => 'nullable|max:20000',
            'photo_url' => 'nullable|max:500',
            'sort_order' => 'nullable',
        ]);
        $context->forms()->entity('listings.brokers.save', \Modules\Listings\Domain\Broker::class);

        $validation->ruleset('listings.near.save', [
            'name' => 'required|min:1|max:190',
            'category' => 'required|max:32',
            'address' => 'nullable|max:255',
            'city' => 'nullable|max:120',
            'description' => 'nullable|max:20000',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'sort_order' => 'nullable',
        ]);
        $context->forms()->entity('listings.near.save', \Modules\Listings\Domain\NearLocation::class);

        $validation->ruleset('listings.cities.save', [
            'name' => 'required|min:1|max:120',
            'image_url' => 'nullable|max:500',
            'media_asset_id' => 'nullable|min:36|max:36',
            'sort_order' => 'nullable',
        ]);
        $context->forms()->entity('listings.cities.save', \Modules\Listings\Domain\City::class);

        $validation->ruleset('listings.near_link.save', [
            'near_location_id' => 'required|min:36|max:36',
            'distance_m' => 'nullable',
            'walk_minutes' => 'nullable',
        ]);

        $validation->ruleset('listings.near_link.update', [
            'distance_m' => 'nullable',
            'walk_minutes' => 'nullable',
        ]);

        $validation->ruleset('listings.bulk', [
            'action' => 'required|in:publish,draft,archive,feature,unfeature,delete',
        ]);
    }
}
