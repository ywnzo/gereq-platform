<?php

declare(strict_types=1);

namespace Gereq\Security;

use Gereq\View\RenderEngine;

final class ContentSecurityPolicy
{
    /** Response may set this to replace the default CSP header (removed by middleware). */
    public const HEADER_OVERRIDE = 'X-Gereq-Csp';

    /** Response may set to "1" to use X-Frame-Options: SAMEORIGIN instead of DENY. */
    public const HEADER_ALLOW_FRAME = 'X-Gereq-Allow-Frame';

    public function __construct(private readonly RenderEngine $render)
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

    public function header(string $nonce, bool $allowSameOriginFrame = false): string
    {
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        // Nonce in style-src makes Chromium ignore style-src-attr and block
        // Alpine/admin CSSOM (element.style). Scripts stay nonce-based.
        $styleSrc = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
        $connectSrc = ["'self'"];

        foreach ($this->render->cspOrigins() as $origin) {
            $scriptSrc[] = $origin;
            $styleSrc[] = $origin;
            $connectSrc[] = $origin;
        }

        $frameSrc = ["'self'", 'https://gereq.cz', ...$this->render->cspOrigins()];
        foreach (explode(',', (string) env_value('FRONTEND_ORIGIN', '')) as $origin) {
            $origin = trim($origin);
            if ($origin !== '') {
                $frameSrc[] = $origin;
            }
        }

        $directives = [
            "default-src 'self'",
            'script-src ' . implode(' ', array_unique([...$scriptSrc, 'https://cdn.jsdelivr.net'])),
            'style-src ' . implode(' ', array_unique($styleSrc)),
            "style-src-attr 'unsafe-inline'",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            'connect-src ' . implode(' ', array_unique([...$connectSrc, 'https://cdn.jsdelivr.net'])),
            'frame-src ' . implode(' ', array_unique($frameSrc)),
            $allowSameOriginFrame ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
