<?php

declare(strict_types=1);

namespace Gereq\Security;

use Gereq\View\AssetManager;

final class ContentSecurityPolicy
{
    /** Response may set this to replace the default CSP header (removed by middleware). */
    public const HEADER_OVERRIDE = 'X-Gereq-Csp';

    /** Response may set to "1" to use X-Frame-Options: SAMEORIGIN instead of DENY. */
    public const HEADER_ALLOW_FRAME = 'X-Gereq-Allow-Frame';

    public function __construct(private readonly AssetManager $assets)
    {
    }

    public function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * CSP for HTML e-mail preview iframes (inline styles/images, no scripts).
     */
    public function emailPreviewHeader(): string
    {
        return implode('; ', [
            "default-src 'none'",
            "script-src 'none'",
            "style-src 'unsafe-inline'",
            "img-src data: blob: https: http:",
            "font-src data: https: http:",
            "media-src data: https: http:",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'self'",
        ]);
    }

    public function header(string $nonce): string
    {
        $hot = $this->assets->isHot();

        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];

        // Vite injects <style> without a nonce. If style-src also has a nonce,
        // browsers ignore 'unsafe-inline' and HMR CSS is blocked → unstyled admin.
        if ($hot) {
            $styleSrc = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
            $scriptSrc[] = "'unsafe-eval'";
        } else {
            $styleSrc = ["'self'", "'nonce-{$nonce}'", 'https://fonts.bunny.net'];
        }

        foreach ($this->assets->hotOriginsForCsp() as $origin) {
            $scriptSrc[] = $origin;
            $connectSrc[] = $origin;
            $ws = preg_replace('#^http#i', 'ws', $origin);
            if (is_string($ws) && $ws !== '') {
                $connectSrc[] = $ws;
            }
        }

        $directives = [
            "default-src 'self'",
            'script-src ' . implode(' ', array_unique([...$scriptSrc, 'https://cdn.jsdelivr.net'])),
            'style-src ' . implode(' ', array_unique($styleSrc)),
            "style-src-attr 'unsafe-inline'",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob: https:",
            'connect-src ' . implode(' ', array_unique([...$connectSrc, 'https://cdn.jsdelivr.net'])),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
