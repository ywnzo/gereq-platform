<?php

declare(strict_types=1);

namespace Modules\Calendar\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\Calendar\Domain\CalendarConnection;

/**
 * Manages external calendar connections (Google / Seznam CalDAV / Outlook / ICS).
 * OAuth tokens are stored encrypted via Settings crypto when available.
 */
final class CalendarConnectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Settings $settings = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return [
            [
                'id' => 'google',
                'oauth' => true,
                'caldav' => false,
                'scopes' => ['https://www.googleapis.com/auth/calendar'],
                'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
            ],
            [
                'id' => 'seznam',
                'oauth' => false,
                'caldav' => true,
                'default_url' => 'https://caldav.seznam.cz/',
            ],
            [
                'id' => 'outlook',
                'oauth' => true,
                'caldav' => false,
                'scopes' => ['https://graph.microsoft.com/Calendars.ReadWrite', 'offline_access'],
                'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            ],
            [
                'id' => 'caldav',
                'oauth' => false,
                'caldav' => true,
                'default_url' => '',
            ],
            [
                'id' => 'ics',
                'oauth' => false,
                'caldav' => false,
                'ics' => true,
            ],
        ];
    }

    public function googleConfigured(): bool
    {
        return $this->setting('calendar.google.client_id') !== ''
            && $this->setting('calendar.google.client_secret') !== '';
    }

    public function createManual(
        string $provider,
        string $email,
        ?string $userId,
        ?string $caldavUrl = null,
        ?string $displayName = null,
        string $color = 'blue',
    ): CalendarConnection {
        $conn = new CalendarConnection($provider, $email, $userId);
        $conn->setDisplayName($displayName);
        $conn->setCaldavUrl($caldavUrl);
        $conn->setColor($color);
        $conn->setStatus(in_array($provider, ['seznam', 'caldav', 'ics'], true) ? 'connected' : 'pending');
        $this->em->persist($conn);
        $this->em->flush();

        return $conn;
    }

    public function googleAuthUrl(string $redirectUri, string $state): ?string
    {
        $clientId = $this->setting('calendar.google.client_id');
        if ($clientId === '') {
            return null;
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** @return array<string, mixed> */
    public function serialize(CalendarConnection $c): array
    {
        return [
            'id' => $c->id(),
            'provider' => $c->provider(),
            'account_email' => $c->accountEmail(),
            'display_name' => $c->displayName(),
            'status' => $c->status(),
            'caldav_url' => $c->caldavUrl(),
            'calendar_id' => $c->calendarId(),
            'color' => $c->color(),
            'sync_enabled' => $c->syncEnabled(),
            'last_synced_at' => $c->lastSyncedAt()?->format(\DateTimeInterface::ATOM),
            'last_error' => $c->lastError(),
        ];
    }

    private function setting(string $key): string
    {
        if ($this->settings === null) {
            return '';
        }
        $v = $this->settings->get($key);

        return is_string($v) ? trim($v) : '';
    }
}
