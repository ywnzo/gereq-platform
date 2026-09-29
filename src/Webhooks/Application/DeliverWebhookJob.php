<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Gereq\Queue\Job;
use Gereq\Webhooks\Domain\Webhook;
use Gereq\Webhooks\Domain\WebhookDelivery;

final class DeliverWebhookJob implements Job
{
    /** Delays in seconds for attempts 2–6 (attempt 1 is immediate). */
    private const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    public function __construct(private readonly string $deliveryId)
    {
    }

    public function handle(): void
    {
        $em = gereq()->make(EntityManagerInterface::class);
        $signer = gereq()->make(WebhookSigner::class);
        $ssrf = gereq()->make(SsrfGuard::class);
        $config = gereq()->make(Repository::class);

        $delivery = $em->find(WebhookDelivery::class, $this->deliveryId);
        if (!$delivery instanceof WebhookDelivery) {
            return;
        }

        $webhook = $em->find(Webhook::class, $delivery->webhookId());
        if (!$webhook instanceof Webhook) {
            $delivery->markFailed(null, [], 'Webhook už neexistuje.', 0);
            $em->flush();

            return;
        }

        try {
            $ssrf->assertSafe($webhook->url());
        } catch (\InvalidArgumentException $e) {
            $delivery->markFailed(null, [], $e->getMessage(), 0);
            $webhook->markFailure();
            $this->maybePause($webhook, $config);
            $em->flush();

            return;
        }

        $timestamp = (string) time();
        $body = $delivery->requestBody();
        $signature = $signer->sign($webhook->secret(), $timestamp, $body);

        $headers = array_merge($delivery->requestHeaders(), [
            'X-GEREQ-Event' => $delivery->eventType(),
            'X-GEREQ-Delivery' => $delivery->id(),
            'X-GEREQ-Timestamp' => $timestamp,
            'X-GEREQ-Signature' => $signature,
            'Content-Type' => 'application/json',
            'User-Agent' => 'GEREQ-Webhooks/1.0',
        ]);

        // Persist headers without the signature secret material beyond the signature itself.
        $safeHeaders = $headers;
        $delivery->setRequestHeaders($safeHeaders);

        $started = hrtime(true);
        $result = $this->send($webhook->url(), $headers, $body, $webhook->timeoutSeconds());
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        if ($result['network_error']) {
            $this->handleFailure(
                $em,
                $webhook,
                $delivery,
                null,
                [],
                $result['error'] ?? 'Síťová chyba při doručení webhooku.',
                $durationMs,
                true,
                $config,
            );

            return;
        }

        $status = $result['status'] ?? 0;
        $responseHeaders = $result['headers'] ?? [];
        $responseBody = $result['body'] ?? '';

        if ($status >= 200 && $status < 300) {
            $delivery->markDelivered($status, $responseHeaders, $responseBody, $durationMs);
            $webhook->markSuccess();
            $em->flush();

            return;
        }

        $retryable = $this->isRetryable($status, false);
        $this->handleFailure(
            $em,
            $webhook,
            $delivery,
            $status,
            $responseHeaders,
            $responseBody !== '' ? $responseBody : 'HTTP ' . $status,
            $durationMs,
            $retryable,
            $config,
        );
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    private function handleFailure(
        EntityManagerInterface $em,
        Webhook $webhook,
        WebhookDelivery $delivery,
        ?int $status,
        array $responseHeaders,
        string $responseBody,
        int $durationMs,
        bool $retryable,
        Repository $config,
    ): void {
        $attempt = $delivery->attempt();
        $delayIndex = $attempt - 1;

        $delivery->recordResponse($status, $responseHeaders, $responseBody, $durationMs);
        $webhook->markFailure();

        if ($retryable && isset(self::RETRY_DELAYS[$delayIndex]) && $attempt < 6) {
            $delivery->scheduleRetry(self::RETRY_DELAYS[$delayIndex]);
        } else {
            $delivery->markFailed($status, $responseHeaders, $responseBody, $durationMs);
        }

        $this->maybePause($webhook, $config);
        $em->flush();
    }

    private function maybePause(Webhook $webhook, Repository $config): void
    {
        $max = (int) $config->get('api.webhooks.max_consecutive_failures', 50);
        if ($max > 0 && $webhook->consecutiveFailures() >= $max) {
            $webhook->pause();
        }
    }

    private function isRetryable(int $status, bool $networkError): bool
    {
        if ($networkError) {
            return true;
        }

        if (in_array($status, [408, 425, 429], true)) {
            return true;
        }

        if ($status >= 500 && $status <= 599) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     * @return array{network_error: bool, status: ?int, headers: array<string, string>, body: string, error: ?string}
     */
    private function send(string $url, array $headers, string $body, int $timeout): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            return $this->sendCurl($url, $headerLines, $body, $timeout);
        }

        return $this->sendStream($url, $headerLines, $body, $timeout);
    }

    /**
     * @param list<string> $headerLines
     * @return array{network_error: bool, status: ?int, headers: array<string, string>, body: string, error: ?string}
     */
    private function sendCurl(string $url, array $headerLines, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'network_error' => true,
                'status' => null,
                'headers' => [],
                'body' => '',
                'error' => 'Nelze inicializovat HTTP klient.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTP') ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : 3,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            $isTimeout = in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28], true);

            return [
                'network_error' => true,
                'status' => null,
                'headers' => [],
                'body' => '',
                'error' => $isTimeout ? 'Vypršel timeout doručení.' : ($error !== '' ? $error : 'Síťová chyba.'),
            ];
        }

        $raw = (string) $raw;
        $headerBlob = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return [
            'network_error' => false,
            'status' => $status,
            'headers' => $this->parseHeaderBlob($headerBlob),
            'body' => (string) $responseBody,
            'error' => null,
        ];
    }

    /**
     * @param list<string> $headerLines
     * @return array{network_error: bool, status: ?int, headers: array<string, string>, body: string, error: ?string}
     */
    private function sendStream(string $url, array $headerLines, string $body, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        /** @var list<string> $http_response_header */
        $responseHeaders = $http_response_header ?? [];

        if ($responseBody === false && $responseHeaders === []) {
            return [
                'network_error' => true,
                'status' => null,
                'headers' => [],
                'body' => '',
                'error' => 'Síťová chyba nebo timeout při doručení.',
            ];
        }

        $status = 0;
        $parsed = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $line, $m)) {
                $status = (int) $m[1];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $parsed[$name] = $value;
            }
        }

        return [
            'network_error' => false,
            'status' => $status > 0 ? $status : null,
            'headers' => $parsed,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => null,
        ];
    }

    /** @return array<string, string> */
    private function parseHeaderBlob(string $blob): array
    {
        $parsed = [];
        foreach (preg_split('/\r\n|\n|\r/', $blob) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name !== '') {
                $parsed[$name] = $value;
            }
        }

        return $parsed;
    }
}
