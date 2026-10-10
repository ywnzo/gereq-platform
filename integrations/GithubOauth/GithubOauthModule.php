<?php

declare(strict_types=1);

namespace Integrations\GithubOauth;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\GithubOauth\Application\GithubOAuthProvider;
use Modules\Authorization\Application\OAuthProviderRegistry;

final class GithubOauthModule implements ModuleInterface
{
    public function id(): string
    {
        return 'github_oauth';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $provider = $context->get(GithubOAuthProvider::class);
        $context->get(OAuthProviderRegistry::class)->register($provider);
        $context->capabilities()->register('oauth.github', $this->id(), $provider);

        $context->settings()->define('auth.github.client_id', SettingRecord::TYPE_STRING, 'github_oauth.settings.client_id', '', $this->id(), [], false);
        $context->settings()->define('auth.github.client_secret', SettingRecord::TYPE_ENCRYPTED, 'github_oauth.settings.client_secret', '', $this->id(), [], false);
    }

    public function boot(ModuleContext $context): void
    {
    }
}
