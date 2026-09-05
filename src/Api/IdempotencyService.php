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

    public function find(string $key): ?IdempotencyRecord
    {
        $row = $this->em->find(IdempotencyRecord::class, $key);

        return $row instanceof IdempotencyRecord ? $row : null;
    }

    public function remember(string $key, Request $request, JsonResponse $response): void
    {
        $hash = hash('sha256', $request->getMethod() . '|' . $request->getPathInfo() . '|' . $request->getContent());
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
