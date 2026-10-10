<?php

declare(strict_types=1);

namespace Gereq\Queue;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Persistence for the failed_jobs audit trail. The queue admin UI and
 * queue:failed CLI talk only through this store — never the table directly.
 */
final class FailedJobStore
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('f')
            ->from(FailedJob::class, 'f');
    }

    public function find(string $idOrUuid): ?FailedJob
    {
        $byId = $this->em->find(FailedJob::class, $idOrUuid);
        if ($byId instanceof FailedJob) {
            return $byId;
        }

        $byUuid = $this->em->getRepository(FailedJob::class)->findOneBy(['uuid' => $idOrUuid]);

        return $byUuid instanceof FailedJob ? $byUuid : null;
    }

    /**
     * @return list<FailedJob>
     */
    public function recent(int $limit = 20): array
    {
        /** @var list<FailedJob> $rows */
        $rows = $this->createQueryBuilder()
            ->orderBy('f.failedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function count(): int
    {
        return (int) $this->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countExhausted(int $maxAttempts): int
    {
        return (int) $this->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->andWhere('f.attempts >= :max')
            ->setParameter('max', max(1, $maxAttempts))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Upsert a failure row for this payload. First failure creates the row;
     * later failures bump attempts on the same row so the sidebar can tell
     * "still retrying" from "gave up".
     */
    public function recordFailure(
        string $payload,
        string $exception,
        string $job = '',
        string $queue = 'default',
        string $uuid = '',
    ): FailedJob {
        $hash = hash('sha256', $payload);
        $existing = $this->em->getRepository(FailedJob::class)->findOneBy(['payloadHash' => $hash]);

        if ($existing instanceof FailedJob) {
            $existing->registerAttempt($exception, $job);
            $this->em->flush();

            return $existing;
        }

        $row = new FailedJob($payload, $exception, $queue, $job, $uuid, 1);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function forgetByPayload(string $payload): bool
    {
        $hash = hash('sha256', $payload);
        $existing = $this->em->getRepository(FailedJob::class)->findOneBy(['payloadHash' => $hash]);
        if (!$existing instanceof FailedJob) {
            return false;
        }

        return $this->forget($existing);
    }

    public function forget(FailedJob $failedJob): bool
    {
        $managed = $this->em->contains($failedJob)
            ? $failedJob
            : $this->em->find(FailedJob::class, $failedJob->id());

        if (!$managed instanceof FailedJob) {
            return false;
        }

        $this->em->remove($managed);
        $this->em->flush();

        return true;
    }

    public function prune(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<FailedJob> $rows */
        $rows = $this->createQueryBuilder()
            ->andWhere('f.failedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $this->em->remove($row);
        }

        if ($rows !== []) {
            $this->em->flush();
        }

        return count($rows);
    }

    /**
     * Push the stored payload back onto the queue. Returns false when the
     * payload cannot be turned into a Job again.
     */
    public function requeue(FailedJob $failedJob, Queue $queue): bool
    {
        $job = $queue->unserializePayload($failedJob->payload());
        if (!$job instanceof Job) {
            return false;
        }

        $queue->push($job);

        return true;
    }
}
