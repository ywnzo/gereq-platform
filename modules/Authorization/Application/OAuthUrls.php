<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

use Gereq\Http\PublicUrl;
use Symfony\Component\HttpFoundation\Request;

final class OAuthUrls
{
    public function callbackUrl(Request $request, string $provider): string
    {
        $candidates = [
            PublicUrl::app(),
            rtrim($request->getSchemeAndHttpHost(), '/'),
            PublicUrl::frontend(),
        ];
        $base = '';
        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim((string) $candidate), '/');
            if ($candidate !== '' && !PublicUrl::isLoopback($candidate)) {
                $base = $candidate;
                break;
            }
        }
        if ($base === '') {
            $base = rtrim(PublicUrl::app() ?: $request->getSchemeAndHttpHost(), '/');
        }

        return $base . '/api/public/auth/' . rawurlencode($provider) . '/callback';
    }

    public function frontendOrigin(): string
    {
        return PublicUrl::frontend();
    }
}
