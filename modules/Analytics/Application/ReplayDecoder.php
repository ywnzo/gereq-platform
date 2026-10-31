<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

final class ReplayDecoder
{
    /**
     * Incomplete batches are omitted; never play incremental events without their initial snapshot.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    public function decode(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $props = json_decode((string) ($row['props'] ?? ''), true);
            if (!is_array($props) || ($props['encoding'] ?? '') !== 'gzip-base64') {
                continue;
            }
            $recording = (string) ($props['recording'] ?? '');
            $batch = $props['batch'] ?? null;
            $part = $props['part'] ?? null;
            $total = $props['total'] ?? null;
            $data = $props['data'] ?? null;
            if (preg_match('/\A[a-f0-9-]{36}\z/D', $recording) !== 1
                || !is_int($batch) || $batch < 0 || $batch > 600
                || !is_int($total) || $total < 1 || $total > 32
                || !is_int($part) || $part < 0 || $part >= $total
                || !is_string($data) || strlen($data) > 16000
            ) {
                continue;
            }
            $groups[$recording][$batch]['total'] = $total;
            $groups[$recording][$batch]['parts'][$part] = $data;
        }

        $recordings = [];
        $decodedBytes = 0;
        foreach ($groups as $recording => $batches) {
            ksort($batches);
            $events = [];
            $expected = 0;
            foreach ($batches as $number => $batch) {
                if ($number !== $expected || count($batch['parts']) !== $batch['total']) {
                    break;
                }
                ksort($batch['parts']);
                $compressed = base64_decode(implode('', $batch['parts']), true);
                $json = $compressed !== false ? @gzdecode($compressed, 2000000) : false;
                if ($json === false || ($decodedBytes += strlen($json)) > 8000000) {
                    break;
                }
                $decoded = json_decode($json, true, 128);
                if (!is_array($decoded) || !array_is_list($decoded)) {
                    break;
                }
                foreach ($decoded as $event) {
                    if (!is_array($event) || !is_int($event['type'] ?? null)
                        || !is_numeric($event['timestamp'] ?? null) || !is_array($event['data'] ?? null)
                    ) {
                        continue;
                    }
                    $events[] = $event;
                    if (count($events) >= 10000) {
                        break 2;
                    }
                }
                ++$expected;
            }
            if (count($events) >= 2 && count(array_filter($events, static fn (array $event): bool => $event['type'] === 2)) > 0) {
                usort($events, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
                $recordings[$recording] = $events;
            }
        }

        return $recordings;
    }
}
