<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

/**
 * Normalizace hostitelů e-mailových providerů (aliasy → kanonický mail host).
 */
final class MailHostNormalizer
{
    /** @var array<string, string> */
    private const ALIASES = [
        'smtp.vas-hosting.cz' => 'mail.vas-hosting.cz',
        'imap.vas-hosting.cz' => 'mail.vas-hosting.cz',
        'pop3.vas-hosting.cz' => 'mail.vas-hosting.cz',
        'pop.vas-hosting.cz' => 'mail.vas-hosting.cz',
    ];

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        return self::ALIASES[$host] ?? $host;
    }
}
