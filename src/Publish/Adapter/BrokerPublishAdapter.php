<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Listings\Domain\Broker;

final class BrokerPublishAdapter implements PublishableAdapterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
    ) {
    }

    public function settingsKeys(): array
    {
        return [];
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            $entry = $this->store->find('listings.brokers', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $broker = $this->em->find(Broker::class, $key);
            if ($broker instanceof Broker) {
                $out[$key] = self::capture($broker);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $broker = $this->em->find(Broker::class, $entry->resourceKey());
            if ($broker instanceof Broker) {
                $this->em->remove($broker);
                $this->em->flush();
            }
        }
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap)
            ? trim((string) ($snap['first_name'] ?? '') . ' ' . (string) ($snap['last_name'] ?? ''))
            : '';
        if ($name === '') {
            $name = $entry->label();
        }
        $email = is_array($snap) ? (string) ($snap['email'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $email);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $email);
        }

        return new ChangeCard($name, $email);
    }

    /** @return array<string, mixed> */
    public static function capture(Broker $broker): array
    {
        return [
            'id' => $broker->id(),
            'first_name' => $broker->firstName(),
            'last_name' => $broker->lastName(),
            'email' => $broker->email(),
            'phone' => $broker->phone(),
            'position' => $broker->position(),
            'skills' => $broker->skills(),
            'description' => $broker->description(),
            'photo_url' => $broker->photoUrl(),
            'active' => $broker->active(),
            'sort_order' => $broker->sortOrder(),
        ];
    }
}
