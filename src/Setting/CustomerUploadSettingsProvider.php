<?php

declare(strict_types=1);

namespace Gereq\Setting;

interface CustomerUploadSettingsProvider
{
    /** @return array<string, string> */
    public function fields(): array;

    /** @param array<string, mixed> $data */
    public function save(array $data): void;
}
