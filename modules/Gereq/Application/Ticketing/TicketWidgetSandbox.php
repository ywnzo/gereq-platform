<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Ticketing;

use Modules\Gereq\Domain\TicketSite;

/** In-memory responses for pk_test_* keys — no DB writes. */
final class TicketWidgetSandbox
{
    /** @param array<string, mixed> $data */
    public function session(TicketSite $site, array $data): array
    {
        $token = is_string($data['visitor_token'] ?? null) && preg_match('/^[a-f0-9]{64}$/', (string) $data['visitor_token']) === 1
            ? (string) $data['visitor_token']
            : bin2hex(random_bytes(32));

        return [
            'visitor_token' => $token,
            'welcome_message' => $site->welcomeMessage(),
            'offline' => !$site->agentsOnline(),
            'primary_color' => $site->primaryColor(),
            'site_name' => $site->name(),
            'photo_url' => null,
            'ticket' => [
                'id' => 'test-ticket',
                'number' => 'SF-TEST-0001',
                'status' => 'open',
                'subject' => null,
                'visitor_name' => null,
                'visitor_email' => $data['email'] ?? null,
                'priority' => 'normal',
                'is_unread' => false,
                'rating' => null,
                'last_message_at' => null,
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            'messages' => [],
            'history' => [],
            'topics' => [],
            'echo' => null,
            'key_mode' => 'test',
        ];
    }

    /** @param array<string, mixed> $data */
    public function history(TicketSite $site, array $data): array
    {
        return [
            'email' => mb_strtolower(trim((string) ($data['email'] ?? ''))),
            'history' => [],
            'key_mode' => 'test',
        ];
    }
}
