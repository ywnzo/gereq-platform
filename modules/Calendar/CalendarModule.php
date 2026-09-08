<?php

declare(strict_types=1);

namespace Modules\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Calendar\Application\CalendarProvider;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Modules\Calendar\Http\Api\CalendarApiController;
use Modules\Calendar\Http\CalendarController;
use Modules\Calendar\Application\Widgets\CalendarShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CalendarModule implements ModuleInterface
{
    public function id(): string
    {
        return 'calendar';
    }

    public function version(): string
    {
        return '1.2.3';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('calendar.view', 'perm.calendar.view', 'perm.group.calendar');
        $context->permissions()->register('calendar.manage', 'perm.calendar.manage', 'perm.group.calendar');
        $context->capabilities()->register('calendar', $this->id(), new CalendarProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/calendar', function (Router $routes): void {
            $routes->get('', [CalendarController::class, 'index'])->name('admin.calendar.index')->permission('calendar.view');
            $routes->get('/feed', [CalendarController::class, 'feed'])->name('admin.calendar.feed')->permission('calendar.view');
            $routes->post('/move', [CalendarController::class, 'move'])->name('admin.calendar.move')->permission('calendar.manage');
            $routes->post('/save', [CalendarController::class, 'save'])->name('admin.calendar.save')->permission('calendar.manage');
            $routes->get('/export.ics', [CalendarController::class, 'ics'])->name('admin.calendar.ics')->permission('calendar.view');
            $routes->get('/connections', [CalendarController::class, 'connections'])->name('admin.calendar.connections')->permission('calendar.manage');
            $routes->post('/connections', [CalendarController::class, 'storeConnection'])->name('admin.calendar.connections.store')->permission('calendar.manage');
            $routes->post('/connections/{id}/delete', [CalendarController::class, 'destroyConnection'])->name('admin.calendar.connections.destroy')->permission('calendar.manage');
            $routes->get('/oauth/google', [CalendarController::class, 'connectGoogle'])->name('admin.calendar.oauth.google')->permission('calendar.manage');
            $routes->get('/oauth/google/callback', [CalendarController::class, 'googleCallback'])->name('admin.calendar.oauth.google.callback')->permission('calendar.manage');
            $routes->get('/create', [CalendarController::class, 'create'])->name('admin.calendar.create')->permission('calendar.manage');
            $routes->post('', [CalendarController::class, 'store'])->name('admin.calendar.store')->permission('calendar.manage');
            $routes->get('/{id}', [CalendarController::class, 'show'])->name('admin.calendar.show')->permission('calendar.view');
            $routes->get('/{id}/edit', [CalendarController::class, 'edit'])->name('admin.calendar.edit')->permission('calendar.manage');
            $routes->post('/{id}', [CalendarController::class, 'update'])->name('admin.calendar.update')->permission('calendar.manage');
            $routes->post('/{id}/delete', [CalendarController::class, 'destroy'])->name('admin.calendar.destroy')->permission('calendar.manage');
        }, ['middleware' => ['auth'], 'module' => 'calendar']);

        $context->api()->registerKnownScope('calendar.read');
        $context->api()->registerKnownScope('calendar.write');
        $context->api()->module('events', function (ApiModule $api): void {
            $api->get('/', [CalendarApiController::class, 'index'])->scope('calendar.read');
            $api->post('/', [CalendarApiController::class, 'store'])->scope('calendar.write');
            $api->get('/{id}', [CalendarApiController::class, 'show'])->scope('calendar.read');
            $api->patch('/{id}', [CalendarApiController::class, 'update'])->scope('calendar.write');
            $api->delete('/{id}', [CalendarApiController::class, 'destroy'])->scope('calendar.write');
        });

        foreach ([
            'calendar.event_created' => 'Událost kalendáře vytvořena',
            'calendar.event_updated' => 'Událost kalendáře upravena',
            'calendar.event_deleted' => 'Událost kalendáře smazána',
        ] as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($description)->webhookEnabled(true)
            );
        }

        $context->validation()->ruleset('calendar.event.save', [
            'title' => 'required|min:2|max:190',
            'starts_at' => 'required',
            'ends_at' => 'required',
            'type' => 'nullable|in:meeting,deadline,call,task,other',
            'all_day' => 'nullable',
            'description' => 'nullable|max:20000',
            'location' => 'nullable|max:255',
            'color' => 'nullable|in:blue,green,amber,red,violet,slate',
            'timezone' => 'nullable|max:64',
            'rrule' => 'nullable|max:255',
            'status' => 'nullable|in:confirmed,tentative,cancelled',
            'link_entity_type' => 'nullable|max:64',
            'link_entity_id' => 'nullable|max:36',
            'attendee_email' => 'nullable|email|max:190',
        ]);
        $context->forms()->entity('calendar.event.save', \Modules\Calendar\Domain\CalendarEvent::class);

        $context->settings()->define('calendar.google.client_id', 'string', 'calendar.settings.google_client_id', '', 'calendar');
        $context->settings()->define('calendar.google.client_secret', 'encrypted', 'calendar.settings.google_client_secret', '', 'calendar');
        $context->settings()->define('calendar.outlook.client_id', 'string', 'calendar.settings.outlook_client_id', '', 'calendar');
        $context->settings()->define('calendar.outlook.client_secret', 'encrypted', 'calendar.settings.outlook_client_secret', '', 'calendar');
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new CalendarShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'calendar',
            label: 'nav.calendar',
            icon: 'calendar',
            route: 'admin.calendar.index',
            permission: 'calendar.view',
            order: 42,
            group: 'Práce',
        ));
        $context->menu()->add(new MenuItem(
            id: 'calendar.create',
            label: 'nav.calendar.create',
            route: 'admin.calendar.create',
            permission: 'calendar.manage',
            parent: 'calendar',
            order: 43,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'calendar.connections',
            label: 'nav.calendar.connections',
            route: 'admin.calendar.connections',
            permission: 'calendar.manage',
            parent: 'calendar',
            order: 44,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'calendar.index',
            label: 'palette.calendar.index',
            url: '/admin/calendar',
            icon: 'calendar',
            permission: 'calendar.view',
            keywords: ['kalendář', 'calendar', 'schůzka', 'meeting', 'google'],
        ));

        $em = $context->get(EntityManagerInterface::class);
        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope) use ($em): void {
                if ($envelope->type !== 'activity.created') {
                    return;
                }
                try {
                    $activityId = (string) ($envelope->resource['id'] ?? '');
                    if ($activityId === '') {
                        return;
                    }
                    $existing = $em->getRepository(CalendarEventLink::class)->findOneBy([
                        'entityType' => 'crm_activity',
                        'entityId' => $activityId,
                    ]);
                    if ($existing instanceof CalendarEventLink) {
                        return;
                    }
                    $data = $envelope->data;
                    $title = (string) ($data['subject'] ?? $data['title'] ?? 'CRM aktivita');
                    $dueRaw = $data['due_at'] ?? null;
                    $starts = is_string($dueRaw) && $dueRaw !== ''
                        ? new \DateTimeImmutable($dueRaw)
                        : new \DateTimeImmutable();
                    $ends = $starts->modify('+1 hour');
                    $type = (($data['type'] ?? '') === 'meeting') ? 'meeting' : 'other';
                    $event = new CalendarEvent($title, $starts, $ends, $type);
                    if (!empty($data['body'])) {
                        $event->setDescription((string) $data['body']);
                    }
                    $em->persist($event);
                    $em->persist(new CalendarEventLink($event->id(), 'crm_activity', $activityId));
                    $em->flush();
                } catch (\Throwable) {
                    // best effort — never break CRM activity flow
                }
            },
            HookPriority::LATE,
        );
    }
}
