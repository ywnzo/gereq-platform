<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Currency\Domain\UserCurrencyPreference;

/** Preferovaná měna uživatele — persistence v Currency modulu. */
final class UserCurrencyPreferenceStore
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $row = $this->em->find(UserCurrencyPreference::class, $userId);
        if (!$row instanceof UserCurrencyPreference) {
            return null;
        }
        $code = $row->currencyCode();

        return $code !== null && $code !== '' ? $code : null;
    }

    public function set(string $userId, ?string $code): void
    {
        $row = $this->em->find(UserCurrencyPreference::class, $userId);
        if (!$row instanceof UserCurrencyPreference) {
            $row = new UserCurrencyPreference($userId);
            $this->em->persist($row);
        }
        $row->setCurrencyCode($code);
        $this->em->flush();
    }
}
