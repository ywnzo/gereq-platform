<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use Symfony\Component\HttpFoundation\Request;

final class EventCustomerAuth
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoreCookieBridge $cookies,
        private readonly StoreTokenService $tokens,
    ) {
    }

    public function accountFromRequest(Request $request): ?CustomerAccount
    {
        $token = $this->cookies->tokenFromRequest($request);
        if ($token === null) {
            return null;
        }
        $claims = $this->tokens->parse($token);
        if ($claims === null) {
            return null;
        }
        $account = $this->em->find(CustomerAccount::class, $claims['sub']);
        if (!$account instanceof CustomerAccount) {
            return null;
        }
        if ($account->tokenVersion() !== $claims['ver']) {
            return null;
        }

        return $account;
    }
}
