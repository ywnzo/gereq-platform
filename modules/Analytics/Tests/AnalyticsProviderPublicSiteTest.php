<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\AnalyticsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Admin site surface contract for the HMAC secret (regression guard).
 *
 * The sites controllers render {@see AnalyticsProvider::publicSite()} into Twig, so the secret must
 * never leave the service layer: this pins the sanitiser independently of the (container-backed)
 * controller.
 */
final class AnalyticsProviderPublicSiteTest extends TestCase
{
    public function testSecretIsStrippedWhileEditableColumnsSurvive(): void
    {
        $secret = str_repeat('ab', 32);
        $site = [
            'id' => 'site-1',
            'name' => 'Example',
            'key' => 'grq_an_test',
            'domains' => ['example.com'],
            'enabled' => true,
            'timezone' => 'Europe/Prague',
            'secret' => $secret,
            'retention_override_days' => 30,
            'keep_bot_hits' => false,
            'respect_dnt' => true,
            'excluded_paths' => ['/admin'],
            'excluded_ips' => ['10.0.0.0/8'],
        ];

        $public = AnalyticsProvider::publicSite($site);

        self::assertArrayNotHasKey('secret', $public);
        self::assertStringNotContainsString($secret, (string) json_encode($public));

        // The id / key are shown in the UI and every editable column is kept for the form.
        foreach ([
            'id', 'name', 'key', 'domains', 'enabled', 'timezone',
            'retention_override_days', 'keep_bot_hits', 'respect_dnt',
            'excluded_paths', 'excluded_ips',
        ] as $key) {
            self::assertArrayHasKey($key, $public, $key . ' must stay available to the form');
        }
    }
}
