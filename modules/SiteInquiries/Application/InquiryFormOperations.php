<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Cms\FormActionOperations;
use Modules\SiteInquiries\Domain\Inquiry;

final class InquiryFormOperations implements FormActionOperations
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly InquiryNotificationOutbox $notifications)
    {
    }

    public function viewPermission(): string
    {
        return 'site_inquiries.view';
    }

    public function retryPermission(): string
    {
        return 'site_inquiries.manage';
    }

    public function recordLink(string $id): ?array
    {
        return $this->recordLinks([$id])[$id] ?? null;
    }

    public function recordLinks(array $ids): array
    {
        $links = [];
        foreach ($this->em->getRepository(Inquiry::class)->findBy(['id' => array_values(array_unique($ids))]) as $inquiry) {
            $links[$inquiry->id()] = ['route' => 'admin.site_inquiries.show', 'params' => ['id' => $inquiry->id()]];
        }
        return $links;
    }

    public function retryNotification(string $id): string
    {
        $inquiry = $this->em->find(Inquiry::class, $id);
        if (!$inquiry instanceof Inquiry) {
            throw new \RuntimeException('Inquiry no longer exists.');
        }
        return $this->notifications->retry($id);
    }
}
