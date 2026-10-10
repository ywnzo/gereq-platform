<?php

declare(strict_types=1);

namespace Gereq\Queue;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Gereq\Schedule\RunScheduledCommandJob;
use Throwable;

/**
 * Audit trail of job executions (worker / sync / schedule).
 */
final class JobRunStore
{
    private const PAYLOAD_MAX = 8192;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(JobRun::class, 'r');
    }

    public function find(string $id): ?JobRun
    {
        $row = $this->em->find(JobRun::class, $id);

        return $row instanceof JobRun ? $row : null;
    }

    public function recordJob(
        Job $job,
        string $status,
        string $trigger,
        DateTimeImmutable $startedAt,
        int $durationMs,
        ?Throwable $error = null,
        ?string $rawPayload = null,
    ): JobRun {
        $row = new JobRun(
            job: $this->labelFor($job),
            status: $status,
            trigger: $trigger,
            startedAt: $startedAt,
            finishedAt: new DateTimeImmutable(),
            durationMs: $durationMs,
            exception: $error !== null ? $this->formatException($error) : null,
            payload: $this->truncatePayload($rawPayload),
        );

        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function recordSkip(
        string $job,
        string $trigger,
        string $reason,
    ): JobRun {
        return $this->recordNamed(
            $job,
            JobRun::STATUS_SKIPPED,
            $trigger,
            new DateTimeImmutable(),
            0,
            $reason,
        );
    }

    public function recordNamed(
        string $job,
        string $status,
        string $trigger,
        DateTimeImmutable $startedAt,
        int $durationMs,
        ?string $exception = null,
    ): JobRun {
        $row = new JobRun(
            job: $job,
            status: $status,
            trigger: $trigger,
            startedAt: $startedAt,
            finishedAt: new DateTimeImmutable(),
            durationMs: max(0, $durationMs),
            exception: $exception,
        );

        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);
        $before = new DateTimeImmutable(sprintf('-%d days', $days));

        return (int) $this->em->createQueryBuilder()
            ->delete(JobRun::class, 'r')
            ->where('r.startedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    private function labelFor(Job $job): string
    {
        if ($job instanceof RunScheduledCommandJob) {
            return 'schedule:' . $job->commandName();
        }

        return $job::class;
    }

    private function formatException(Throwable $error): string
    {
        $text = $error::class . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString();
        if (strlen($text) > 20000) {
            return substr($text, 0, 20000) . "\n…";
        }

        return $text;
    }

    private function truncatePayload(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (strlen($payload) <= self::PAYLOAD_MAX) {
            return $payload;
        }

        return substr($payload, 0, self::PAYLOAD_MAX) . "\n…";
    }
}
