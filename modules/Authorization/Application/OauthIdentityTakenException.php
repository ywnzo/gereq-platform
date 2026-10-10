<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

final class OauthIdentityTakenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('oauth_identity_taken');
    }
}
