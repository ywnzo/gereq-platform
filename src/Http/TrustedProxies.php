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

        // TRUSTED_PROXIES=* is convenient behind a known edge proxy in Docker/k8s.
        if ($proxies === ['*']) {
            $proxies = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        }

        Request::setTrustedProxies(
            $proxies,
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
    }
}
