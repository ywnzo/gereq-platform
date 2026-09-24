<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Public;

use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class EmbedScriptController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function security(Request $request): Response
    {
        return $this->js('gereq-security.js');
    }

    public function monitor(Request $request): Response
    {
        return $this->js('gereq-monitor.js');
    }

    public function tickets(Request $request): Response
    {
        return $this->js('gereq-tickets.js');
    }

    private function js(string $file): Response
    {
        $path = dirname(__DIR__, 2) . '/Resources/assets/js/' . $file;
        if (!is_file($path)) {
            return new Response('// missing', 404, ['Content-Type' => 'application/javascript; charset=UTF-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
