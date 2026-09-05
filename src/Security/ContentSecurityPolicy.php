<?php

declare(strict_types=1);

namespace Gereq\Security;

final class ContentSecurityPolicy
{
    public function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    public function header(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Inline <style> only with nonce; TipTap text-align needs style attributes.
            "style-src 'self' 'nonce-{$nonce}' https://fonts.bunny.net",
            "style-src-attr 'unsafe-inline'",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
