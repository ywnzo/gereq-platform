<?php

declare(strict_types=1);

namespace Modules\Viewings\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Viewings\Application\ViewingsProvider;
use Modules\Viewings\Domain\Viewing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ViewingController extends Controller
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
        private readonly ViewingsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['status', 'number', 'scheduledAt', 'createdAt'],
            defaultSort: 'scheduledAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(Viewing::class)->createQueryBuilder('v');
        $list->applySearch($qb, 'v', ['number', 'listingId', 'contactName', 'contactEmail', 'contactPhone']);
        if (in_array($status, Viewing::STATUSES, true)) {
            $qb->andWhere('v.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('v.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@viewings/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('viewings.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => Viewing::STATUSES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@viewings/form.html.twig', [
            'pageTitle' => $this->translator->trans('viewings.new'),
            'item' => null,
            'statuses' => Viewing::STATUSES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $item = new Viewing($this->provider->nextNumber(), $data['listing_id'], $data['contact_name']);
        $this->apply($item, $data);
        $this->em->persist($item);
        $this->em->flush();

        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.created', ['type' => 'viewing', 'id' => $item->id()], $payload);
        $this->auditor->log('viewings.created', 'viewing', $item->id(), null, ['number' => $item->number()]);
        $this->forgetForm('viewings.save');
        $this->flash('success', $this->translator->trans('viewings.flash.created', ['%number%' => $item->number()]));

        return $this->redirect('admin.viewings.show', ['id' => $item->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('viewings.error.not_found'));
        }

        return $this->page('@viewings/show.html.twig', [
            'pageTitle' => $item->number(),
            'item' => $item,
            'statuses' => Viewing::STATUSES,
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('viewings.error.not_found'));
        }

        return $this->page('@viewings/form.html.twig', [
            'pageTitle' => $this->translator->trans('viewings.edit', ['%number%' => $item->number()]),
            'item' => $item,
            'statuses' => Viewing::STATUSES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('viewings.error.not_found'));
        }
        $data = $this->validated($request);
        $this->apply($item, $data);
        $this->em->flush();

        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.updated', ['type' => 'viewing', 'id' => $id], $payload);
        $this->auditor->log('viewings.updated', 'viewing', $id);
        $this->forgetForm('viewings.save');
        $this->flash('success', $this->translator->trans('viewings.flash.updated'));

        return $this->redirect('admin.viewings.show', ['id' => $id]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('viewings.error.not_found'));
        }
        $status = (string) $request->request->get('status', '');
        if (!in_array($status, Viewing::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('viewings.error.invalid_status'));

            return $this->redirect('admin.viewings.show', ['id' => $id]);
        }
        $item->setStatus($status);
        if ($request->request->has('note')) {
            $item->setNote(trim((string) $request->request->get('note', '')));
        }
        $this->em->flush();

        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.status_changed', ['type' => 'viewing', 'id' => $id], $payload);
        $this->events->emit('viewing.updated', ['type' => 'viewing', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('viewings.flash.status_changed'));

        return $this->redirect('admin.viewings.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('viewings.error.not_found'));
        }
        $number = $item->number();
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('viewing.deleted', ['type' => 'viewing', 'id' => $id], ['id' => $id, 'number' => $number]);
        $this->auditor->log('viewings.deleted', 'viewing', $id);
        $this->flash('success', $this->translator->trans('viewings.flash.deleted', ['%number%' => $number]));

        return $this->redirect('admin.viewings.index');
    }

    /**
     * @return array{
     *   listing_id: string,
     *   customer_id: ?string,
     *   broker_id: ?string,
     *   status: string,
     *   scheduled_at: ?\DateTimeImmutable,
     *   contact_name: string,
     *   contact_email: ?string,
     *   contact_phone: ?string,
     *   note: ?string
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'viewings.save');
        $status = (string) ($data['status'] ?? 'scheduled');
        if (!in_array($status, Viewing::STATUSES, true)) {
            $status = 'scheduled';
        }

        return [
            'listing_id' => trim((string) $data['listing_id']),
            'customer_id' => trim((string) ($data['customer_id'] ?? '')) ?: null,
            'broker_id' => trim((string) ($data['broker_id'] ?? '')) ?: null,
            'status' => $status,
            'scheduled_at' => $this->parseDateTime(trim((string) ($data['scheduled_at'] ?? ''))),
            'contact_name' => trim((string) $data['contact_name']),
            'contact_email' => trim((string) ($data['contact_email'] ?? '')) ?: null,
            'contact_phone' => trim((string) ($data['contact_phone'] ?? '')) ?: null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }

    /**
     * @param array{
     *   listing_id: string,
     *   customer_id: ?string,
     *   broker_id: ?string,
     *   status: string,
     *   scheduled_at: ?\DateTimeImmutable,
     *   contact_name: string,
     *   contact_email: ?string,
     *   contact_phone: ?string,
     *   note: ?string
     * } $data
     */
    private function apply(Viewing $item, array $data): void
    {
        $item->setListingId($data['listing_id']);
        $item->setCustomerId($data['customer_id']);
        $item->setBrokerId($data['broker_id']);
        $item->setStatus($data['status']);
        $item->setScheduledAt($data['scheduled_at']);
        $item->setContactName($data['contact_name']);
        $item->setContactEmail($data['contact_email']);
        $item->setContactPhone($data['contact_phone']);
        $item->setNote($data['note']);
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
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
