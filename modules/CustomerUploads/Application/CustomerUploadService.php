<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;
use Modules\CustomerUploads\Domain\CustomerUpload;

final class CustomerUploadService
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly string $basePath,
    ) {
    }

    /** @return array{token: string, expires_at: string, max_bytes: int, allowed_mimes: list<string>} */
    public function createSession(): array
    {
        $ttl = max(300, (int) $this->settings->get('uploads.session_ttl_seconds', 86400));
        $plain = bin2hex(random_bytes(24));
        $expires = time() + $ttl;
        $payload = base64_encode(json_encode(['t' => $plain, 'exp' => $expires], JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $payload, $this->signingKey());
        $token = $payload . '.' . $sig;

        return [
            'token' => $token,
            'expires_at' => (new \DateTimeImmutable('@' . $expires))->format(\DateTimeInterface::ATOM),
            'max_bytes' => $this->maxBytes(),
            'allowed_mimes' => self::ALLOWED,
        ];
    }

    public function verifySession(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $this->signingKey());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($json) || !isset($json['t'], $json['exp'])) {
            return null;
        }
        if ((int) $json['exp'] < time()) {
            return null;
        }

        return hash('sha256', (string) $json['t']);
    }

    public function store(
        string $sessionHash,
        string $tmpPath,
        string $originalName,
        string $mime,
        int $size,
        ?string $productId = null,
    ): CustomerUpload {
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('uploads.error.invalid_mime');
        }
        if ($size <= 0 || $size > $this->maxBytes()) {
            throw new \InvalidArgumentException('uploads.error.too_large');
        }
        if (@getimagesize($tmpPath) === false) {
            throw new \InvalidArgumentException('uploads.error.not_image');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $stored = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('uploads.error.storage');
        }
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($tmpPath, $dest) && !rename($tmpPath, $dest)) {
            if (!copy($tmpPath, $dest)) {
                throw new \RuntimeException('uploads.error.storage');
            }
            @unlink($tmpPath);
        }

        $upload = new CustomerUpload($sessionHash, $originalName, $stored, $mime, $size, $productId);
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }

    /**
     * @param list<string> $uploadIds
     */
    public function linkToOrder(string $orderNumber, array $uploadIds, string $sessionHash): int
    {
        $count = 0;
        foreach ($uploadIds as $id) {
            $upload = $this->em->find(CustomerUpload::class, $id);
            if (!$upload instanceof CustomerUpload) {
                continue;
            }
            if (!hash_equals($upload->sessionHash(), $sessionHash)) {
                continue;
            }
            $upload->linkToOrder($orderNumber);
            $count++;
        }
        $this->em->flush();

        return $count;
    }

    public function setConsent(CustomerUpload $upload, bool $consent): void
    {
        $upload->setPromotionalConsent($consent);
        $this->em->flush();
    }

    public function absolutePath(CustomerUpload $upload): string
    {
        return $this->storageDir() . '/' . $upload->storedName();
    }

    /** @return array<string, mixed> */
    public function serialize(CustomerUpload $upload): array
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        return [
            'id' => $upload->id(),
            'product_id' => $upload->productId(),
            'order_number' => $upload->orderNumber(),
            'original_name' => $upload->originalName(),
            'mime' => $upload->mime(),
            'size' => $upload->size(),
            'promotional_consent' => $upload->promotionalConsent(),
            'url' => $base . '/api/v1/uploads/files/' . $upload->id(),
            'created_at' => $upload->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function purgeExpired(?int $days = null, bool $dryRun = false): array
    {
        $days = $days ?? max(1, (int) $this->settings->get('uploads.retention_days', 7));
        $cutoff = (new \DateTimeImmutable())->modify('-' . $days . ' days');
        $items = $this->em->getRepository(CustomerUpload::class)->createQueryBuilder('u')
            ->where('u.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
        $deleted = 0;
        foreach ($items as $upload) {
            if (!$upload instanceof CustomerUpload) {
                continue;
            }
            if ($dryRun) {
                $deleted++;
                continue;
            }
            $path = $this->absolutePath($upload);
            if (is_file($path)) {
                @unlink($path);
            }
            $this->em->remove($upload);
            $deleted++;
        }
        if (!$dryRun && $deleted > 0) {
            $this->em->flush();
        }

        return [
            'days' => $days,
            'candidates' => count($items),
            'deleted' => $deleted,
        ];
    }

    private function maxBytes(): int
    {
        return max(1024 * 100, (int) $this->settings->get('uploads.max_bytes', 25 * 1024 * 1024));
    }

    private function storageDir(): string
    {
        return rtrim($this->basePath, '/') . '/storage/uploads/customer';
    }

    private function signingKey(): string
    {
        $key = (string) $this->config->get('app.key', '');
        if ($key === '') {
            $key = (string) $this->settings->get('uploads.signing_key', 'gereq-uploads');
        }

        return $key;
    }
}
