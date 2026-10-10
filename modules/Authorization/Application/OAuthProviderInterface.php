<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

interface OAuthProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function authorizeUrl(string $redirectUri, string $state): string;

    /**
     * @return array{id: string, email: string, name: string}
     */
    public function profile(string $code, string $redirectUri): array;

    public function configured(): bool;
}
