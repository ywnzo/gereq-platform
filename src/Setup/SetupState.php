<?php

declare(strict_types=1);

namespace Gereq\Setup;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

/** Tracks first-run setup wizard completion. */
final class SetupState
{
    public const KEY = 'setup.completed';

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isComplete(): bool
    {
        $record = $this->em->find(SettingRecord::class, self::KEY);
        if (!$record instanceof SettingRecord) {
            // Legacy installs without the flag are considered done.
            return true;
        }

        return (bool) $this->settings->get(self::KEY, false);
    }

    public function isPending(): bool
    {
        return !$this->isComplete();
    }

    public function markIncomplete(): void
    {
        $this->settings->set(self::KEY, false, SettingRecord::TYPE_BOOLEAN, 'core');
    }

    public function markComplete(): void
    {
        $this->settings->set(self::KEY, true, SettingRecord::TYPE_BOOLEAN, 'core');
    }
}
