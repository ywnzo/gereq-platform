<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

final class OauthNotLinkedException extends \RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct('oauth_not_linked:' . $provider);
    }
}
