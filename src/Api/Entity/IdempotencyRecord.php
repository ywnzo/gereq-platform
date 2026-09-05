<?php

declare(strict_types=1);

namespace Gereq\Api\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_idempotency')]
class IdempotencyRecord
{
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $key;

    #[ORM\Column(name: 'request_hash', length: 64)]
    private string $requestHash;

    #[ORM\Column(name: 'response_code')]
    private int $responseCode;

    #[ORM\Column(name: 'response_body', type: 'text')]
    private string $responseBody;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $key, string $requestHash, int $responseCode, string $responseBody)
    {
        $this->key = $key;
        $this->requestHash = $requestHash;
        $this->responseCode = $responseCode;
        $this->responseBody = $responseBody;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }
}
