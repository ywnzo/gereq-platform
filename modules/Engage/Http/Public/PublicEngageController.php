<?php

declare(strict_types=1);

namespace Modules\Engage\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Modules\Engage\Application\EngageProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicEngageController
{
    public function __construct(
        private readonly EngageProvider $engage,
    ) {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function campaign(Request $request): Response
    {
        $slug = trim((string) $request->query->get('mkind', $request->query->get('slug', '')));
        if ($slug === '') {
            return $this->cors(ApiResponse::error('validation_error', 'Query param mkind (or slug) is required.', [
                'mkind' => 'required',
            ], 422), $request);
        }

        $campaign = $this->engage->findCampaignBySlug($slug);
        if ($campaign === null || !$campaign->isActive()) {
            return $this->cors(ApiResponse::error('not_found', 'Campaign not found.', [], 404), $request);
        }

        $touchRaw = strtolower(trim((string) $request->query->get('touch', '1')));
        $touch = !in_array($touchRaw, ['0', 'false', 'no', 'off'], true);

        return $this->cors(ApiResponse::data($this->engage->serializeCampaign($campaign, $touch)), $request);
    }

    public function spots(Request $request): Response
    {
        $key = trim((string) $request->query->get('placement', ''));

        return $this->cors(ApiResponse::data([
            'items' => $this->engage->activeSpots($key !== '' ? $key : null),
        ]), $request);
    }

    public function spot(Request $request): Response
    {
        $key = trim((string) $request->query->get('placement', ''));
        if ($key === '') {
            return $this->cors(ApiResponse::error('validation_error', 'Query param placement is required.', [
                'placement' => 'required',
            ], 422), $request);
        }

        $spot = $this->engage->findSpotByKey($key);
        if ($spot === null || !$spot->isActive()) {
            return $this->cors(ApiResponse::error('not_found', 'Spot not found.', [], 404), $request);
        }

        return $this->cors(ApiResponse::data($this->engage->serializeSpot($spot)), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request);
    }
}
