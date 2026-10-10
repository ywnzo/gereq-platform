<?php

declare(strict_types=1);

namespace Modules\Events;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAccount;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Events\Application\EventCalendarSync;
use Modules\Events\Application\EventCatalog;
use Modules\Events\Application\EventOrganizerDemoSeeder;
use Modules\Events\Application\EventsCms;
use Modules\Events\Application\EventsWebPlus;
use Modules\Events\Application\Widgets\EventMessagesWidget;
use Modules\Events\Application\Widgets\EventsCreateHeroWidget;
use Modules\Events\Application\Widgets\EventsCreatedChartWidget;
use Modules\Events\Application\Widgets\EventsShortcutWidget;
use Modules\Events\Application\Widgets\EventsStatsWidget;
use Modules\Events\Application\Widgets\PendingEventsWidget;
use Modules\Events\Application\Widgets\UpcomingEventsWidget;
use Modules\Events\Domain\CatalogEvent;
use Gereq\Api\ApiModule;
use Gereq\Setting\SettingRecord;
use Modules\Events\Http\Api\CatalogEventApiController;
use Modules\Events\Http\Api\PublicEventApiController;
use Modules\Events\Application\EventCategories;
use Modules\Events\Application\EventRegionImages;
use Modules\Events\Application\EventTrust;
use Modules\Events\Http\EventAdminController;
use Modules\Events\Http\EventCategoryController;
use Modules\Events\Http\EventContactController;
use Modules\Events\Http\EventFeedController;
use Modules\Events\Http\EventRegionController;
use Modules\Events\Domain\EventFeed;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'events';
    }

    public function version(): string
    {
        return '0.5.5';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('events.view', 'perm.events.view', 'perm.group.events');
        $context->permissions()->register('events.manage', 'perm.events.manage', 'perm.group.events');
        $context->capabilities()->register('events', $this->id(), $context->get(EventCatalog::class));

        $context->settings()->define(
            EventRegionImages::SETTING_KEY,
            SettingRecord::TYPE_JSON,
            'events.regions.setting',
            [],
            $this->id(),
            [],
            false,
        );

        $context->router()->group('/admin/events', function (Router $routes): void {
            $routes->get('', [EventAdminController::class, 'index'])->name('admin.events.index')->permission('events.view');
            $routes->get('/create', [EventAdminController::class, 'create'])->name('admin.events.create')->permission('events.manage');
            $routes->post('', [EventAdminController::class, 'store'])->name('admin.events.store')->permission('events.manage');
            $routes->post('/bulk', [EventAdminController::class, 'bulk'])->name('admin.events.bulk')->permission('events.manage');
            $routes->post('/organizers/{customerId}/trust', [EventAdminController::class, 'trust'])->name('admin.events.organizers.trust')->permission('events.manage');
            $routes->post('/organizers/{customerId}/untrust', [EventAdminController::class, 'untrust'])->name('admin.events.organizers.untrust')->permission('events.manage');
            $routes->get('/messages', [EventContactController::class, 'index'])->name('admin.events.messages')->permission('events.view');
            $routes->post('/messages/{id}/delete', [EventContactController::class, 'destroy'])->name('admin.events.messages.destroy')->permission('events.manage');
            $routes->get('/categories', [EventCategoryController::class, 'index'])->name('admin.events.categories')->permission('events.view');
            $routes->get('/categories/create', [EventCategoryController::class, 'create'])->name('admin.events.categories.create')->permission('events.manage');
            $routes->post('/categories', [EventCategoryController::class, 'store'])->name('admin.events.categories.store')->permission('events.manage');
            $routes->get('/categories/{id}/edit', [EventCategoryController::class, 'edit'])->name('admin.events.categories.edit')->permission('events.manage');
            $routes->post('/categories/{id}', [EventCategoryController::class, 'update'])->name('admin.events.categories.update')->permission('events.manage');
            $routes->post('/categories/{id}/delete', [EventCategoryController::class, 'destroy'])->name('admin.events.categories.destroy')->permission('events.manage');
            $routes->get('/regions', [EventRegionController::class, 'index'])->name('admin.events.regions')->permission('events.view');
            $routes->post('/regions', [EventRegionController::class, 'save'])->name('admin.events.regions.save')->permission('events.manage');
            $routes->get('/feeds', [EventFeedController::class, 'index'])->name('admin.events.feeds')->permission('events.view');
            $routes->get('/feeds/create', [EventFeedController::class, 'create'])->name('admin.events.feeds.create')->permission('events.manage');
            $routes->post('/feeds', [EventFeedController::class, 'store'])->name('admin.events.feeds.store')->permission('events.manage');
            $routes->get('/feeds/{id}/edit', [EventFeedController::class, 'edit'])->name('admin.events.feeds.edit')->permission('events.manage');
            $routes->post('/feeds/{id}', [EventFeedController::class, 'update'])->name('admin.events.feeds.update')->permission('events.manage');
            $routes->post('/feeds/{id}/run', [EventFeedController::class, 'run'])->name('admin.events.feeds.run')->permission('events.manage');
            $routes->post('/feeds/{id}/delete', [EventFeedController::class, 'destroy'])->name('admin.events.feeds.destroy')->permission('events.manage');
            $routes->get('/{id}', [EventAdminController::class, 'show'])->name('admin.events.show')->permission('events.view');
            $routes->get('/{id}/edit', [EventAdminController::class, 'edit'])->name('admin.events.edit')->permission('events.manage');
            $routes->post('/{id}', [EventAdminController::class, 'update'])->name('admin.events.update')->permission('events.manage');
            $routes->post('/{id}/publish', [EventAdminController::class, 'publish'])->name('admin.events.publish')->permission('events.manage');
            $routes->post('/{id}/archive', [EventAdminController::class, 'archive'])->name('admin.events.archive')->permission('events.manage');
            $routes->post('/{id}/reject', [EventAdminController::class, 'reject'])->name('admin.events.reject')->permission('events.manage');
            $routes->post('/{id}/spotlight', [EventAdminController::class, 'spotlight'])->name('admin.events.spotlight')->permission('events.manage');
            $routes->post('/{id}/delete', [EventAdminController::class, 'destroy'])->name('admin.events.destroy')->permission('events.manage');
        }, ['middleware' => ['auth']]);

        $public = [PublicEventApiController::class, 'options'];
        foreach ([
            '/api/public/events',
            '/api/public/events/meta',
            '/api/public/events/favorites',
            '/api/public/events/mine',
            '/api/public/events/{slug}',
            '/api/public/events/{id}/favorite',
            '/api/public/events/{id}/interest',
            '/api/public/events/{id}/revise',
            '/api/public/events/contact',
        ] as $path) {
            $context->router()->add($path, ['OPTIONS'], $public);
        }

        $context->router()->get('/api/public/events', [PublicEventApiController::class, 'index'])->name('api.public.events.index');
        $context->router()->get('/api/public/events/meta', [PublicEventApiController::class, 'meta'])->name('api.public.events.meta');
        $context->router()->get('/api/public/events/favorites', [PublicEventApiController::class, 'favorites'])->name('api.public.events.favorites');
        $context->router()->get('/api/public/events/mine', [PublicEventApiController::class, 'mine'])->name('api.public.events.mine');
        $context->router()->post('/api/public/events', [PublicEventApiController::class, 'store'])->name('api.public.events.store');
        $context->router()->post('/api/public/events/{id}/favorite', [PublicEventApiController::class, 'favorite'])->name('api.public.events.favorite');
        $context->router()->post('/api/public/events/{id}/interest', [PublicEventApiController::class, 'interest'])->name('api.public.events.interest');
        $context->router()->post('/api/public/events/{id}/revise', [PublicEventApiController::class, 'revise'])->name('api.public.events.revise');
        $context->router()->post('/api/public/events/contact', [PublicEventApiController::class, 'contact'])->name('api.public.events.contact');
        $context->router()->get('/api/public/events/{slug}', [PublicEventApiController::class, 'show'])->name('api.public.events.show');

        $context->api()->registerKnownScope('catalog-events.read');
        $context->api()->registerKnownScope('catalog-events.write');
        $context->api()->module('catalog-events', function (ApiModule $api): void {
            $api->get('/', [CatalogEventApiController::class, 'index'])
                ->scope('catalog-events.read')->permission('events.view')
                ->summary('List catalog events');
            $api->get('/meta', [CatalogEventApiController::class, 'meta'])
                ->scope('catalog-events.read')->permission('events.view')
                ->summary('Catalog event meta (statuses, regions, categories)');
            $api->post('/', [CatalogEventApiController::class, 'store'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Create catalog event')->ruleset('events.save');
            $api->post('/bulk', [CatalogEventApiController::class, 'bulk'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Bulk catalog event actions');
            $api->get('/{id}', [CatalogEventApiController::class, 'show'])
                ->scope('catalog-events.read')->permission('events.view')
                ->summary('Get catalog event');
            $api->patch('/{id}', [CatalogEventApiController::class, 'update'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Update catalog event')->ruleset('events.save');
            $api->delete('/{id}', [CatalogEventApiController::class, 'destroy'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Delete catalog event');
            $api->post('/{id}/publish', [CatalogEventApiController::class, 'publish'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Publish / approve catalog event');
            $api->post('/{id}/archive', [CatalogEventApiController::class, 'archive'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Archive catalog event');
            $api->post('/{id}/reject', [CatalogEventApiController::class, 'reject'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Reject catalog event');
            $api->post('/{id}/spotlight', [CatalogEventApiController::class, 'spotlight'])
                ->scope('catalog-events.write')->permission('events.manage')
                ->summary('Set catalog event spotlight');
        });

        foreach ([
            'events.submitted' => 'Nová akce čeká na schválení',
            'events.created' => 'Akce vytvořena v administraci',
            'events.updated' => 'Akce upravena',
            'events.published' => 'Akce zveřejněna',
            'events.rejected' => 'Akce zamítnuta',
            'events.archived' => 'Akce archivována',
            'events.deleted' => 'Akce smazána',
            'events.interest' => 'Někdo má zájem o akci',
            'events.contact' => 'Nová zpráva z webu',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $regions = implode(',', CatalogEvent::REGIONS);
        $colors = implode(',', CatalogEvent::COLORS);
        $v->ruleset('events.save', [
            'title' => 'required|min:2|max:190',
            'slug' => 'nullable|max:190',
            'description' => 'required|min:1|max:20000',
            'long_description' => 'nullable|max:200000',
            'category' => 'required|max:80',
            'color' => 'nullable|in:' . $colors,
            'image_key' => 'nullable|max:64',
            'image' => 'nullable|max:64',
            'image_url' => 'nullable|max:500',
            'media_asset_id' => 'nullable|max:36',
            'place' => 'required|max:190',
            'region' => 'required|in:' . $regions,
            'starts_at' => 'nullable|max:40',
            'ends_at' => 'nullable|max:40',
            'show_starts_at' => 'nullable',
            'show_ends_at' => 'nullable',
            'time_label' => 'nullable|max:32',
            'time' => 'nullable|max:32',
            'price' => 'nullable|max:80',
            'capacity' => 'nullable|max:80',
            'organizer' => 'nullable|max:190',
            'external_url' => 'nullable|url|max:500',
            'status' => 'nullable|in:pending,published,archived,rejected',
            'spotlight' => 'nullable|in:none,week,month',
            'gallery' => 'nullable',
        ]);
        $v->ruleset('events.bulk', [
            'action' => 'required|in:publish,archive,delete',
            'confirmed' => 'nullable',
        ]);
        $v->ruleset('events.reject', [
            'reason' => 'required|max:20000',
        ]);
        $v->ruleset('events.spotlight', [
            'spotlight' => 'required|in:none,week,month',
        ]);
        $v->ruleset('events.submit', [
            'title' => 'required|min:2|max:190',
            'description' => 'required|min:1|max:20000',
            'category' => 'required|max:80',
            'place' => 'required|max:190',
            'region' => 'required|in:' . $regions,
            'starts_at' => 'nullable|max:40',
            'ends_at' => 'nullable|max:40',
            'show_starts_at' => 'nullable',
            'show_ends_at' => 'nullable',
            'time' => 'nullable|max:32',
            'price' => 'nullable|max:80',
            'capacity' => 'nullable|max:80',
            'organizer' => 'nullable|max:190',
            'external_url' => 'nullable|url|max:500',
            'long_description' => 'nullable|max:20000',
            'image' => 'nullable|max:64',
        ]);
        $v->ruleset('events.contact', [
            'name' => 'required|min:2|max:190',
            'email' => 'required|email|max:190',
            'topic' => 'nullable|in:tip,data,other',
            'message' => 'required|min:3|max:20000',
        ]);
        $v->ruleset('events.category.save', [
            'name' => 'required|min:1|max:80',
            'slug' => 'required|min:1|max:80',
            'blurb' => 'nullable|max:190',
            'icon' => 'nullable|max:64',
            'color_hex' => 'nullable|max:16',
            'tint' => 'nullable|max:16',
            'image_key' => 'nullable|max:64',
            'event_color' => 'nullable|in:' . $colors,
            'kicker' => 'nullable|max:190',
            'show_on_home' => 'nullable',
            'assignable' => 'nullable',
            'hide_dates' => 'nullable',
            'sort_order' => 'nullable|integer',
        ]);
        $drivers = implode(',', EventFeed::DRIVERS);
        $v->ruleset('events.feed.save', [
            'key' => 'required|min:2|max:40',
            'name' => 'required|min:2|max:120',
            'driver' => 'required|in:' . $drivers,
            'url' => 'required|url|max:500',
            'region' => 'required|in:' . $regions,
            'organizer' => 'nullable|max:190',
            'enabled' => 'nullable',
            'sort_order' => 'nullable|integer',
            'config_category' => 'nullable|max:80',
            'config_per_page' => 'nullable|integer',
            'config_max_pages' => 'nullable|integer',
            'config_venues' => 'nullable|max:190',
            'config_district_eids' => 'nullable|max:190',
            'config_start_from' => 'nullable|max:40',
            'config_fetch_details' => 'nullable',
            'config_map_card' => 'nullable|max:300',
            'config_map_link' => 'nullable|max:300',
            'config_map_title' => 'nullable|max:300',
            'config_map_type' => 'nullable|max:300',
            'config_map_date_class' => 'nullable|max:80',
            'config_map_time_class' => 'nullable|max:80',
            'config_map_place_class' => 'nullable|max:80',
            'config_map_price_class' => 'nullable|max:80',
            'config_map_image' => 'nullable|max:300',
            'config_map_page_param' => 'nullable|max:40',
            'config_map_detail_body' => 'nullable|max:300',
            'config_map_detail_teaser' => 'nullable|max:300',
            'config_map_detail_gallery' => 'nullable|max:120',
            'config_map_poster_alt' => 'nullable|max:120',
            'config_json' => 'nullable|max:5000',
        ]);
        $context->forms()->entity('events.save', CatalogEvent::class);
        $context->forms()->entity('events.category.save', \Modules\Events\Domain\EventCategory::class);
        $context->forms()->entity('events.feed.save', EventFeed::class);

        $cats = $context->get(EventCategories::class);
        $translator = $context->get(TranslatorInterface::class);
        $checkCategory = static function (array $data, array $errors) use ($cats, $translator): array {
            $allowed = $cats->assignableSlugs();
            $value = trim((string) ($data['category'] ?? ''));
            if ($value !== '' && $allowed !== [] && !in_array($value, $allowed, true)) {
                $errors['category'] = $translator->trans('events.error.bad_category');
            }

            return $errors;
        };
        $checkSchedule = static function (array $data, array $errors) use ($cats, $translator): array {
            $category = trim((string) ($data['category'] ?? ''));
            $hideDates = $category !== '' && $cats->hidesDates($category);
            $startRaw = trim((string) ($data['starts_at'] ?? ''));
            $endRaw = trim((string) ($data['ends_at'] ?? ''));
            $showStart = !array_key_exists('show_starts_at', $data)
                || in_array(strtolower(trim((string) $data['show_starts_at'])), ['1', 'true', 'on', 'yes'], true)
                || $data['show_starts_at'] === true
                || $data['show_starts_at'] === 1;
            if (!$hideDates && $showStart && $startRaw === '') {
                $errors['starts_at'] = $translator->trans('events.error.starts_required');
            }
            $start = EventCatalog::tryParseDate($startRaw);
            $end = EventCatalog::tryParseDate($endRaw);
            if ($start === null && $startRaw !== '') {
                $errors['starts_at'] = $translator->trans('events.error.bad_date');
            }
            if ($end === null && $endRaw !== '') {
                $errors['ends_at'] = $translator->trans('events.error.bad_date');
            }
            if ($start !== null && $end !== null && $end < $start) {
                $errors['ends_at'] = $translator->trans('events.error.ends_before_start');
            }

            return $errors;
        };
        $v->after('events.save', $checkCategory);
        $v->after('events.save', $checkSchedule);
        $v->after('events.submit', $checkCategory);
        $v->after('events.submit', $checkSchedule);

        // Events + categories go live immediately (approval status for events) — no staging.
    }

    public function boot(ModuleContext $context): void
    {
        $context->get(EventCategories::class)->ensureHideDatesColumn();
        $context->get(EventCatalog::class)->ensureShowDatesColumns();
        $context->get(EventCatalog::class)->ensureSearchVector();
        $context->get(EventCategories::class)->seedIfEmpty();
        $context->get(EventCategories::class)->reconcileCanonical();
        $context->get(EventCatalog::class)->seedIfEmpty();
        try {
            $context->get(EventRegionImages::class)->seedMissing();
        } catch (\Throwable) {
            // Media / settings may be unavailable mid-migrate.
        }
        try {
            $context->get(EventCatalog::class)->discardStaging();
            $context->get(EventCategories::class)->discardStaging();
        } catch (\Throwable) {
            // Staging table may be missing mid-migrate.
        }
        try {
            $context->get(EventOrganizerDemoSeeder::class)->runMissing();
        } catch (\Throwable) {
            // Demo pořadatelé nesmí shodit boot adminu.
        }
        if (class_exists(\Modules\Cms\Domain\Page::class)) {
            $context->get(EventsCms::class)->seedIfEmpty();
        }
        if (class_exists(\Modules\WebPlus\Domain\WebMeta::class)) {
            $context->get(EventsWebPlus::class)->boot();
        }

        $twig = $context->get(Environment::class);
        $translator = $context->get(TranslatorInterface::class);
        $router = $context->get(Router::class);
        $em = $context->get(EntityManagerInterface::class);
        $context->dashboard()->addWidget(new EventsShortcutWidget($twig, $translator, $router));
        $context->dashboard()->addWidget(new EventsCreateHeroWidget($twig, $translator, $router));
        foreach ([
            EventsStatsWidget::class,
            PendingEventsWidget::class,
            UpcomingEventsWidget::class,
            EventsCreatedChartWidget::class,
            EventMessagesWidget::class,
        ] as $widgetClass) {
            $context->dashboard()->addWidget(new $widgetClass($twig, $em, $translator, $router));
        }

        $context->menu()->add(new MenuItem(
            id: 'events',
            label: 'nav.events',
            icon: 'view_timeline',
            route: 'admin.events.index',
            permission: 'events.view',
            order: 24,
            group: 'KdeAco',
        ));
        $context->menu()->add(new MenuItem(
            id: 'events.catalog',
            label: 'events.title',
            route: 'admin.events.index',
            permission: 'events.view',
            parent: 'events',
            order: 10,
        ));
        $context->menu()->add(new MenuItem(
            id: 'events.categories',
            label: 'events.categories.title',
            route: 'admin.events.categories',
            permission: 'events.view',
            parent: 'events',
            order: 15,
        ));
        $context->menu()->add(new MenuItem(
            id: 'events.regions',
            label: 'events.regions.title',
            icon: 'map',
            route: 'admin.events.regions',
            permission: 'events.view',
            parent: 'events',
            order: 16,
        ));
        $context->menu()->add(new MenuItem(
            id: 'events.messages',
            label: 'events.contacts.title',
            route: 'admin.events.messages',
            permission: 'events.view',
            parent: 'events',
            order: 20,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'events.list',
            label: 'palette.events',
            url: '/admin/events',
            icon: 'view_timeline',
            permission: 'events.view',
            keywords: ['akce', 'event', 'katalog', 'kdeaco'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'events.categories',
            label: 'events.categories.title',
            url: '/admin/events/categories',
            icon: 'tag',
            permission: 'events.view',
            keywords: ['kategorie', 'category', 'mood', 'kdeaco'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'events.regions',
            label: 'events.regions.title',
            url: '/admin/events/regions',
            icon: 'map',
            permission: 'events.view',
            keywords: ['okres', 'region', 'město', 'fotka', 'obrazek', 'kdeaco'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'events.messages',
            label: 'events.contacts.title',
            url: '/admin/events/messages',
            icon: 'mail',
            permission: 'events.view',
            keywords: ['kontakt', 'zprava', 'message', 'kdeaco'],
        ));

        $auth = $context->get(Authenticator::class);
        $catalog = $context->get(EventCatalog::class);
        $trust = $context->get(EventTrust::class);
        $context->hooks()->addFilter(
            'store.auth.serialize',
            static function (mixed $payload, mixed $account) use ($trust): array {
                if (!is_array($payload)) {
                    return [];
                }
                $customerId = '';
                if ($account instanceof CustomerAccount) {
                    $customerId = $account->customerId();
                }
                if ($customerId === '') {
                    $customerId = (string) ($payload['customer_id'] ?? '');
                }
                $payload['trusted'] = $trust->isTrusted($customerId);

                return $payload;
            },
            HookPriority::NORMAL,
        );
        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, mixed $customer) use ($auth, $twig, $catalog, $trust, $translator): array {
                $user = $auth->user();
                if ($user === null || !$user->can('events.view') || !$customer instanceof Customer) {
                    return $sections;
                }
                $trusted = $trust->isTrusted($customer->id());
                $chip = $trusted
                    ? '<span class="badge badge--ok">' . htmlspecialchars($translator->trans('events.trust.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                    : '';
                $sections[] = [
                    'id' => 'events',
                    'label' => 'events.saved.title',
                    'order' => 48,
                    'html' => $twig->render('@events/partials/customer_saved.html.twig', [
                        'saved' => $catalog->favoritesForCustomer($customer->id()),
                    ]),
                ];
                $sections[] = [
                    'id' => 'events.submitted',
                    'label' => 'events.submitted.title',
                    'order' => 49,
                    'overview_html' => $chip,
                    'html' => $twig->render('@events/partials/customer_submitted.html.twig', [
                        'items' => $catalog->submittedByCustomer($customer->id()),
                        'customer' => $customer,
                        'trusted' => $trusted,
                        'canManage' => $user->can('events.manage'),
                    ]),
                ];
                $sections[] = [
                    'id' => 'events.interest',
                    'label' => 'events.interest.people',
                    'order' => 50,
                    'html' => $twig->render('@events/partials/customer_interest.html.twig', [
                        'items' => $catalog->interestsForCustomer($customer->id()),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );

        $calendarSync = $context->get(EventCalendarSync::class);
        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (mixed $envelope) use ($context, $catalog, $calendarSync): void {
                if (!$envelope instanceof EventEnvelope) {
                    return;
                }
                $calendarSync->handleEnvelope($envelope);
                if ($envelope->type !== 'customer.store_deleted') {
                    return;
                }
                $customerId = (string) ($envelope->data['customer_id'] ?? '');
                $email = (string) ($envelope->data['email'] ?? '');
                if ($customerId !== '') {
                    $catalog->purgeCustomer($customerId);
                }
                if ($email !== '' && class_exists(\Modules\Newsletter\Application\NewsletterProvider::class)) {
                    try {
                        $context->get(\Modules\Newsletter\Application\NewsletterProvider::class)->unsubscribe($email);
                    } catch (\Throwable) {
                    }
                }
            },
            HookPriority::NORMAL,
        );
    }
}
