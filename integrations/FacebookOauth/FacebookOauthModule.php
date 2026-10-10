<?php

declare(strict_types=1);

namespace Integrations\FacebookOauth;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\FacebookOauth\Application\FacebookOAuthProvider;
use Modules\Authorization\Application\OAuthProviderRegistry;

final class FacebookOauthModule implements ModuleInterface
{
    public function id(): string
    {
        return 'facebook_oauth';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $provider = $context->get(FacebookOAuthProvider::class);
        $context->get(OAuthProviderRegistry::class)->register($provider);
        $context->capabilities()->register('oauth.facebook', $this->id(), $provider);

        $context->settings()->define('auth.facebook.app_id', SettingRecord::TYPE_STRING, 'facebook_oauth.settings.app_id', '', $this->id(), [], false);
        $context->settings()->define('auth.facebook.app_secret', SettingRecord::TYPE_ENCRYPTED, 'facebook_oauth.settings.app_secret', '', $this->id(), [], false);
    }

    public function boot(ModuleContext $context): void
    {
    }
}
