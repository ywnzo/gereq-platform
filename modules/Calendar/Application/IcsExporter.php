<?php

declare(strict_types=1);

namespace Modules\Calendar\Application;

use Modules\Calendar\Domain\CalendarEvent;

/** Build RFC 5545 iCalendar (.ics) payloads. */
final class IcsExporter
{
    /** @param list<CalendarEvent> $events */
    public function export(array $events, string $calendarName = 'GEREQ Calendar'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//GEREQ//Calendar//CS',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape($calendarName),
        ];

        foreach ($events as $event) {
            if (!$event instanceof CalendarEvent) {
                continue;
            }
            $lines = array_merge($lines, $this->vevent($event));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @return list<string> */
    private function vevent(CalendarEvent $event): array
    {
        $uid = $event->externalId() ?: $event->id() . '@gereq';
        $stamp = $this->utc($event->updatedAt());
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'DTSTAMP:' . $stamp,
            'SUMMARY:' . $this->escape($event->title()),
        ];

        if ($event->allDay()) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $event->startsAt()->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $event->endsAt()->format('Ymd');
        } else {
            $tz = $event->timezone() ?: 'Europe/Prague';
            $lines[] = 'DTSTART;TZID=' . $tz . ':' . $event->startsAt()->format('Ymd\THis');
            $lines[] = 'DTEND;TZID=' . $tz . ':' . $event->endsAt()->format('Ymd\THis');
        }

        if ($event->location()) {
            $lines[] = 'LOCATION:' . $this->escape($event->location());
        }
        if ($event->description()) {
            $lines[] = 'DESCRIPTION:' . $this->escape($event->description());
        }
        if ($event->rrule()) {
            $lines[] = 'RRULE:' . $event->rrule();
        }
        $lines[] = 'STATUS:' . strtoupper($event->status());
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function utc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function escape(string $value): string
    {
        return str_replace(
            ["\\", ';', ',', "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', ''],
            $value,
        );
    }
}
