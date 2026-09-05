<?php

declare(strict_types=1);

namespace Modules\Calendar\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Calendar\Application\CalendarProvider;
use Modules\Calendar\Domain\CalendarAttendee;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CalendarController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly CalendarProvider $calendar,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $view = (string) $request->query->get('view', 'month');
        if (!in_array($view, ['month', 'week'], true)) {
            $view = 'month';
        }
        $anchor = $this->parseDate((string) $request->query->get('date', '')) ?? new \DateTimeImmutable('today');

        if ($view === 'week') {
            $from = $anchor->modify('monday this week')->setTime(0, 0);
            $to = $from->modify('+7 days');
        } else {
            $from = $anchor->modify('first day of this month')->setTime(0, 0);
            $to = $from->modify('first day of next month');
        }

        $events = $this->calendar->between($from, $to);

        return $this->page('@calendar/events/index.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.title'),
            'events' => $events,
            'view' => $view,
            'anchor' => $anchor,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function create(Request $request): Response
    {
        $starts = (string) $request->query->get('starts', '');

        return $this->page('@calendar/events/form.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.new'),
            'item' => null,
            'defaultStarts' => $starts !== '' ? $starts : (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $event = new CalendarEvent($data['title'], $data['starts_at'], $data['ends_at'], $data['type']);
        $event->setAllDay($data['all_day']);
        $event->setDescription($data['description']);
        $this->em->persist($event);
        $this->persistExtras($event->id(), $data);
        $this->em->flush();
        $this->events->emit('calendar.event_created', ['type' => 'calendar_event', 'id' => $event->id()], $this->public($event));
        $this->auditor->log('calendar.created', 'calendar_event', $event->id());
        $this->forgetForm('calendar.event.save');
        $this->flash('success', $this->translator->trans('calendar.flash.created', ['%title%' => $event->title()]));

        return $this->redirect('admin.calendar.show', ['id' => $event->id()]);
    }

    public function show(string $id): Response
    {
        $event = $this->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }

        return $this->page('@calendar/events/show.html.twig', [
            'pageTitle' => $event->title(),
            'item' => $event,
            'links' => $this->em->getRepository(CalendarEventLink::class)->findBy(['eventId' => $id]),
            'attendees' => $this->em->getRepository(CalendarAttendee::class)->findBy(['eventId' => $id]),
        ]);
    }

    public function edit(string $id): Response
    {
        $event = $this->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }

        return $this->page('@calendar/events/form.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.edit'),
            'item' => $event,
            'defaultStarts' => $event->startsAt()->format('Y-m-d\TH:i'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $event = $this->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }
        $data = $this->validated($request);
        $event->setTitle($data['title']);
        $event->setStartsAt($data['starts_at']);
        $event->setEndsAt($data['ends_at']);
        $event->setType($data['type']);
        $event->setAllDay($data['all_day']);
        $event->setDescription($data['description']);
        $this->em->flush();
        $this->events->emit('calendar.event_updated', ['type' => 'calendar_event', 'id' => $event->id()], $this->public($event));
        $this->auditor->log('calendar.updated', 'calendar_event', $id);
        $this->forgetForm('calendar.event.save');
        $this->flash('success', $this->translator->trans('calendar.flash.saved'));

        return $this->redirect('admin.calendar.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $event = $this->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }
        $title = $event->title();
        foreach ($this->em->getRepository(CalendarEventLink::class)->findBy(['eventId' => $id]) as $link) {
            $this->em->remove($link);
        }
        foreach ($this->em->getRepository(CalendarAttendee::class)->findBy(['eventId' => $id]) as $a) {
            $this->em->remove($a);
        }
        $this->em->remove($event);
        $this->em->flush();
        $this->events->emit('calendar.event_deleted', ['type' => 'calendar_event', 'id' => $id], ['id' => $id, 'title' => $title]);
        $this->flash('success', $this->translator->trans('calendar.flash.deleted', ['%title%' => $title]));

        return $this->redirect('admin.calendar.index');
    }

    private function find(string $id): ?CalendarEvent
    {
        $e = $this->em->find(CalendarEvent::class, $id);

        return $e instanceof CalendarEvent ? $e : null;
    }

    /** @param array{title: string, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, type: string, all_day: bool, description: ?string, link_entity_type?: string, link_entity_id?: string, attendee_email?: string} $data */
    private function persistExtras(string $eventId, array $data): void
    {
        if (!empty($data['link_entity_type']) && !empty($data['link_entity_id'])) {
            $this->em->persist(new CalendarEventLink($eventId, (string) $data['link_entity_type'], (string) $data['link_entity_id']));
        }
        if (!empty($data['attendee_email'])) {
            $this->em->persist(new CalendarAttendee($eventId, null, (string) $data['attendee_email']));
        }
    }

    /** @return array<string, mixed> */
    private function public(CalendarEvent $e): array
    {
        return [
            'id' => $e->id(),
            'title' => $e->title(),
            'starts_at' => $e->startsAt()->format(\DateTimeInterface::ATOM),
            'ends_at' => $e->endsAt()->format(\DateTimeInterface::ATOM),
            'all_day' => $e->allDay(),
            'type' => $e->type(),
            'description' => $e->description(),
            'created_at' => $e->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $e->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{title: string, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, type: string, all_day: bool, description: ?string, link_entity_type?: string, link_entity_id?: string, attendee_email?: string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'calendar.event.save');
        $starts = $this->parseDateTime((string) $data['starts_at']);
        $ends = $this->parseDateTime((string) $data['ends_at']);
        if ($starts === null || $ends === null) {
            $this->abort(422, $this->translator->trans('calendar.error.invalid_range'));
        }
        if ($ends < $starts) {
            $this->abort(422, $this->translator->trans('calendar.error.invalid_range'));
        }
        $type = (string) ($data['type'] ?? 'other');
        if (!in_array($type, CalendarEvent::TYPES, true)) {
            $type = 'other';
        }
        $allDay = ($data['all_day'] ?? null) === true || ($data['all_day'] ?? null) === '1' || ($data['all_day'] ?? null) === 'on';

        return [
            'title' => trim((string) $data['title']),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'type' => $type,
            'all_day' => $allDay,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'link_entity_type' => trim((string) ($data['link_entity_type'] ?? '')) ?: null,
            'link_entity_id' => trim((string) ($data['link_entity_id'] ?? '')) ?: null,
            'attendee_email' => trim((string) ($data['attendee_email'] ?? '')) ?: null,
        ];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDateTime(string $raw): ?\DateTimeImmutable
    {
        return $this->parseDate($raw);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
