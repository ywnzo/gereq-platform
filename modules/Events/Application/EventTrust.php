<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Events\Domain\TrustedOrganizer;

final class EventTrust
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isTrusted(?string $customerId): bool
    {
        $customerId = trim((string) $customerId);
        if ($customerId === '') {
            return false;
        }

        return $this->find($customerId) instanceof TrustedOrganizer;
    }

    public function grant(string $customerId): void
    {
        $customerId = trim($customerId);
        if ($customerId === '' || $this->isTrusted($customerId)) {
            return;
        }
        $this->em->persist(new TrustedOrganizer($customerId));
        $this->em->flush();
    }

    public function revoke(string $customerId): void
    {
        $row = $this->find($customerId);
        if (!$row instanceof TrustedOrganizer) {
            return;
        }
        $this->em->remove($row);
        $this->em->flush();
    }

    public function forget(string $customerId): void
    {
        $row = $this->find($customerId);
        if ($row instanceof TrustedOrganizer) {
            $this->em->remove($row);
        }
    }

    private function find(string $customerId): ?TrustedOrganizer
    {
        $row = $this->em->getRepository(TrustedOrganizer::class)->findOneBy(['customerId' => $customerId]);

        return $row instanceof TrustedOrganizer ? $row : null;
    }
}
