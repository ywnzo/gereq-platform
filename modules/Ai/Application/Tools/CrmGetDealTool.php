<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;

final class CrmGetDealTool implements AiTool
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'crm.get_deal';
    }

    public function description(): string
    {
        return 'Get CRM deal details by id.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'required' => ['id'],
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
        if (!class_exists(Deal::class)) {
            return AiToolResult::fail('CRM not available');
        }
        $deal = $this->em->find(Deal::class, (string) ($args['id'] ?? ''));
        if (!$deal instanceof Deal) {
            return AiToolResult::fail('Deal not found');
        }

        return AiToolResult::ok([
            'id' => $deal->id(),
            'title' => $deal->title(),
            'stage' => $deal->stage(),
            'customer_id' => $deal->customerId(),
            'amount' => $deal->amount(),
            'currency' => $deal->currency(),
        ]);
    }
}

