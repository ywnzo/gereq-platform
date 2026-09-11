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
        $commands = $this->commands;
        if ($user !== null) {
            $commands = array_values(array_filter(
                $commands,
                static fn (PaletteCommand $command): bool => $command->permission === null || $user->can($command->permission)
            ));
        }

        return array_map(
            static fn (PaletteCommand $command): PaletteCommand => new PaletteCommand(
                id: $command->id,
                label: $command->label,
                url: \Gereq\Support\UrlPrefix::path($command->url),
                icon: $command->icon,
                permission: $command->permission,
                keywords: $command->keywords,
                group: $command->group,
            ),
            $commands,
        );
    }
}
