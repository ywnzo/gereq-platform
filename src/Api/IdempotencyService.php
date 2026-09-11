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
