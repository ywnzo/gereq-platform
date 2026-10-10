<?php

declare(strict_types=1);

namespace Integrations\GoogleOauth;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\GoogleOauth\Application\GoogleOAuthProvider;
use Modules\Authorization\Application\OAuthProviderRegistry;

final class GoogleOauthModule implements ModuleInterface
{
    public function id(): string
    {
        return 'google_oauth';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $provider = $context->get(GoogleOAuthProvider::class);
        $context->get(OAuthProviderRegistry::class)->register($provider);
        $context->capabilities()->register('oauth.google', $this->id(), $provider);

        $context->settings()->define('auth.google.client_id', SettingRecord::TYPE_STRING, 'google_oauth.settings.client_id', '', $this->id(), [], false);
        $context->settings()->define('auth.google.client_secret', SettingRecord::TYPE_ENCRYPTED, 'google_oauth.settings.client_secret', '', $this->id(), [], false);
    }

    public function boot(ModuleContext $context): void
    {
    }
}
