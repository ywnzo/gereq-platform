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
use Modules\Newsletter\Domain\Campaign;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CampaignController extends Controller
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
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['subject', 'status', 'createdAt', 'sentAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(Campaign::class)->createQueryBuilder('c');
        $list->applySearch($qb, 'c', ['subject']);
        if (in_array($status, Campaign::STATUSES, true)) {
            $qb->andWhere('c.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('c.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@newsletter/campaigns/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('newsletter.campaigns.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => Campaign::STATUSES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@newsletter/campaigns/form.html.twig', [
            'pageTitle' => $this->translator->trans('newsletter.campaigns.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $campaign = new Campaign($data['subject'], $data['body_html']);
        $this->em->persist($campaign);
        $this->em->flush();
        $this->events->emit('newsletter.campaign_created', ['type' => 'newsletter_campaign', 'id' => $campaign->id()], $this->public($campaign));
        $this->auditor->log('newsletter.campaign.created', 'newsletter_campaign', $campaign->id());
        $this->forgetForm('newsletter.campaign.save');
        $this->flash('success', $this->translator->trans('newsletter.flash.campaign_created'));

        return $this->redirect('admin.newsletter.campaigns.edit', ['id' => $campaign->id()]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.campaign_not_found'));
        }

        return $this->page('@newsletter/campaigns/form.html.twig', [
            'pageTitle' => $this->translator->trans('newsletter.campaigns.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.campaign_not_found'));
        }
        $data = $this->validated($request);
        $item->setSubject($data['subject']);
        $item->setBodyHtml($data['body_html']);
        $this->em->flush();
        $this->auditor->log('newsletter.campaign.updated', 'newsletter_campaign', $id);
        $this->forgetForm('newsletter.campaign.save');
        $this->flash('success', $this->translator->trans('newsletter.flash.campaign_saved'));

        return $this->redirect('admin.newsletter.campaigns.edit', ['id' => $id]);
    }

    public function markSent(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.campaign_not_found'));
        }
        $item->markSent();
        $this->em->flush();
        $this->events->emit('newsletter.campaign_sent', ['type' => 'newsletter_campaign', 'id' => $id], $this->public($item));
        $this->flash('success', $this->translator->trans('newsletter.flash.campaign_sent'));

        return $this->redirect('admin.newsletter.campaigns.edit', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('newsletter.error.campaign_not_found'));
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log('newsletter.campaign.deleted', 'newsletter_campaign', $id);
        $this->flash('success', $this->translator->trans('newsletter.flash.campaign_deleted'));

        return $this->redirect('admin.newsletter.campaigns.index');
    }

    private function find(string $id): ?Campaign
    {
        $item = $this->em->find(Campaign::class, $id);

        return $item instanceof Campaign ? $item : null;
    }

    /** @return array{subject: string, body_html: string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'newsletter.campaign.save');

        return [
            'subject' => trim((string) $data['subject']),
            'body_html' => (string) $data['body_html'],
        ];
    }

    /** @return array<string, mixed> */
    private function public(Campaign $c): array
    {
        return [
            'id' => $c->id(),
            'subject' => $c->subject(),
            'body_html' => $c->bodyHtml(),
            'status' => $c->status(),
            'sent_at' => $c->sentAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $c->createdAt()->format(\DateTimeInterface::ATOM),
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
