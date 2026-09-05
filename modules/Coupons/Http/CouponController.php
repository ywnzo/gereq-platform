<?php

declare(strict_types=1);

namespace Modules\Coupons\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Coupons\Domain\Coupon;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CouponController extends Controller
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
        $qb = $this->em->getRepository(Coupon::class)->createQueryBuilder('c')
            ->orderBy('c.code', 'ASC');
        self_repair_search($qb, 'c', ['code', 'name'], $search);

        return $this->page('@coupons/index.html.twig', [
            'pageTitle' => $this->translator->trans('coupons.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@coupons/form.html.twig', [
            'pageTitle' => $this->translator->trans('coupons.new'),
            'item' => null,
            'types' => Coupon::TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(Coupon::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof Coupon) {
            $this->abort(422, $this->translator->trans('coupons.error.code_taken'));
        }

        $coupon = new Coupon($data['code'], $data['name'], $data['type'], $data['value']);
        $this->applyData($coupon, $data);
        $this->em->persist($coupon);
        $this->em->flush();

        $payload = $this->publicCoupon($coupon);
        $this->events->emit('coupon.created', ['type' => 'coupon', 'id' => $coupon->id()], $payload);
        $this->auditor->log('coupons.created', 'coupon', $coupon->id(), null, [
            'code' => $coupon->code(),
            'name' => $coupon->name(),
        ]);
        $this->forgetForm('coupons.save');
        $this->flash('success', $this->translator->trans('coupons.flash.created', ['%name%' => $coupon->name()]));

        return $this->redirect('admin.coupons.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('coupons.error.not_found'));
        }

        return $this->page('@coupons/form.html.twig', [
            'pageTitle' => $this->translator->trans('coupons.edit'),
            'item' => $item,
            'types' => Coupon::TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('coupons.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(Coupon::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof Coupon && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('coupons.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $item->setType($data['type']);
        $item->setValue($data['value']);
        $this->applyData($item, $data);
        $this->em->flush();

        $payload = $this->publicCoupon($item);
        $this->events->emit('coupon.updated', ['type' => 'coupon', 'id' => $item->id()], $payload);
        $this->auditor->log('coupons.updated', 'coupon', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('coupons.save');
        $this->flash('success', $this->translator->trans('coupons.flash.saved'));

        return $this->redirect('admin.coupons.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('coupons.error.not_found'));
        }

        $name = $item->name();
        $payload = $this->publicCoupon($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('coupon.deleted', ['type' => 'coupon', 'id' => $id], $payload);
        $this->auditor->log('coupons.deleted', 'coupon', $id);
        $this->flash('success', $this->translator->trans('coupons.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.coupons.index');
    }

    private function find(string $id): ?Coupon
    {
        $item = $this->em->find(Coupon::class, $id);

        return $item instanceof Coupon ? $item : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     value: string,
     *     currency: ?string,
     *     min_order: ?string,
     *     max_uses: ?int,
     *     starts_at: ?\DateTimeImmutable,
     *     ends_at: ?\DateTimeImmutable,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'coupons.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');
        $currency = trim((string) ($data['currency'] ?? ''));
        $minOrder = trim((string) ($data['min_order'] ?? ''));
        $maxUses = trim((string) ($data['max_uses'] ?? ''));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'type' => strtolower(trim((string) ($data['type'] ?? 'percent'))),
            'value' => number_format((float) ($data['value'] ?? 0), 2, '.', ''),
            'currency' => $currency === '' ? null : strtoupper($currency),
            'min_order' => $minOrder === '' ? null : number_format((float) $minOrder, 2, '.', ''),
            'max_uses' => $maxUses === '' ? null : (int) $maxUses,
            'starts_at' => $this->parseDate($data['starts_at'] ?? null),
            'ends_at' => $this->parseDate($data['ends_at'] ?? null),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array{
     *     currency: ?string,
     *     min_order: ?string,
     *     max_uses: ?int,
     *     starts_at: ?\DateTimeImmutable,
     *     ends_at: ?\DateTimeImmutable,
     *     active: bool
     * } $data
     */
    private function applyData(Coupon $coupon, array $data): void
    {
        $coupon->setCurrency($data['currency']);
        $coupon->setMinOrder($data['min_order']);
        $coupon->setMaxUses($data['max_uses']);
        $coupon->setStartsAt($data['starts_at']);
        $coupon->setEndsAt($data['ends_at']);
        $coupon->setActive($data['active']);
    }

    /** @return array<string, mixed> */
    private function publicCoupon(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id(),
            'code' => $coupon->code(),
            'name' => $coupon->name(),
            'type' => $coupon->type(),
            'value' => $coupon->value(),
            'currency' => $coupon->currency(),
            'min_order' => $coupon->minOrder(),
            'max_uses' => $coupon->maxUses(),
            'used_count' => $coupon->usedCount(),
            'starts_at' => $coupon->startsAt()?->format(\DateTimeInterface::ATOM),
            'ends_at' => $coupon->endsAt()?->format(\DateTimeInterface::ATOM),
            'active' => $coupon->isActive(),
            'created_at' => $coupon->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $coupon->updatedAt()->format(\DateTimeInterface::ATOM),
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
