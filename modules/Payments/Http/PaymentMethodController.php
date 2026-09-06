<?php

declare(strict_types=1);

namespace Modules\Payments\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Payments\Domain\PaymentMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaymentMethodController extends Controller
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
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(PaymentMethod::class)->createQueryBuilder('m')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.code', 'ASC');
        self_repair_search($qb, 'm', ['code', 'name', 'provider'], $search);

        return $this->page('@payments/methods/index.html.twig', [
            'pageTitle' => $this->translator->trans('payments.methods.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@payments/methods/form.html.twig', [
            'pageTitle' => $this->translator->trans('payments.methods.new'),
            'item' => null,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof PaymentMethod) {
            $this->abort(422, $this->translator->trans('payments.error.code_taken'));
        }

        $method = new PaymentMethod($data['code'], $data['name'], $data['type']);
        $this->applyData($method, $data);
        $this->em->persist($method);
        $this->em->flush();

        $payload = $this->publicMethod($method);
        $this->events->emit('payment.method_created', ['type' => 'payment_method', 'id' => $method->id()], $payload);
        $this->auditor->log('payments.method.created', 'payment_method', $method->id(), null, [
            'code' => $method->code(),
            'name' => $method->name(),
        ]);
        $this->forgetForm('payments.method.save');
        $this->flash('success', $this->translator->trans('payments.flash.created', ['%name%' => $method->name()]));

        return $this->redirect('admin.payments.methods.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('payments.error.not_found'));
        }

        return $this->page('@payments/methods/form.html.twig', [
            'pageTitle' => $this->translator->trans('payments.methods.edit'),
            'item' => $item,
            'types' => PaymentMethod::TYPES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('payments.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof PaymentMethod && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('payments.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $item->setType($data['type']);
        $this->applyData($item, $data);
        $this->em->flush();

        $payload = $this->publicMethod($item);
        $this->events->emit('payment.method_updated', ['type' => 'payment_method', 'id' => $item->id()], $payload);
        $this->auditor->log('payments.method.updated', 'payment_method', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('payments.method.save');
        $this->flash('success', $this->translator->trans('payments.flash.saved'));

        return $this->redirect('admin.payments.methods.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('payments.error.not_found'));
        }

        $name = $item->name();
        $payload = $this->publicMethod($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('payment.method_deleted', ['type' => 'payment_method', 'id' => $id], $payload);
        $this->auditor->log('payments.method.deleted', 'payment_method', $id);
        $this->flash('success', $this->translator->trans('payments.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.payments.methods.index');
    }

    private function find(string $id): ?PaymentMethod
    {
        $item = $this->em->find(PaymentMethod::class, $id);

        return $item instanceof PaymentMethod ? $item : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     provider: ?string,
     *     fee_fixed: string,
     *     fee_percent: string,
     *     currency: string,
     *     instructions: ?string,
     *     sort_order: int,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'payments.method.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');
        $provider = trim((string) ($data['provider'] ?? ''));
        $instructions = trim((string) ($data['instructions'] ?? ''));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'type' => strtolower(trim((string) ($data['type'] ?? 'bank_transfer'))),
            'provider' => $provider === '' ? null : $provider,
            'fee_fixed' => number_format((float) ($data['fee_fixed'] ?? 0), 2, '.', ''),
            'fee_percent' => number_format((float) ($data['fee_percent'] ?? 0), 4, '.', ''),
            'currency' => $this->money->code(isset($data['currency']) ? (string) $data['currency'] : null),
            'instructions' => $instructions === '' ? null : $instructions,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @param array{provider: ?string, fee_fixed: string, fee_percent: string, currency: string, instructions: ?string, sort_order: int, active: bool} $data */
    private function applyData(PaymentMethod $method, array $data): void
    {
        $method->setProvider($data['provider']);
        $method->setFeeFixed($data['fee_fixed']);
        $method->setFeePercent($data['fee_percent']);
        $method->setCurrency($data['currency']);
        $method->setInstructions($data['instructions']);
        $method->setSortOrder($data['sort_order']);
        $method->setActive($data['active']);
    }

    /** @return array<string, mixed> */
    private function publicMethod(PaymentMethod $method): array
    {
        return [
            'id' => $method->id(),
            'code' => $method->code(),
            'name' => $method->name(),
            'type' => $method->type(),
            'provider' => $method->provider(),
            'fee_fixed' => $method->feeFixed(),
            'fee_percent' => $method->feePercent(),
            'currency' => $method->currency(),
            'instructions' => $method->instructions(),
            'sort_order' => $method->sortOrder(),
            'active' => $method->isActive(),
            'created_at' => $method->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $method->updatedAt()->format(\DateTimeInterface::ATOM),
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
