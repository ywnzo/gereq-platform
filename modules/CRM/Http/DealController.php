<?php

declare(strict_types=1);

namespace Modules\CRM\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CRM\Domain\Deal;
use Modules\Customers\Domain\Customer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DealController extends Controller
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
        $view = (string) $request->query->get('view', 'board');
        if (!in_array($view, ['board', 'list'], true)) {
            $view = 'board';
        }

        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['title', 'stage', 'amount', 'expectedCloseAt', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['stage', 'customer'],
        );
        $stage = $list->filter('stage');
        $customerId = $list->filter('customer');

        $qb = $this->em->getRepository(Deal::class)->createQueryBuilder('d');

        if ($list->search !== '') {
            $qb->andWhere('d.title LIKE :q')->setParameter('q', '%' . $list->search . '%');
        }
        if (in_array($stage, Deal::STAGES, true)) {
            $qb->andWhere('d.stage = :stage')->setParameter('stage', $stage);
        }
        if ($customerId !== '') {
            $qb->andWhere('d.customerId = :customer')->setParameter('customer', $customerId);
        }

        $qb->orderBy('d.' . $list->sort, $list->direction());
        $items = $qb->getQuery()->getResult();
        $customers = $this->customerMap($items);

        $columns = [];
        foreach (Deal::STAGES as $s) {
            $columns[$s] = [];
        }
        foreach ($items as $deal) {
            /** @var Deal $deal */
            $columns[$deal->stage()][] = $deal;
        }

        return $this->page('@crm/deals/index.html.twig', array_merge($list->viewData(count($items)), [
            'pageTitle' => $this->translator->trans('crm.deals.title'),
            'items' => $items,
            'columns' => $columns,
            'customers' => $customers,
            'view' => $view,
            'stage' => $stage,
            'customerId' => $customerId,
            'stages' => Deal::STAGES,
        ]));
    }

    public function create(Request $request): Response
    {
        $customerId = (string) $request->query->get('customer', '');

        return $this->page('@crm/deals/form.html.twig', [
            'pageTitle' => $this->translator->trans('crm.deals.new'),
            'item' => null,
            'customerId' => $customerId,
            'customerOptions' => $this->customerOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);

        $deal = new Deal($data['customer_id'], $data['title']);
        $deal->setAmount($data['amount']);
        $deal->setCurrency($data['currency']);
        $deal->setStage($data['stage']);
        $deal->setExpectedCloseAt($data['expected_close_at']);
        $deal->setNote($data['note']);
        $this->em->persist($deal);
        $this->em->flush();
        $this->events->emit('deal.created', ['type' => 'deal', 'id' => $deal->id()], $this->publicDeal($deal));
        $this->auditor->log('crm.deal.created', 'crm_deal', $deal->id(), null, [
            'title' => $deal->title(),
            'customer_id' => $deal->customerId(),
        ]);
        $this->forgetForm('crm.deal.save');
        $this->flash('success', $this->translator->trans('crm.flash.deal_created', ['%title%' => $deal->title()]));

        return $this->redirect('admin.crm.deals.show', ['id' => $deal->id()]);
    }

    public function show(string $id): Response
    {
        $deal = $this->find($id);
        if ($deal === null) {
            $this->abort(404, $this->translator->trans('crm.error.deal_not_found'));
        }

        $customer = $this->em->find(Customer::class, $deal->customerId());

        return $this->page('@crm/deals/show.html.twig', [
            'pageTitle' => $deal->title(),
            'item' => $deal,
            'customer' => $customer instanceof Customer ? $customer : null,
            'stages' => Deal::STAGES,
        ]);
    }

    public function edit(string $id): Response
    {
        $deal = $this->find($id);
        if ($deal === null) {
            $this->abort(404, $this->translator->trans('crm.error.deal_not_found'));
        }

        return $this->page('@crm/deals/form.html.twig', [
            'pageTitle' => $this->translator->trans('crm.deals.edit'),
            'item' => $deal,
            'customerId' => $deal->customerId(),
            'customerOptions' => $this->customerOptions(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $deal = $this->find($id);
        if ($deal === null) {
            $this->abort(404, $this->translator->trans('crm.error.deal_not_found'));
        }

        $data = $this->validated($request);
        $old = ['stage' => $deal->stage(), 'title' => $deal->title()];
        $previousStage = $deal->stage();
        $deal->setCustomerId($data['customer_id']);
        $deal->setTitle($data['title']);
        $deal->setAmount($data['amount']);
        $deal->setCurrency($data['currency']);
        $deal->setStage($data['stage']);
        $deal->setExpectedCloseAt($data['expected_close_at']);
        $deal->setNote($data['note']);
        $this->em->flush();
        $payload = $this->publicDeal($deal);
        $this->events->emit('deal.updated', ['type' => 'deal', 'id' => $deal->id()], $payload);
        if ($previousStage !== $deal->stage()) {
            $this->events->emit('deal.stage_changed', ['type' => 'deal', 'id' => $deal->id()], array_merge($payload, [
                'previous_stage' => $previousStage,
            ]));
        }
        $this->auditor->log('crm.deal.updated', 'crm_deal', $deal->id(), $old, [
            'stage' => $deal->stage(),
            'title' => $deal->title(),
        ]);
        $this->forgetForm('crm.deal.save');
        $this->flash('success', $this->translator->trans('crm.flash.deal_saved'));

        return $this->redirect('admin.crm.deals.show', ['id' => $id]);
    }

    public function updateStage(Request $request, string $id): Response
    {
        $deal = $this->find($id);
        if ($deal === null) {
            $this->abort(404, $this->translator->trans('crm.error.deal_not_found'));
        }

        $stage = (string) $request->request->get('stage', '');
        if (!in_array($stage, Deal::STAGES, true)) {
            $this->flash('error', $this->translator->trans('crm.flash.stage_invalid'));

            return $this->redirect('admin.crm.deals.show', ['id' => $id]);
        }

        $old = $deal->stage();
        $deal->setStage($stage);
        $this->em->flush();
        $payload = $this->publicDeal($deal);
        $this->events->emit('deal.stage_changed', ['type' => 'deal', 'id' => $deal->id()], array_merge($payload, [
            'previous_stage' => $old,
        ]));
        $this->events->emit('deal.updated', ['type' => 'deal', 'id' => $deal->id()], $payload);
        $this->auditor->log('crm.deal.stage', 'crm_deal', $deal->id(), ['stage' => $old], ['stage' => $stage]);
        $this->flash('success', $this->translator->trans('crm.flash.stage_changed', [
            '%stage%' => $this->translator->trans($deal->stageLabel()),
        ]));

        $redirect = (string) $request->request->get('_redirect', '');
        if ($redirect === 'index') {
            return $this->redirect('admin.crm.deals.index');
        }

        return $this->redirect('admin.crm.deals.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $deal = $this->find($id);
        if ($deal === null) {
            $this->abort(404, $this->translator->trans('crm.error.deal_not_found'));
        }

        $title = $deal->title();
        $payload = $this->publicDeal($deal);
        $this->em->remove($deal);
        $this->em->flush();
        $this->events->emit('deal.deleted', ['type' => 'deal', 'id' => $id], $payload);
        $this->auditor->log('crm.deal.deleted', 'crm_deal', $id);
        $this->flash('success', $this->translator->trans('crm.flash.deal_deleted', ['%title%' => $title]));

        return $this->redirect('admin.crm.deals.index');
    }

    private function find(string $id): ?Deal
    {
        $deal = $this->em->find(Deal::class, $id);

        return $deal instanceof Deal ? $deal : null;
    }

    /** @return array<string, mixed> */
    private function publicDeal(Deal $deal): array
    {
        return [
            'id' => $deal->id(),
            'customer_id' => $deal->customerId(),
            'title' => $deal->title(),
            'amount' => $deal->amount(),
            'currency' => $deal->currency(),
            'stage' => $deal->stage(),
            'expected_close_at' => $deal->expectedCloseAt()?->format('Y-m-d'),
            'note' => $deal->note(),
            'created_at' => $deal->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $deal->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{customer_id: string, title: string, amount: ?string, currency: string, stage: string, expected_close_at: ?\DateTimeImmutable, note: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'crm.deal.save');

        $customerId = trim((string) ($data['customer_id'] ?? ''));
        $customer = $this->em->find(Customer::class, $customerId);
        if (!$customer instanceof Customer) {
            $this->abort(422, $this->translator->trans('crm.error.invalid_customer'));
        }

        $amountRaw = trim((string) ($data['amount'] ?? ''));
        $amount = null;
        if ($amountRaw !== '') {
            $normalized = str_replace([' ', ','], ['', '.'], $amountRaw);
            if (!is_numeric($normalized)) {
                $this->abort(422, $this->translator->trans('crm.error.invalid_amount'));
            }
            $amount = number_format((float) $normalized, 2, '.', '');
        }

        $closeRaw = trim((string) ($data['expected_close_at'] ?? ''));
        $closeAt = null;
        if ($closeRaw !== '') {
            $closeAt = new \DateTimeImmutable($closeRaw);
        }

        $stage = (string) ($data['stage'] ?? 'lead');
        if (!in_array($stage, Deal::STAGES, true)) {
            $stage = 'lead';
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CZK')));
        if ($currency === '') {
            $currency = 'CZK';
        }

        $note = trim((string) ($data['note'] ?? ''));

        return [
            'customer_id' => $customerId,
            'title' => trim((string) $data['title']),
            'amount' => $amount,
            'currency' => $currency,
            'stage' => $stage,
            'expected_close_at' => $closeAt,
            'note' => $note !== '' ? $note : null,
        ];
    }

    /**
     * @param list<Deal> $deals
     * @return array<string, Customer>
     */
    private function customerMap(array $deals): array
    {
        $ids = array_values(array_unique(array_map(static fn (Deal $d): string => $d->customerId(), $deals)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $customer) {
            /** @var Customer $customer */
            $map[$customer->id()] = $customer;
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        $rows = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static function (Customer $c): array {
                $label = $c->name();
                if ($c->company()) {
                    $label .= ' · ' . $c->company();
                }

                return ['value' => $c->id(), 'label' => $label];
            },
            $rows
        );
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
