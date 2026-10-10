<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

final class OauthUnlinkBlockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('oauth_unlink_blocked');
    }
}
