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
use Modules\Calendar\Application\CalendarConnectionService;
use Modules\Calendar\Application\CalendarProvider;
use Modules\Calendar\Application\IcsExporter;
use Modules\Calendar\Domain\CalendarAttendee;
use Modules\Calendar\Domain\CalendarConnection;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly CalendarConnectionService $connections,
        private readonly IcsExporter $ics,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $view = (string) $request->query->get('view', 'month');
        if (!in_array($view, ['month', 'week', 'day', 'agenda'], true)) {
            $view = 'month';
        }
        $anchor = $this->parseDate((string) $request->query->get('date', '')) ?? new \DateTimeImmutable('today');

        return $this->page('@calendar/events/index.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.title'),
            'view' => $view,
            'anchor' => $anchor,
            'feedUrl' => $this->router->url('admin.calendar.feed', [], $request),
            'moveUrl' => $this->router->url('admin.calendar.move', [], $request),
            'createUrl' => $this->router->url('admin.calendar.create', [], $request),
            'canManage' => $this->auth->user()?->can('calendar.manage') ?? false,
            'connections' => array_map(
                fn (CalendarConnection $c) => $this->connections->serialize($c),
                $this->calendar->connections($this->auth->user()?->id()),
            ),
            'types' => CalendarEvent::TYPES,
            'colors' => CalendarEvent::COLORS,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $from = $this->parseDate((string) $request->query->get('from', '')) ?? new \DateTimeImmutable('first day of this month');
        $to = $this->parseDate((string) $request->query->get('to', '')) ?? $from->modify('+1 month');
        $events = $this->calendar->between($from->setTime(0, 0), $to->setTime(23, 59, 59));

        return new JsonResponse([
            'data' => array_map(fn (CalendarEvent $e) => $this->calendar->serialize($e), $events),
            'meta' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'total' => count($events),
            ],
        ]);
    }

    public function move(Request $request): JsonResponse
    {
        if (!($this->auth->user()?->can('calendar.manage') ?? false)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }
        $payload = $this->jsonBody($request);
        $id = (string) ($payload['id'] ?? '');
        $event = $this->calendar->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        $starts = $this->parseDateTime((string) ($payload['starts_at'] ?? ''));
        $ends = $this->parseDateTime((string) ($payload['ends_at'] ?? ''));
        if ($starts === null || $ends === null || $ends < $starts) {
            return new JsonResponse(['error' => 'invalid_range'], 422);
        }
        $event->setStartsAt($starts);
        $event->setEndsAt($ends);
        if (array_key_exists('all_day', $payload)) {
            $event->setAllDay((bool) $payload['all_day']);
        }
        $this->em->flush();
        $data = $this->calendar->serialize($event);
        $this->events->emit('calendar.event_updated', ['type' => 'calendar_event', 'id' => $event->id()], $data);

        return new JsonResponse(['data' => $data]);
    }

    public function ics(Request $request): Response
    {
        $from = $this->parseDate((string) $request->query->get('from', '')) ?? new \DateTimeImmutable('-1 month');
        $to = $this->parseDate((string) $request->query->get('to', '')) ?? new \DateTimeImmutable('+6 months');
        $body = $this->ics->export($this->calendar->between($from, $to), $this->translator->trans('calendar.title'));

        return new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="gereq-calendar.ics"',
        ]);
    }

    public function connections(Request $request): Response
    {
        $user = $this->auth->user();

        return $this->page('@calendar/connections.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.connections.title'),
            'connections' => $this->calendar->connections($user?->id()),
            'catalog' => $this->connections->catalog(),
            'googleReady' => $this->connections->googleConfigured(),
            'googleRedirectUri' => $this->router->absoluteUrl('admin.calendar.oauth.google.callback', [], $request),
            'settingsUrl' => $this->router->url('admin.settings.index', ['tab' => 'calendar']),
            'providers' => CalendarConnection::PROVIDERS,
            'colors' => CalendarEvent::COLORS,
        ]);
    }

    public function storeConnection(Request $request): Response
    {
        $provider = (string) $request->request->get('provider', '');
        $email = trim((string) $request->request->get('account_email', ''));
        if (!in_array($provider, CalendarConnection::PROVIDERS, true) || $email === '') {
            $this->flash('error', $this->translator->trans('calendar.connections.error.invalid'));

            return $this->redirect('admin.calendar.connections');
        }
        $url = trim((string) $request->request->get('caldav_url', '')) ?: null;
        if ($provider === 'seznam' && $url === null) {
            $url = 'https://caldav.seznam.cz/';
        }
        $this->connections->createManual(
            $provider,
            $email,
            $this->auth->user()?->id(),
            $url,
            trim((string) $request->request->get('display_name', '')) ?: null,
            (string) $request->request->get('color', 'blue'),
        );
        $this->flash('success', $this->translator->trans('calendar.connections.flash.created'));

        return $this->redirect('admin.calendar.connections');
    }

    public function destroyConnection(string $id): Response
    {
        $conn = $this->em->find(CalendarConnection::class, $id);
        if ($conn instanceof CalendarConnection) {
            $this->em->remove($conn);
            $this->em->flush();
            $this->flash('success', $this->translator->trans('calendar.connections.flash.deleted'));
        }

        return $this->redirect('admin.calendar.connections');
    }

    public function connectGoogle(Request $request): Response
    {
        $redirect = $this->router->absoluteUrl('admin.calendar.oauth.google.callback', [], $request);
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('calendar.oauth.state', $state);
        $url = $this->connections->googleAuthUrl($redirect, $state);
        if ($url === null) {
            $this->flash('error', $this->translator->trans('calendar.connections.error.google_config'));

            return $this->redirect('admin.calendar.connections');
        }

        return $this->redirectTo($url);
    }

    public function googleCallback(Request $request): Response
    {
        $state = (string) $request->query->get('state', '');
        $expected = (string) $request->getSession()->get('calendar.oauth.state', '');
        $request->getSession()->remove('calendar.oauth.state');
        if ($state === '' || !hash_equals($expected, $state)) {
            $this->flash('error', $this->translator->trans('calendar.connections.error.oauth_state'));

            return $this->redirect('admin.calendar.connections');
        }
        $code = (string) $request->query->get('code', '');
        if ($code === '') {
            $this->flash('error', $this->translator->trans('calendar.connections.error.oauth_denied'));

            return $this->redirect('admin.calendar.connections');
        }

        // Token exchange requires client secret from settings — store pending connection with auth code marker.
        $email = $this->auth->user()?->email() ?? 'google@pending.local';
        $conn = $this->connections->createManual('google', $email, $this->auth->user()?->id(), null, 'Google Calendar', 'green');
        $conn->setStatus('pending');
        $conn->setTokenCipher(json_encode(['code' => $code, 'received_at' => time()], JSON_THROW_ON_ERROR));
        $this->em->flush();
        $this->flash('success', $this->translator->trans('calendar.connections.flash.google_pending'));

        return $this->redirect('admin.calendar.connections');
    }

    public function create(Request $request): Response
    {
        $starts = (string) $request->query->get('starts', '');

        return $this->page('@calendar/events/form.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.new'),
            'item' => null,
            'link' => null,
            'attendees' => [],
            'defaultStarts' => $starts !== '' ? $starts : (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'defaultEnds' => $starts !== ''
                ? (new \DateTimeImmutable($starts))->modify('+1 hour')->format('Y-m-d\TH:i')
                : (new \DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i'),
            'colors' => CalendarEvent::COLORS,
            'types' => CalendarEvent::TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $event = new CalendarEvent($data['title'], $data['starts_at'], $data['ends_at'], $data['type']);
        $this->applyFields($event, $data);
        $event->setCreatedBy($this->auth->user()?->id());
        $this->em->persist($event);
        $this->syncExtras($event->id(), $data);
        $this->em->flush();
        $this->events->emit('calendar.event_created', ['type' => 'calendar_event', 'id' => $event->id()], $this->calendar->serialize($event));
        $this->auditor->log('calendar.created', 'calendar_event', $event->id());
        $this->forgetForm('calendar.event.save');
        $this->flash('success', $this->translator->trans('calendar.flash.created', ['%title%' => $event->title()]));

        return $this->redirect('admin.calendar.show', ['id' => $event->id()]);
    }

    public function show(string $id): Response
    {
        $event = $this->calendar->find($id);
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
        $event = $this->calendar->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }

        return $this->page('@calendar/events/form.html.twig', [
            'pageTitle' => $this->translator->trans('calendar.edit'),
            'item' => $event,
            'link' => $this->em->getRepository(CalendarEventLink::class)->findOneBy(['eventId' => $id]),
            'attendees' => $this->em->getRepository(CalendarAttendee::class)->findBy(['eventId' => $id]),
            'defaultStarts' => $event->startsAt()->format('Y-m-d\TH:i'),
            'defaultEnds' => $event->endsAt()->format('Y-m-d\TH:i'),
            'colors' => CalendarEvent::COLORS,
            'types' => CalendarEvent::TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $event = $this->calendar->find($id);
        if ($event === null) {
            $this->abort(404, $this->translator->trans('calendar.error.not_found'));
        }
        $data = $this->validated($request);
        $event->setTitle($data['title']);
        $event->setStartsAt($data['starts_at']);
        $event->setEndsAt($data['ends_at']);
        $event->setType($data['type']);
        $this->applyFields($event, $data);
        $this->syncExtras($event->id(), $data);
        $this->em->flush();
        $this->events->emit('calendar.event_updated', ['type' => 'calendar_event', 'id' => $event->id()], $this->calendar->serialize($event));
        $this->auditor->log('calendar.updated', 'calendar_event', $id);
        $this->forgetForm('calendar.event.save');
        $this->flash('success', $this->translator->trans('calendar.flash.saved'));

        return $this->redirect('admin.calendar.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $event = $this->calendar->find($id);
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

    /** @param array<string, mixed> $data */
    private function applyFields(CalendarEvent $event, array $data): void
    {
        $event->setAllDay((bool) $data['all_day']);
        $event->setDescription($data['description'] ?? null);
        $event->setLocation($data['location'] ?? null);
        $event->setColor((string) ($data['color'] ?? 'blue'));
        $event->setTimezone($data['timezone'] ?? 'Europe/Prague');
        $event->setRrule($data['rrule'] ?? null);
        $event->setStatus((string) ($data['status'] ?? 'confirmed'));
    }

    /** @param array{link_entity_type?: ?string, link_entity_id?: ?string, attendee_email?: ?string} $data */
    private function syncExtras(string $eventId, array $data): void
    {
        $linkType = trim((string) ($data['link_entity_type'] ?? ''));
        $linkId = trim((string) ($data['link_entity_id'] ?? ''));
        foreach ($this->em->getRepository(CalendarEventLink::class)->findBy(['eventId' => $eventId]) as $link) {
            $this->em->remove($link);
        }
        if ($linkType !== '' && $linkId !== '') {
            $this->em->persist(new CalendarEventLink($eventId, $linkType, $linkId));
        }

        $attendeeEmail = trim((string) ($data['attendee_email'] ?? ''));
        if ($attendeeEmail !== '') {
            $exists = $this->em->getRepository(CalendarAttendee::class)->findOneBy([
                'eventId' => $eventId,
                'email' => $attendeeEmail,
            ]);
            if ($exists === null) {
                $this->em->persist(new CalendarAttendee($eventId, null, $attendeeEmail));
            }
        }
    }

    /** @return array<string, mixed> */
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
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'color' => (string) ($data['color'] ?? 'blue'),
            'timezone' => trim((string) ($data['timezone'] ?? 'Europe/Prague')) ?: 'Europe/Prague',
            'rrule' => trim((string) ($data['rrule'] ?? '')) ?: null,
            'status' => (string) ($data['status'] ?? 'confirmed'),
            'link_entity_type' => trim((string) ($data['link_entity_type'] ?? '')) ?: null,
            'link_entity_id' => trim((string) ($data['link_entity_id'] ?? '')) ?: null,
            'attendee_email' => trim((string) ($data['attendee_email'] ?? '')) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
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
