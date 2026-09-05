<?php

declare(strict_types=1);

namespace Modules\Calendar\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Calendar\Application\CalendarProvider;
use Modules\Calendar\Domain\CalendarEvent;
use Modules\Calendar\Domain\CalendarEventLink;
use Modules\Calendar\Domain\CalendarAttendee;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CalendarApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly CalendarProvider $calendar,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $fromRaw = (string) $request->query->get('from', '');
        $toRaw = (string) $request->query->get('to', '');
        try {
            $from = $fromRaw !== '' ? new \DateTimeImmutable($fromRaw) : new \DateTimeImmutable('first day of this month');
            $to = $toRaw !== '' ? new \DateTimeImmutable($toRaw) : $from->modify('+1 month');
        } catch (\Exception) {
            return ApiResponse::error('validation_error', 'Invalid from/to', [], 422);
        }
        $items = $this->calendar->between($from, $to);

        return ApiResponse::collection(
            array_map(fn (CalendarEvent $e): array => $this->calendar->serialize($e), $items),
            ['from' => $from->format(\DateTimeInterface::ATOM), 'to' => $to->format(\DateTimeInterface::ATOM), 'total' => count($items)],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('calendar.event.save', $this->body($request));
        try {
            $starts = new \DateTimeImmutable((string) $data['starts_at']);
            $ends = new \DateTimeImmutable((string) $data['ends_at']);
        } catch (\Exception) {
            return ApiResponse::error('validation_error', 'Invalid dates', [], 422);
        }
        $type = (string) ($data['type'] ?? 'other');
        if (!in_array($type, CalendarEvent::TYPES, true)) {
            $type = 'other';
        }
        $event = new CalendarEvent(trim((string) $data['title']), $starts, $ends, $type);
        $event->setAllDay(($data['all_day'] ?? false) === true || ($data['all_day'] ?? null) === '1');
        $event->setDescription(trim((string) ($data['description'] ?? '')) ?: null);
        $event->setLocation(trim((string) ($data['location'] ?? '')) ?: null);
        if (!empty($data['color'])) {
            $event->setColor((string) $data['color']);
        }
        if (!empty($data['timezone'])) {
            $event->setTimezone((string) $data['timezone']);
        }
        if (array_key_exists('rrule', $data)) {
            $event->setRrule(trim((string) ($data['rrule'] ?? '')) ?: null);
        }
        if (!empty($data['status'])) {
            $event->setStatus((string) $data['status']);
        }
        $this->em->persist($event);
        $this->em->flush();
        $payload = $this->calendar->serialize($event);
        $this->events->emit('calendar.event_created', ['type' => 'calendar_event', 'id' => $event->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $event = $this->em->find(CalendarEvent::class, $id);
        if (!$event instanceof CalendarEvent) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->calendar->serialize($event));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $event = $this->em->find(CalendarEvent::class, $id);
        if (!$event instanceof CalendarEvent) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('calendar.event.save', $this->body($request));
        try {
            $starts = new \DateTimeImmutable((string) $data['starts_at']);
            $ends = new \DateTimeImmutable((string) $data['ends_at']);
        } catch (\Exception) {
            return ApiResponse::error('validation_error', 'Invalid dates', [], 422);
        }
        $event->setTitle(trim((string) $data['title']));
        $event->setStartsAt($starts);
        $event->setEndsAt($ends);
        $type = (string) ($data['type'] ?? $event->type());
        if (in_array($type, CalendarEvent::TYPES, true)) {
            $event->setType($type);
        }
        $event->setAllDay(($data['all_day'] ?? $event->allDay()) === true || ($data['all_day'] ?? null) === '1');
        $event->setDescription(trim((string) ($data['description'] ?? '')) ?: null);
        if (array_key_exists('location', $data)) {
            $event->setLocation(trim((string) ($data['location'] ?? '')) ?: null);
        }
        if (!empty($data['color'])) {
            $event->setColor((string) $data['color']);
        }
        if (!empty($data['timezone'])) {
            $event->setTimezone((string) $data['timezone']);
        }
        if (array_key_exists('rrule', $data)) {
            $event->setRrule(trim((string) ($data['rrule'] ?? '')) ?: null);
        }
        if (!empty($data['status'])) {
            $event->setStatus((string) $data['status']);
        }
        $this->em->flush();
        $payload = $this->calendar->serialize($event);
        $this->events->emit('calendar.event_updated', ['type' => 'calendar_event', 'id' => $event->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $event = $this->em->find(CalendarEvent::class, $id);
        if (!$event instanceof CalendarEvent) {
            return ApiResponse::notFound();
        }
        foreach ($this->em->getRepository(CalendarEventLink::class)->findBy(['eventId' => $id]) as $link) {
            $this->em->remove($link);
        }
        foreach ($this->em->getRepository(CalendarAttendee::class)->findBy(['eventId' => $id]) as $a) {
            $this->em->remove($a);
        }
        $this->em->remove($event);
        $this->em->flush();
        $this->events->emit('calendar.event_deleted', ['type' => 'calendar_event', 'id' => $id], ['id' => $id]);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
