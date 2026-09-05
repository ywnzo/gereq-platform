<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Newsletter\Application\NewsletterProvider;
use Modules\Newsletter\Domain\Subscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SubscriberController extends Controller
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
        private readonly NewsletterProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['email', 'status', 'subscribedAt', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(Subscriber::class)->createQueryBuilder('s');
        $list->applySearch($qb, 's', ['email', 'name']);
        if (in_array($status, Subscriber::STATUSES, true)) {
            $qb->andWhere('s.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('s.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@newsletter/subscribers/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('newsletter.subscribers.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => Subscriber::STATUSES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@newsletter/subscribers/form.html.twig', [
            'pageTitle' => $this->translator->trans('newsletter.subscribers.new'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'newsletter.subscriber.save');
        $subscriber = $this->provider->subscribe(
            trim((string) $data['email']),
            trim((string) ($data['name'] ?? '')) ?: null,
            trim((string) ($data['source'] ?? '')) ?: 'admin',
        );
        $this->events->emit('newsletter.subscribed', ['type' => 'newsletter_subscriber', 'id' => $subscriber->id()], $this->public($subscriber));
        $this->auditor->log('newsletter.subscriber.created', 'newsletter_subscriber', $subscriber->id());
        $this->forgetForm('newsletter.subscriber.save');
        $this->flash('success', $this->translator->trans('newsletter.flash.subscribed', ['%email%' => $subscriber->email()]));

        return $this->redirect('admin.newsletter.subscribers.index');
    }

    public function unsubscribe(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.subscriber_not_found'));
        }
        $item->unsubscribe();
        $this->em->flush();
        $this->events->emit('newsletter.unsubscribed', ['type' => 'newsletter_subscriber', 'id' => $id], $this->public($item));
        $this->flash('success', $this->translator->trans('newsletter.flash.unsubscribed', ['%email%' => $item->email()]));

        return $this->redirect('admin.newsletter.subscribers.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.subscriber_not_found'));
        }
        $email = $item->email();
        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log('newsletter.subscriber.deleted', 'newsletter_subscriber', $id);
        $this->flash('success', $this->translator->trans('newsletter.flash.subscriber_deleted', ['%email%' => $email]));

        return $this->redirect('admin.newsletter.subscribers.index');
    }

    private function find(string $id): ?Subscriber
    {
        $item = $this->em->find(Subscriber::class, $id);

        return $item instanceof Subscriber ? $item : null;
    }

    /** @return array<string, mixed> */
    private function public(Subscriber $s): array
    {
        return [
            'id' => $s->id(),
            'email' => $s->email(),
            'name' => $s->name(),
            'status' => $s->status(),
            'source' => $s->source(),
            'subscribed_at' => $s->subscribedAt()->format(\DateTimeInterface::ATOM),
            'unsubscribed_at' => $s->unsubscribedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $s->createdAt()->format(\DateTimeInterface::ATOM),
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
