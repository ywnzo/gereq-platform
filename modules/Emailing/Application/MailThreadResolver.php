<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

/**
 * Outlook-like konverzace: root Message-ID z References / In-Reply-To, jinak předmět.
 */
final class MailThreadResolver
{
    public static function normalizeId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        // může být více ID v In-Reply-To
        if (preg_match_all('/<([^>]+)>/', $id, $m) > 0) {
            $id = (string) $m[1][0];
        } else {
            $id = trim($id, "<> \t\"'");
        }

        return $id !== '' ? $id : null;
    }

    /**
     * @return list<string>
     */
    public static function parseIdList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/<([^>]+)>/', $raw, $m) > 0) {
            foreach ($m[1] as $id) {
                $n = self::normalizeId($id);
                if ($n !== null) {
                    $out[] = $n;
                }
            }
        } else {
            foreach (preg_split('/\s+/', $raw) ?: [] as $part) {
                $n = self::normalizeId($part);
                if ($n !== null) {
                    $out[] = $n;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function normalizeSubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ($s === '') {
            return '';
        }
        do {
            $prev = $s;
            $s = (string) preg_replace(
                '/^(?:(?:re|fw|fwd|aw|sv|odp|antw|wg|tr|rv)\s*(?:\[[\d]+\]\s*)?:\s*)+/iu',
                '',
                $s,
            );
            $s = trim($s);
        } while ($s !== $prev);

        return $s;
    }

    /**
     * @param list<string> $references
     */
    public static function resolveThreadId(
        ?string $messageId,
        ?string $inReplyTo,
        array $references,
        ?string $subject,
        string $accountId,
        ?string $parentThreadId = null,
    ): string {
        if ($parentThreadId !== null && $parentThreadId !== '') {
            return self::clampThreadId($parentThreadId);
        }

        $refs = [];
        foreach ($references as $ref) {
            $n = self::normalizeId($ref);
            if ($n !== null) {
                $refs[] = $n;
            }
        }
        $irt = self::normalizeId($inReplyTo);
        $mid = self::normalizeId($messageId);

        if ($refs !== []) {
            return self::midKey($refs[0]);
        }
        if ($irt !== null) {
            return self::midKey($irt);
        }
        if ($mid !== null) {
            return self::midKey($mid);
        }

        $subj = mb_strtolower(self::normalizeSubject($subject));
        if ($subj !== '') {
            return 'subj:' . substr(hash('sha256', $accountId . "\0" . $subj), 0, 40);
        }

        return 'orphan:' . substr(hash('sha256', $accountId . "\0" . uniqid('', true)), 0, 40);
    }

    /** Stabilní krátký klíč — Message-ID bývá delší než DB sloupec. */
    public static function midKey(string $messageId): string
    {
        $id = self::normalizeId($messageId) ?? $messageId;

        return 'mid:' . substr(hash('sha256', strtolower($id)), 0, 40);
    }

    public static function clampThreadId(string $threadId): string
    {
        if (strlen($threadId) <= 120) {
            return $threadId;
        }

        return 'x:' . substr(hash('sha256', $threadId), 0, 40);
    }

    /**
     * @param list<string> $references
     */
    public static function buildReferencesHeader(?string $existingReferences, ?string $parentMessageId): ?string
    {
        $ids = self::parseIdList($existingReferences);
        $parent = self::normalizeId($parentMessageId);
        if ($parent !== null && !in_array($parent, $ids, true)) {
            $ids[] = $parent;
        }
        if ($ids === []) {
            return null;
        }

        return implode(' ', array_map(static fn (string $id): string => '<' . $id . '>', $ids));
    }
}
