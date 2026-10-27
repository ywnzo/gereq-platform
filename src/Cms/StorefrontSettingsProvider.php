<?php

declare(strict_types=1);

namespace Gereq\Cms;

interface StorefrontSettingsProvider
{
    /** @return list<array{id: string, title: string}> */
    public function storefrontSettingsPages(): array;

    public function validateStorefrontHomePage(string $id): void;

    public function saveStorefrontSettings(string $homePageId, string $publicCss): void;
}
