<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Listings\Domain\Broker;

final class BrokersProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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

        return $items;
    }

    /** @return list<Broker> */
    public function all(): array
    {
        /** @var list<Broker> $items */
        $items = $this->em->getRepository(Broker::class)->findBy(
            [],
            ['sortOrder' => 'ASC', 'lastName' => 'ASC'],
        );

        return $items;
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
