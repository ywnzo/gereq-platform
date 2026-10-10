<?php

declare(strict_types=1);

namespace Gereq\Database\Demo;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Notifications\Channel\DatabaseNotification;
use Gereq\Notifications\Domain\NotificationPreference;
use Gereq\User\Entity\User;

/** Sample inbox notifications for demo admin users. */
final class NotificationsDemoSeeder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<string> $log */
    public function seed(User $demo, User $manager, array &$log): void
    {
        $this->em->persist(new DatabaseNotification($demo->id(), 'projects.task_assigned', [
            'title' => 'Nový úkol',
            'body' => 'Byl vám přiřazen úkol „Nasazení staging prostředí“.',
            'url' => '/admin/projects',
        ]));
        $this->em->persist(new DatabaseNotification($demo->id(), 'orders.invoice_due', [
            'title' => 'Splatná faktura',
            'body' => 'Faktura INV-2026-0002 je splatná za 11 dní.',
            'url' => '/admin/orders',
        ]));
        $this->em->persist(new DatabaseNotification($demo->id(), 'warehouse.low_stock', [
            'title' => 'Nízká zásoba',
            'body' => 'Monitor 27" 4K — zbývají 2 ks ve skladu Praha.',
            'url' => '/admin/warehouse',
        ]));
        $read = new DatabaseNotification($demo->id(), 'crm.deal_won', [
            'title' => 'Obchod vyhraný',
            'body' => 'Licence 2026 renewal — Logistik CZ.',
            'url' => '/admin/crm',
        ]);
        $read->markRead();
        $this->em->persist($read);

        $this->em->persist(new DatabaseNotification($manager->id(), 'shop.cart_open', [
            'title' => 'Otevřený košík',
            'body' => 'City Café má otevřený košík s 2 položkami.',
            'url' => '/admin/shop/carts',
        ]));

        if (class_exists(NotificationPreference::class)) {
            foreach (['mail', 'database'] as $channel) {
                foreach (['projects.task_assigned', 'orders.invoice_due', 'warehouse.low_stock'] as $event) {
                    $this->em->persist(new NotificationPreference(
                        $demo->id(),
                        $channel,
                        $event,
                        true,
                    ));
                }
            }
        }

        $log[] = 'Demo notifikace vytvořeny.';
    }
}
