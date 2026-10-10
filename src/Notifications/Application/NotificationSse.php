<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Notifications\Domain\NotificationStreamToken;

final class NotificationSse
{
    public const TOKEN_LENGTH = 32;
    /** Short-lived: stream tokens may still appear in URLs for legacy clients. */
    public const TOKEN_TTL = 900;
    public const STREAM_SECONDS = 1500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemberNotifier $members,
    ) {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, expires_at: string} */
    public function issue(string $accountId): array
    {
        $this->purgeExpired();
        $plain = bin2hex(random_bytes(16));
        $expires = (new \DateTimeImmutable())->modify('+' . self::TOKEN_TTL . ' seconds');
        $this->em->persist(new NotificationStreamToken($accountId, hash('sha256', $plain), $expires));
        $this->em->flush();

        return [
            'access_token' => $plain,
            'token_type' => 'sse',
            'expires_in' => self::TOKEN_TTL,
            'expires_at' => $expires->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function looksLikeToken(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $token);
    }

    public function accountIdFromToken(string $token): ?string
    {
        if (!self::looksLikeToken($token)) {
            return null;
        }
        $row = $this->em->getRepository(NotificationStreamToken::class)->findOneBy([
            'tokenHash' => hash('sha256', $token),
        ]);
        if (!$row instanceof NotificationStreamToken || $row->isExpired()) {
            return null;
        }

        return $row->accountId();
    }

    public function stream(string $accountId, string $sinceId): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->emit('ready', ['ok' => true]);
        $deadline = time() + self::STREAM_SECONDS;
        $lastPing = time();
        $cursor = $sinceId;
        $started = new \DateTimeImmutable();

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }
            foreach ($this->members->inboxSince($accountId, $cursor, $started) as $item) {
                $cursor = (string) $item['id'];
                $this->emit('notification', [
                    'item' => $item,
                    'unread' => $this->members->unreadCount($accountId),
                ], $cursor);
            }
            $this->em->clear();
            if (time() - $lastPing >= 15) {
                echo ": ping\n\n";
                $this->flush();
                $lastPing = time();
            }
            usleep(800000);
        }

        $this->emit('reconnect', ['ok' => true]);
    }

    /** @param array<string, mixed> $data */
    private function emit(string $event, array $data, ?string $id = null): void
    {
        if ($id !== null && $id !== '') {
            echo 'id: ' . $id . "\n";
        }
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function purgeExpired(): void
    {
        $this->em->createQueryBuilder()
            ->delete(NotificationStreamToken::class, 't')
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
