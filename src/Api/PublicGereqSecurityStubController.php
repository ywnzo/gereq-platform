<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Http\PublicCors;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Disabled Gereq security payload when the Gereq module is off.
 * Shape mirrors Modules\Gereq\Application\GereqClientConfig::securityPublicPayload().
 */
final class PublicGereqSecurityStubController
{
    private const SECURITY_SCRIPT = 'https://gereq.cz/js/gereq-security.js';
    private const MONITOR_SCRIPT = 'https://gereq.cz/js/gereq-monitor.js';
    private const DEFAULT_COLOR = '#173E2D';

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, OPTIONS');
    }

    public function security(Request $request): JsonResponse
    {
        $response = new JsonResponse([
            'enabled' => false,
            'public_key' => '',
            'script_url' => self::SECURITY_SCRIPT,
            'monitor_script_url' => self::MONITOR_SCRIPT,
            'color' => self::DEFAULT_COLOR,
            'theme' => 'light',
            'layout' => 'row',
            'auto' => false,
            'captcha_always' => false,
            'debug' => false,
            'monitor' => [
                'preset' => 'corner',
                'position' => 'br',
                'mobile_shown' => false,
                'color' => self::DEFAULT_COLOR,
                'label' => '',
                'sub' => '',
                'badge' => true,
            ],
        ]);

        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
