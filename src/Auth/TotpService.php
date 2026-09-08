<?php

declare(strict_types=1);

namespace Gereq\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Gereq\Config\Repository as Config;
use Gereq\User\Entity\User;
use OTPHP\TOTP;

final class TotpService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, User $user): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($user->email());
        $issuer = (string) $this->config->get('app.name', 'GEREQ');
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    public function qrDataUri(string $uri, int $size = 200): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        );
        $svg = (new Writer($renderer))->writeString($uri);
        // Strip XML prologue — some browsers reject it inside data: URIs.
        $svg = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $svg) ?? $svg;

        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $totp = TOTP::create($secret);

        return $totp->verify($code, null, 1);
    }

    /** @return list<string> Plain recovery codes (show once). */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    /** @param list<string> $plainCodes @return list<string> */
    public function hashRecoveryCodes(array $plainCodes): array
    {
        return array_values(array_map(
            static fn (string $code): string => password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT),
            $plainCodes,
        ));
    }
}
