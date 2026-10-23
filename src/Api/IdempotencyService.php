<?php

declare(strict_types=1);

namespace Gereq\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\Entity\IdempotencyRecord;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class IdempotencyService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Namespace a client key to the current API actor so keys cannot be shared across clients. */
    public function actorKey(string $clientKey, Request $request): string
    {
        $actor = $request->attributes->get('_api_actor');
        $actorPart = 'anon';
        if (is_array($actor)) {
            $actorPart = (string) ($actor['type'] ?? 'anon') . ':' . (string) ($actor['id'] ?? '');
        }

        return hash('sha256', $actorPart . '|' . $clientKey);
    }

    public function requestHash(Request $request): string
    {
        return hash('sha256', $request->getMethod() . '|' . $request->getPathInfo() . '|' . $request->getContent());
    }

    public function find(string $key): ?IdempotencyRecord
    {
        $row = $this->em->find(IdempotencyRecord::class, $key);

        return $row instanceof IdempotencyRecord ? $row : null;
    }

    /** Claim a unique key inside the caller's transaction before performing any mutation. */
    public function claim(string $key, Request $request, JsonResponse $response): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Idempotency claims require an active transaction.');
        }
        // Use the database unique constraint, including when another worker already committed.
        $this->em->getConnection()->insert('api_idempotency', [
            $this->em->getConnection()->quoteIdentifier('key') => $key, 'request_hash' => $this->requestHash($request),
            'response_code' => $response->getStatusCode(), 'response_body' => (string) $response->getContent(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function remember(string $key, Request $request, JsonResponse $response): void
    {
        $hash = $this->requestHash($request);
        $existing = $this->find($key);
        if ($existing !== null) {
            return;
        }

        $this->em->persist(new IdempotencyRecord(
            $key,
            $hash,
            $response->getStatusCode(),
            (string) $response->getContent(),
        ));
        $this->em->flush();
    }

    public function replay(IdempotencyRecord $record): JsonResponse
    {
        $data = json_decode($record->responseBody(), true);

        return new JsonResponse(is_array($data) ? $data : ['data' => null], $record->responseCode());
    }
}
