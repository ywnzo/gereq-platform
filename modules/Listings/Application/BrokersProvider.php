<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\FrozenPublicReader;
use Modules\Listings\Domain\Broker;

final class BrokersProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentReadContext $readContext,
        private readonly FrozenPublicReader $frozen,
    ) {
    }

    public function find(string $id): ?Broker
    {
        $broker = $this->em->find(Broker::class, $id);

        return $broker instanceof Broker ? $broker : null;
    }

    /** @return list<Broker> */
    public function allActive(): array
    {
        /** @var list<Broker> $items */
        $items = $this->em->getRepository(Broker::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'lastName' => 'ASC'],
        );

        if (!$this->readContext->isProduction()) {
            $pendingDelete = array_flip($this->frozen->pendingDeleteKeys('listings.brokers'));

            return array_values(array_filter(
                $items,
                static fn (Broker $b): bool => !isset($pendingDelete[$b->id()]),
            ));
        }

        $out = [];
        foreach ($items as $broker) {
            if ($this->frozen->isPendingCreate('listings.brokers', $broker->id())) {
                continue;
            }
            $snap = $this->frozen->snapshot('listings.brokers', $broker->id());
            if ($snap !== null && empty($snap['active'])) {
                continue;
            }
            $out[] = $broker;
        }

        foreach ($this->frozen->allSnapshots('listings.brokers') as $hit) {
            $snap = $hit['snapshot'];
            if (empty($snap['active'])) {
                continue;
            }
            $id = $hit['resource_key'];
            if (array_any($out, static fn (Broker $b): bool => $b->id() === $id)) {
                continue;
            }
            $broker = $this->find($id);
            if ($broker instanceof Broker) {
                $out[] = $broker;
            }
        }

        return $out;
    }

    /** @return list<Broker> */
    public function all(): array
    {
        /** @var list<Broker> $items */
        $items = $this->em->getRepository(Broker::class)->findBy(
            [],
            ['sortOrder' => 'ASC', 'lastName' => 'ASC'],
        );

        $pendingDelete = array_flip($this->frozen->pendingDeleteKeys('listings.brokers'));

        return array_values(array_filter(
            $items,
            static fn (Broker $b): bool => !isset($pendingDelete[$b->id()]),
        ));
    }

    /** @return array{value: string, label: string}[] */
    public function options(bool $activeOnly = true): array
    {
        $items = $activeOnly ? $this->allActive() : $this->all();
        $out = [];
        foreach ($items as $broker) {
            $label = $broker->fullName();
            if ($broker->position()) {
                $label .= ' · ' . $broker->position();
            }
            $out[] = ['value' => $broker->id(), 'label' => $label];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function serialize(Broker $broker): array
    {
        if ($this->readContext->isProduction()) {
            $snap = $this->frozen->snapshot('listings.brokers', $broker->id());
            if (is_array($snap)) {
                return [
                    'id' => (string) ($snap['id'] ?? $broker->id()),
                    'first_name' => (string) ($snap['first_name'] ?? ''),
                    'last_name' => (string) ($snap['last_name'] ?? ''),
                    'name' => trim((string) ($snap['first_name'] ?? '') . ' ' . (string) ($snap['last_name'] ?? '')),
                    'email' => (string) ($snap['email'] ?? ''),
                    'phone' => $snap['phone'] ?? null,
                    'position' => $snap['position'] ?? null,
                    'skills' => $snap['skills'] ?? null,
                    'description' => $snap['description'] ?? null,
                    'photo_url' => $snap['photo_url'] ?? null,
                    'active' => (bool) ($snap['active'] ?? false),
                ];
            }
        }

        return [
            'id' => $broker->id(),
            'first_name' => $broker->firstName(),
            'last_name' => $broker->lastName(),
            'name' => $broker->fullName(),
            'email' => $broker->email(),
            'phone' => $broker->phone(),
            'position' => $broker->position(),
            'skills' => $broker->skills(),
            'description' => $broker->description(),
            'photo_url' => $broker->photoUrl(),
            'active' => $broker->active(),
        ];
    }
}
