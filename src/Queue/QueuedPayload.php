<?php

declare(strict_types=1);

namespace Gereq\Queue;

/**
 * Wire format helpers for queue storage.
 *
 * Immediate jobs stay as plain PHP serialize() for backward compatibility.
 * Delayed entries are stored separately (Redis ZSET / delayed files) and promoted
 * into the ready queue when due — the worker always receives a plain Job blob.
 */
final class QueuedPayload
{
    /**
     * @return array{id: string, body: string}
     */
    public static function delayedMember(string $serializedJob): array
    {
        $id = bin2hex(random_bytes(8));

        return [
            'id' => $id,
            'body' => $serializedJob,
        ];
    }

    public static function encodeDelayedMember(string $serializedJob): string
    {
        $member = self::delayedMember($serializedJob);

        return json_encode($member, JSON_THROW_ON_ERROR);
    }

    public static function decodeDelayedMember(string $raw): ?string
    {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['body']) || !is_string($decoded['body'])) {
            return null;
        }

        return $decoded['body'];
    }
}
