<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

final class WebhookSigner
{
    public function sign(string $secret, string $timestamp, string $rawBody): string
    {
        $payload = $timestamp . '.' . $rawBody;

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function verify(string $secret, string $timestamp, string $rawBody, string $signature): bool
    {
        $expected = $this->sign($secret, $timestamp, $rawBody);

        return hash_equals($expected, $signature);
    }
}
