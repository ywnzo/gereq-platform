<?php

declare(strict_types=1);

namespace Modules\Loyalty\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Loyalty\Domain\LoyaltyAccount;
use Modules\Loyalty\Domain\LoyaltyTier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AccountController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(LoyaltyAccount::class)->createQueryBuilder('a')
            ->orderBy('a.updatedAt', 'DESC');
        if ($search !== '') {
            $qb->andWhere('a.customerId LIKE :q OR a.id LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        /** @var list<LoyaltyAccount> $accounts */
        $accounts = $qb->setMaxResults(200)->getQuery()->getResult();
        $tiers = [];
        foreach ($this->em->getRepository(LoyaltyTier::class)->findAll() as $tier) {
            if ($tier instanceof LoyaltyTier) {
                $tiers[$tier->id()] = $tier;
            }
        }

        return $this->page('@loyalty/accounts/index.html.twig', [
            'pageTitle' => $this->translator->trans('loyalty.accounts.title'),
            'items' => $accounts,
            'tiers' => $tiers,
            'search' => $search,
        ]);
    }

    public function adjust(Request $request, string $id): Response
    {
        $account = $this->em->find(LoyaltyAccount::class, $id);
        if (!$account instanceof LoyaltyAccount) {
            $this->abort(404, $this->translator->trans('loyalty.error.account_not_found'));
        }

        $delta = (int) $request->request->get('delta', 0);
        if ($delta === 0) {
            $this->flash('error', $this->translator->trans('loyalty.error.delta_required'));

            return $this->redirect('admin.loyalty.accounts.index');
        }

        $before = $account->points();
        $account->adjustPoints($delta);
        $this->em->flush();

        $payload = [
            'id' => $account->id(),
            'customer_id' => $account->customerId(),
            'points' => $account->points(),
            'delta' => $delta,
        ];
        $this->events->emit('loyalty.points_adjusted', ['type' => 'loyalty_account', 'id' => $account->id()], $payload);
        $this->auditor->log('loyalty.points.adjusted', 'loyalty_account', $account->id(), [
            'points' => $before,
        ], [
            'points' => $account->points(),
            'delta' => $delta,
        ]);
        $this->flash('success', $this->translator->trans('loyalty.flash.points_adjusted'));

        return $this->redirect('admin.loyalty.accounts.index');
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
