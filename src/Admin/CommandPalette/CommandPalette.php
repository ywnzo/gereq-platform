<?php

declare(strict_types=1);

namespace Gereq\Admin\CommandPalette;

use Gereq\User\Entity\User;

final class CommandPalette
{
    /** @var list<PaletteCommand> */
    private array $commands = [];

    public function add(PaletteCommand $command): void
    {
        $this->commands[] = $command;
    }

    /**
     * @return list<PaletteCommand>
     */
    public function all(?User $user = null): array
    {
        if ($user === null) {
            return $this->commands;
        }

        return array_values(array_filter(
            $this->commands,
            static fn (PaletteCommand $command): bool => $command->permission === null || $user->can($command->permission)
        ));
    }
}
