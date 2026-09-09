<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;

final class CrmListActivitiesTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'crm.list_activities';
    }

    public function description(): string
    {
        return 'List recent CRM activities for a deal.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'deal_id' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['deal_id'],
        ];
    }

    public function permission(): ?string
    {
        return 'crm.view';
    }

    public function surfaces(): array
    {
        return ['admin'];
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        if (!class_exists(Activity::class)) {
            return AiToolResult::fail('CRM activities not available');
        }
        $dealId = (string) ($args['deal_id'] ?? '');
        $limit = min(30, max(1, (int) ($args['limit'] ?? 10)));
        $rows = $this->em->getRepository(Activity::class)->createQueryBuilder('a')
            ->andWhere('a.dealId = :d')->setParameter('d', $dealId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $a) {
            $out[] = [
                'id' => method_exists($a, 'id') ? $a->id() : null,
                'type' => method_exists($a, 'type') ? $a->type() : null,
                'subject' => method_exists($a, 'subject') ? $a->subject() : (method_exists($a, 'title') ? $a->title() : null),
                'created_at' => method_exists($a, 'createdAt') ? $a->createdAt()->format(DATE_ATOM) : null,
            ];
        }

        return AiToolResult::ok($out);
    }
}
