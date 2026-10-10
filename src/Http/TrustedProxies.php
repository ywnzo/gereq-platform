<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\Request;

final class TrustedProxies
{
    public static function configure(Request $request): void
    {
        $raw = trim((string) env_value('TRUSTED_PROXIES', ''));
        if ($raw === '') {
            return;
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($proxies === []) {
            return;
        }

        // TRUSTED_PROXIES=* must not trust every private peer — a direct hit on PHP-FPM
        // from another container could then spoof X-Forwarded-For and bypass rate limits.
        // Prefer an explicit nginx/edge CIDR. Debug may widen to RFC1918 for local Docker.
        if ($proxies === ['*']) {
            $proxies = ['127.0.0.1', '::1'];
            if ((bool) env_value('APP_DEBUG', false)) {
                $proxies = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
            }
        }

        Request::setTrustedProxies(
            $proxies,
            // Host/port forwarding is unused for rate-limit IP; keep FORWARDED_FOR + PROTO only.
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO,
        );
    }
}
