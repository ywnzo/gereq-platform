<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Domain\AiUsageDaily;

final class UsageTracker
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function record(
        string $providerId,
        string $modelId,
        string $surface,
        ?string $agentId,
        int $tokensIn,
        int $tokensOut,
        int $requests = 1,
    ): void {
        $day = new \DateTimeImmutable('today');
        $qb = $this->em->getRepository(AiUsageDaily::class)->createQueryBuilder('u');
        $qb->andWhere('u.day = :day')->setParameter('day', $day)
            ->andWhere('u.providerId = :p')->setParameter('p', $providerId)
            ->andWhere('u.modelId = :m')->setParameter('m', $modelId)
            ->andWhere('u.surface = :s')->setParameter('s', $surface)
            ->setMaxResults(1);
        if ($agentId === null) {
            $qb->andWhere('u.agentId IS NULL');
        } else {
            $qb->andWhere('u.agentId = :a')->setParameter('a', $agentId);
        }
        $row = $qb->getQuery()->getOneOrNullResult();
        if (!$row instanceof AiUsageDaily) {
            $row = new AiUsageDaily($day, $providerId, $modelId, $surface, $agentId);
            $this->em->persist($row);
        }
        $row->add($requests, $tokensIn, $tokensOut);
        $this->em->flush();
    }
}
