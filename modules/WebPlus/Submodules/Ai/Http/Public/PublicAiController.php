<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Modules\WebPlus\Submodules\Ai\Application\AiProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicAiController
{
    public function __construct(private readonly AiProvider $provider)
    {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    /** Open discovery catalog (no API key) — WebMCP / agent readiness. */
    public function toolsJson(Request $request): Response
    {
        $this->provider->ensureDefaults();
        $body = json_encode(
            $this->provider->catalog(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $this->cors(new Response($body, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
        ]), $request);
    }

    /** Same catalog wrapped in GEREQ API envelope (public key / first-party). */
    public function tools(Request $request): Response
    {
        $this->provider->ensureDefaults();

        return $this->cors(ApiResponse::data($this->provider->catalog()), $request);
    }

    /** Informal /ai.txt discovery (Agent Readiness panel). */
    public function aiTxt(Request $request): Response
    {
        $this->provider->ensureDefaults();

        return $this->cors(new Response($this->provider->aiTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
        ]), $request);
    }

    /** Expanded llms context with embedded tool schemas. */
    public function llmsFullTxt(Request $request): Response
    {
        $this->provider->ensureDefaults();

        return $this->cors(new Response($this->provider->llmsFullTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
        ]), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
