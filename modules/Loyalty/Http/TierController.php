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
use Modules\Loyalty\Domain\LoyaltyTier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TierController extends Controller
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
        $qb = $this->em->getRepository(LoyaltyTier::class)->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.minSpend', 'ASC');
        self_repair_search($qb, 't', ['code', 'name'], $search);

        return $this->page('@loyalty/tiers/index.html.twig', [
            'pageTitle' => $this->translator->trans('loyalty.tiers.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@loyalty/tiers/form.html.twig', [
            'pageTitle' => $this->translator->trans('loyalty.tiers.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(LoyaltyTier::class)->findOneBy(['code' => $data['code']]) instanceof LoyaltyTier) {
            $this->abort(422, $this->translator->trans('loyalty.error.code_taken'));
        }

        $tier = new LoyaltyTier($data['code'], $data['name']);
        $this->applyData($tier, $data);
        $this->em->persist($tier);
        $this->em->flush();

        $payload = $this->publicTier($tier);
        $this->events->emit('loyalty.tier_created', ['type' => 'loyalty_tier', 'id' => $tier->id()], $payload);
        $this->auditor->log('loyalty.tier.created', 'loyalty_tier', $tier->id(), null, [
            'code' => $tier->code(),
            'name' => $tier->name(),
        ]);
        $this->forgetForm('loyalty.tier.save');
        $this->flash('success', $this->translator->trans('loyalty.flash.tier_created', ['%name%' => $tier->name()]));

        return $this->redirect('admin.loyalty.tiers.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('loyalty.error.tier_not_found'));
        }

        return $this->page('@loyalty/tiers/form.html.twig', [
            'pageTitle' => $this->translator->trans('loyalty.tiers.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('loyalty.error.tier_not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(LoyaltyTier::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof LoyaltyTier && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('loyalty.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $this->applyData($item, $data);
        $this->em->flush();

        $payload = $this->publicTier($item);
        $this->events->emit('loyalty.tier_updated', ['type' => 'loyalty_tier', 'id' => $item->id()], $payload);
        $this->auditor->log('loyalty.tier.updated', 'loyalty_tier', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('loyalty.tier.save');
        $this->flash('success', $this->translator->trans('loyalty.flash.tier_saved'));

        return $this->redirect('admin.loyalty.tiers.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('loyalty.error.tier_not_found'));
        }

        $name = $item->name();
        $payload = $this->publicTier($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('loyalty.tier_deleted', ['type' => 'loyalty_tier', 'id' => $id], $payload);
        $this->auditor->log('loyalty.tier.deleted', 'loyalty_tier', $id);
        $this->flash('success', $this->translator->trans('loyalty.flash.tier_deleted', ['%name%' => $name]));

        return $this->redirect('admin.loyalty.tiers.index');
    }

    private function find(string $id): ?LoyaltyTier
    {
        $item = $this->em->find(LoyaltyTier::class, $id);

        return $item instanceof LoyaltyTier ? $item : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     min_spend: string,
     *     discount_percent: string,
     *     sort_order: int,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'loyalty.tier.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'min_spend' => number_format((float) ($data['min_spend'] ?? 0), 2, '.', ''),
            'discount_percent' => number_format((float) ($data['discount_percent'] ?? 0), 2, '.', ''),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /**
     * @param array{
     *     min_spend: string,
     *     discount_percent: string,
     *     sort_order: int,
     *     active: bool
     * } $data
     */
    private function applyData(LoyaltyTier $tier, array $data): void
    {
        $tier->setMinSpend($data['min_spend']);
        $tier->setDiscountPercent($data['discount_percent']);
        $tier->setSortOrder($data['sort_order']);
        $tier->setActive($data['active']);
    }

    /** @return array<string, mixed> */
    private function publicTier(LoyaltyTier $tier): array
    {
        return [
            'id' => $tier->id(),
            'code' => $tier->code(),
            'name' => $tier->name(),
            'min_spend' => $tier->minSpend(),
            'discount_percent' => $tier->discountPercent(),
            'sort_order' => $tier->sortOrder(),
            'active' => $tier->isActive(),
            'created_at' => $tier->createdAt()->format(\DateTimeInterface::ATOM),
        ];
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
