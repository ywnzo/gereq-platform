<?php

declare(strict_types=1);

namespace Modules\Engage;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Engage\Application\EngageProvider;
use Modules\Engage\Application\Widgets\EngageShortcutWidget;
use Modules\Engage\Http\Api\EngageApiController;
use Modules\Engage\Http\CampaignController;
use Modules\Engage\Http\HubController;
use Modules\Engage\Http\Public\PublicEngageController;
use Modules\Engage\Http\SpotController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EngageModule implements ModuleInterface
{
    public function id(): string
    {
        return 'engage';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('engage.view', 'perm.engage.view', 'perm.group.engage');
        $context->permissions()->register('engage.manage', 'perm.engage.manage', 'perm.group.engage');

        $provider = $context->get(EngageProvider::class);
        $context->capabilities()->register('engage', $this->id(), $provider);

        $context->router()->group('/admin/engage', function (Router $routes): void {
            $routes->get('', [HubController::class, 'index'])->name('admin.engage.hub')->permission('engage.view');

            $routes->get('/campaigns', [CampaignController::class, 'index'])->name('admin.engage.campaigns.index')->permission('engage.view');
            $routes->get('/campaigns/create', [CampaignController::class, 'create'])->name('admin.engage.campaigns.create')->permission('engage.manage');
            $routes->post('/campaigns', [CampaignController::class, 'store'])->name('admin.engage.campaigns.store')->permission('engage.manage');
            $routes->get('/campaigns/{id}/edit', [CampaignController::class, 'edit'])->name('admin.engage.campaigns.edit')->permission('engage.manage');
            $routes->post('/campaigns/{id}', [CampaignController::class, 'update'])->name('admin.engage.campaigns.update')->permission('engage.manage');
            $routes->post('/campaigns/{id}/delete', [CampaignController::class, 'destroy'])->name('admin.engage.campaigns.destroy')->permission('engage.manage');

            $routes->get('/spots', [SpotController::class, 'index'])->name('admin.engage.spots.index')->permission('engage.view');
            $routes->get('/spots/create', [SpotController::class, 'create'])->name('admin.engage.spots.create')->permission('engage.manage');
            $routes->post('/spots', [SpotController::class, 'store'])->name('admin.engage.spots.store')->permission('engage.manage');
            $routes->get('/spots/{id}/edit', [SpotController::class, 'edit'])->name('admin.engage.spots.edit')->permission('engage.manage');
            $routes->post('/spots/{id}', [SpotController::class, 'update'])->name('admin.engage.spots.update')->permission('engage.manage');
            $routes->post('/spots/{id}/delete', [SpotController::class, 'destroy'])->name('admin.engage.spots.destroy')->permission('engage.manage');
        }, ['middleware' => ['auth'], 'module' => 'engage']);

        $context->api()->registerKnownScope('engage.read');
        $context->api()->registerKnownScope('engage.write');
        $context->api()->module('engage', function (ApiModule $api): void {
            $api->get('/campaigns', [EngageApiController::class, 'campaigns'])->scope('engage.read');
            $api->get('/campaigns/{slug}', [EngageApiController::class, 'campaign'])->scope('engage.read');
            $api->get('/spots', [EngageApiController::class, 'spots'])->scope('engage.read');
        });

        $opts = [PublicEngageController::class, 'options'];
        foreach ([
            '/api/public/engage/campaign' => 'api.public.engage.campaign.options',
            '/api/public/engage/spots' => 'api.public.engage.spots.options',
            '/api/public/engage/spot' => 'api.public.engage.spot.options',
        ] as $path => $name) {
            $context->router()->add($path, ['OPTIONS'], $opts)->name($name);
        }
        $context->router()->get('/api/public/engage/campaign', [PublicEngageController::class, 'campaign'])
            ->name('api.public.engage.campaign');
        $context->router()->get('/api/public/engage/spots', [PublicEngageController::class, 'spots'])
            ->name('api.public.engage.spots');
        $context->router()->get('/api/public/engage/spot', [PublicEngageController::class, 'spot'])
            ->name('api.public.engage.spot');

        foreach ([
            'engage.campaign.created' => 'Engage kampaň vytvořena',
            'engage.campaign.updated' => 'Engage kampaň uložena',
            'engage.campaign.deleted' => 'Engage kampaň smazána',
            'engage.spot.created' => 'Engage obsah vytvořen',
            'engage.spot.updated' => 'Engage obsah uložen',
            'engage.spot.deleted' => 'Engage obsah smazán',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('engage.campaign.save', [
            'slug' => 'required|min:1|max:80',
            'name' => 'required|min:1|max:190',
            'notes' => 'nullable|max:5000',
            'active' => 'nullable',
            'landing_path' => 'nullable|max:255',
            'cms_page_id' => 'nullable|max:36',
            'cta_label' => 'nullable|max:120',
            'cta_url' => 'nullable|max:2048',
            'utm_source' => 'nullable|max:64',
            'utm_medium' => 'nullable|max:64',
            'utm_campaign' => 'nullable|max:120',
            'og_image_url' => 'nullable|max:2048',
            'og_media_asset_id' => 'nullable|min:36|max:36',
        ]);
        $context->forms()->entity('engage.campaign.save', \Modules\Engage\Domain\Campaign::class);

        $v->ruleset('engage.spot.save', [
            'placement_key' => 'required|min:1|max:80',
            'title' => 'required|min:1|max:190',
            'body' => 'nullable|max:10000',
            'source' => 'required|in:custom,cms_page,listings_path,product,external',
            'ref_id' => 'nullable|max:36',
            'cms_page_id' => 'nullable|max:36',
            'campaign_id' => 'nullable|max:36',
            'cta_label' => 'nullable|max:120',
            'cta_url' => 'nullable|max:2048',
            'image_url' => 'nullable|max:2048',
            'image_media_asset_id' => 'nullable|min:36|max:36',
            'sort_order' => 'nullable|integer',
            'active' => 'nullable',
        ]);
        $context->forms()->entity('engage.spot.save', \Modules\Engage\Domain\Spot::class);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new EngageShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
            $context->get(EngageProvider::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'engage',
            label: 'nav.engage',
            icon: 'campaign',
            route: 'admin.engage.hub',
            permission: 'engage.view',
            order: 50,
            group: 'Marketing',
        ));
        $context->menu()->add(new MenuItem(
            id: 'engage.hub',
            label: 'nav.engage.hub',
            route: 'admin.engage.hub',
            permission: 'engage.view',
            parent: 'engage',
            order: 51,
        ));
        $context->menu()->add(new MenuItem(
            id: 'engage.campaigns',
            label: 'nav.engage.campaigns',
            route: 'admin.engage.campaigns.index',
            permission: 'engage.view',
            parent: 'engage',
            order: 52,
        ));
        $context->menu()->add(new MenuItem(
            id: 'engage.spots',
            label: 'nav.engage.spots',
            route: 'admin.engage.spots.index',
            permission: 'engage.view',
            parent: 'engage',
            order: 53,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'engage.hub',
            label: 'palette.engage.hub',
            url: '/admin/engage',
            icon: 'campaign',
            permission: 'engage.view',
            keywords: ['engage', 'marketing', 'kampaň', 'campaign', 'obsah'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'engage.campaigns',
            label: 'palette.engage.campaigns',
            url: '/admin/engage/campaigns',
            icon: 'campaign',
            permission: 'engage.view',
            keywords: ['mkind', 'utm', 'tracking', 'reklama'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'engage.spots',
            label: 'palette.engage.spots',
            url: '/admin/engage/spots',
            icon: 'layers',
            permission: 'engage.view',
            keywords: ['banner', 'cta', 'hero', 'spot', 'promo'],
        ));
    }
}
