<?php

declare(strict_types=1);

namespace Gereq\Http\Session;

/**
 * Adds exclusive locks around a session handler (needed for Redis, which has none).
 *
 * Without locking, concurrent admin AJAX can race on session writes and drop the
 * CSRF token — publish then fails with HTTP 419 until the next full page load.
 */
final class LockingSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private const RELEASE_LUA = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
LUA;

    private ?string $lockedSessionId = null;

    private readonly string $lockToken;

    public function __construct(
        private readonly \SessionHandlerInterface $handler,
        private readonly \Redis $redis,
        private readonly string $lockPrefix = 'gereq:session:lock:',
        private readonly int $lockTtlSec = 30,
        private readonly int $lockWaitMs = 5000,
        private readonly int $spinMs = 40,
    ) {
        $this->lockToken = bin2hex(random_bytes(16));
    }

    public function open(string $path, string $name): bool
    {
        return $this->handler->open($path, $name);
    }

    public function close(): bool
    {
        $this->releaseLock();

        return $this->handler->close();
    }

    public function read(#[\SensitiveParameter] string $id): string|false
    {
        $this->acquireLock($id);

        return $this->handler->read($id);
    }

    public function write(#[\SensitiveParameter] string $id, string $data): bool
    {
        try {
            return $this->handler->write($id, $data);
        } finally {
            $this->releaseLock();
        }
    }

    public function destroy(#[\SensitiveParameter] string $id): bool
    {
        try {
            return $this->handler->destroy($id);
        } finally {
            $this->releaseLock();
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->handler->gc($max_lifetime);
    }

    public function validateId(#[\SensitiveParameter] string $id): bool
    {
        $this->acquireLock($id);

        if ($this->handler instanceof \SessionUpdateTimestampHandlerInterface) {
            return $this->handler->validateId($id);
        }

        $data = $this->handler->read($id);

        return is_string($data) && $data !== '';
    }

    public function updateTimestamp(#[\SensitiveParameter] string $id, string $data): bool
    {
        try {
            if ($this->handler instanceof \SessionUpdateTimestampHandlerInterface) {
                return $this->handler->updateTimestamp($id, $data);
            }

            return $this->handler->write($id, $data);
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(string $sessionId): void
    {
        if ($this->lockedSessionId === $sessionId) {
            return;
        }

        if ($this->lockedSessionId !== null) {
            $this->releaseLock();
        }

        $key = $this->lockPrefix . $sessionId;
        $deadline = microtime(true) + ($this->lockWaitMs / 1000.0);

        while (microtime(true) < $deadline) {
            if ($this->redis->set($key, $this->lockToken, ['nx', 'ex' => $this->lockTtlSec])) {
                $this->lockedSessionId = $sessionId;

                return;
            }

            usleep($this->spinMs * 1000);
        }

        throw new \RuntimeException('Session lock timeout — zkuste požadavek znovu.');
    }

    private function releaseLock(): void
    {
        if ($this->lockedSessionId === null) {
            return;
        }

        $key = $this->lockPrefix . $this->lockedSessionId;
        $this->lockedSessionId = null;

        try {
            $this->redis->eval(self::RELEASE_LUA, [$key, $this->lockToken], 1);
        } catch (\Throwable) {
            // Best-effort unlock; lock TTL covers crashes.
        }
    }
}
