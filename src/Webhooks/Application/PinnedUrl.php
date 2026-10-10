<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

/** SSRF-safe connect target: dial pinned IP, keep original Host. */
final class PinnedUrl
{
    public function __construct(
        public readonly string $originalUrl,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $pathAndQuery,
        public readonly string $ip,
    ) {
    }

    /** URL curl should connect to (scheme://ip:port/path). */
    public function connectUrl(): string
    {
        $authority = $this->ip;
        if (str_contains($this->ip, ':')) {
            $authority = '[' . $this->ip . ']';
        }

        $default = $this->scheme === 'https' ? 443 : 80;
        $port = $this->port === $default ? '' : ':' . $this->port;

        return $this->scheme . '://' . $authority . $port . $this->pathAndQuery;
    }
}
