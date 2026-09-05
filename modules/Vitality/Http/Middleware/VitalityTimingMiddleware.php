<?php

declare(strict_types=1);

namespace Modules\Vitality\Http\Middleware;

use Gereq\Debug\QueryCollector;
use Gereq\Http\Middleware\MiddlewareInterface;
use Gereq\Module\ModuleManager;
use Modules\Vitality\Application\VitalityMonitor;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VitalityTimingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ContainerInterface $container,
        private readonly QueryCollector $queries,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->modules->isEnabled('vitality')) {
            return $next($request);
        }

        $queryOffset = $this->queries->count();
        $started = hrtime(true);
        $response = $next($request);
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        try {
            /** @var VitalityMonitor $monitor */
            $monitor = $this->container->get(VitalityMonitor::class);
            $monitor->record($request, $response, $durationMs, $queryOffset);
            $response->headers->set('X-Response-Time', $durationMs . 'ms');
        } catch (\Throwable) {
            // Monitoring must never break the API response.
        }

        return $response;
    }
}
