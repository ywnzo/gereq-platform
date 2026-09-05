<?php

declare(strict_types=1);

namespace Gereq\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class Auditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function log(
        string $action,
        ?string $resource = null,
        ?string $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $userId = null,
        ?string $userName = null,
        bool $flush = true,
    ): void {
        $request = $this->requestStack->getCurrentRequest();
        $ip = $request?->getClientIp();
        $ua = substr((string) $request?->headers->get('User-Agent'), 0, 255);

        if ($userId === null && $request !== null && $request->hasSession()) {
            $userId = $request->getSession()->get('auth.user_id');
            $userId = is_string($userId) ? $userId : null;
        }

        $log = new AuditLog(
            action: $action,
            userId: is_string($userId) ? $userId : null,
            userName: $userName ?? $this->resolveUserName($userId),
            resource: $resource,
            resourceId: $resourceId,
            oldValues: $oldValues,
            newValues: $newValues,
            ip: $ip,
            userAgent: $ua !== '' ? $ua : null,
        );

        $this->em->persist($log);
        if ($flush) {
            $this->em->flush();
        }
    }

    private function resolveUserName(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->em->find(\Gereq\User\Entity\User::class, $userId);

        return $user?->name();
    }
}
