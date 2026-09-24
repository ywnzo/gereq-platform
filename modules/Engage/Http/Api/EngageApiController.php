<?php

declare(strict_types=1);

namespace Modules\Engage\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Modules\Engage\Application\EngageProvider;
use Modules\Engage\Domain\Campaign;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class EngageApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EngageProvider $engage,
    ) {
    }

    public function campaigns(): Response
    {
        /** @var list<Campaign> $items */
        $items = $this->em->getRepository(Campaign::class)->findBy([], ['name' => 'ASC'], 500);
        $out = [];
        foreach ($items as $item) {
            $out[] = $this->engage->serializeCampaign($item);
        }

        return ApiResponse::data(['items' => $out]);
    }

    public function campaign(string $slug): Response
    {
        $item = $this->engage->findCampaignBySlug($slug);
        if ($item === null) {
            return ApiResponse::error('not_found', 'Campaign not found.', [], 404);
        }

        return ApiResponse::data($this->engage->serializeCampaign($item));
    }

    public function spots(Request $request): Response
    {
        $key = trim((string) $request->query->get('placement', ''));

        return ApiResponse::data([
            'items' => $this->engage->activeSpots($key !== '' ? $key : null),
        ]);
    }
}
