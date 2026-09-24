<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Gereq\Application\Ticketing\TicketService;
use Modules\Gereq\Domain\Ticket;
use Modules\Gereq\Domain\TicketSite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TicketingController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly TicketService $tickets,
        private readonly Repository $config,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function desk(Request $request): Response
    {
        $status = (string) $request->query->get('status', '');
        $qb = $this->em->createQueryBuilder()->select('t')->from(Ticket::class, 't');
        if (in_array($status, Ticket::STATUSES, true)) {
            $qb->andWhere('t.status = :st')->setParameter('st', $status);
        }
        $qb->orderBy('t.lastMessageAt', 'DESC')->setMaxResults(100);
        $items = $qb->getQuery()->getResult();
        $activeId = (string) $request->query->get('ticket', '');
        $active = $activeId !== '' ? $this->em->find(Ticket::class, $activeId) : null;
        $messages = $active instanceof Ticket ? $this->tickets->messagesForTicket($active->id()) : [];

        return $this->page('@gereq/ticketing/desk.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.ticketing.desk'),
            'items' => $items,
            'active' => $active instanceof Ticket ? $active : null,
            'messages' => $messages,
            'statuses' => Ticket::STATUSES,
            'status' => $status,
            'pollUrl' => $active instanceof Ticket
                ? $this->router->url('admin.gereq.ticketing.poll', ['id' => $active->id()])
                : null,
        ]);
    }

    public function poll(string $id): Response
    {
        $ticket = $this->em->find(Ticket::class, $id);
        if (!$ticket instanceof Ticket) {
            return $this->jsonError('not_found', 'Ticket not found.', [], 404);
        }
        $messages = $this->tickets->messagesForTicket($ticket->id());

        return $this->json([
            'ticket' => $this->tickets->ticketPayload($ticket),
            'messages' => $this->tickets->messagePayloads($messages),
        ]);
    }

    public function reply(Request $request, string $id): Response
    {
        $ticket = $this->em->find(Ticket::class, $id);
        if (!$ticket instanceof Ticket) {
            $this->abort(404);
        }
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(401);
        }
        $body = trim((string) $request->request->get('body', ''));
        $internal = $request->request->getBoolean('internal');
        if ($body === '') {
            $this->flash('error', $this->translator->trans('gereq.ticketing.empty_reply'));

            return $this->redirectTo($this->router->url('admin.gereq.ticketing.desk') . '?ticket=' . urlencode($id));
        }
        $this->tickets->addAgentMessage($ticket, $body, $user->id(), $internal);
        $this->flash('success', $this->translator->trans('gereq.ticketing.replied'));

        return $this->redirectTo($this->router->url('admin.gereq.ticketing.desk') . '?ticket=' . urlencode($id));
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $ticket = $this->em->find(Ticket::class, $id);
        if (!$ticket instanceof Ticket) {
            $this->abort(404);
        }
        $ticket->setStatus((string) $request->request->get('status', $ticket->status()));
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.status_updated'));

        return $this->redirectTo($this->router->url('admin.gereq.ticketing.desk') . '?ticket=' . urlencode($id));
    }

    public function sites(Request $request): Response
    {
        $list = ListQuery::fromRequest($request, sortable: ['name', 'createdAt'], defaultSort: 'createdAt', defaultDir: 'desc');
        $qb = $this->em->getRepository(TicketSite::class)->createQueryBuilder('s');
        $list->applySearch($qb, 's', ['name', 'publicKey']);
        $qb->orderBy('s.' . $list->sort, $list->direction());
        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@gereq/ticketing/sites.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('gereq.ticketing.sites'),
            'items' => $items,
        ]));
    }

    public function createSite(): Response
    {
        return $this->page('@gereq/ticketing/site_form.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.ticketing.site_new'),
            'site' => null,
            'strategies' => TicketSite::STRATEGIES,
        ]);
    }

    public function storeSite(Request $request): Response
    {
        $site = new TicketSite(trim((string) $request->request->get('name', 'Ticket site')));
        $this->applySite($site, $request);
        $this->em->persist($site);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.site_created'));

        return $this->redirect('admin.gereq.ticketing.sites.show', ['id' => $site->id()]);
    }

    public function showSite(string $id): Response
    {
        $site = $this->findSite($id);
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        return $this->page('@gereq/ticketing/site_show.html.twig', [
            'pageTitle' => $site->name(),
            'site' => $site,
            'embedSnippet' => $site->embedSnippet($base),
            'strategies' => TicketSite::STRATEGIES,
        ]);
    }

    public function editSite(string $id): Response
    {
        $site = $this->findSite($id);

        return $this->page('@gereq/ticketing/site_form.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.ticketing.site_edit'),
            'site' => $site,
            'strategies' => TicketSite::STRATEGIES,
        ]);
    }

    public function updateSite(Request $request, string $id): Response
    {
        $site = $this->findSite($id);
        $this->applySite($site, $request);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.site_updated'));

        return $this->redirect('admin.gereq.ticketing.sites.show', ['id' => $id]);
    }

    public function issueTestKey(string $id): Response
    {
        $site = $this->findSite($id);
        $site->issueTestKey();
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.test_key_issued'));

        return $this->redirect('admin.gereq.ticketing.sites.show', ['id' => $id]);
    }

    public function destroySite(string $id): Response
    {
        $site = $this->findSite($id);
        $this->em->remove($site);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.site_deleted'));

        return $this->redirect('admin.gereq.ticketing.sites');
    }

    private function applySite(TicketSite $site, Request $request): void
    {
        $site->setName((string) $request->request->get('name', $site->name()));
        $site->setAllowedOrigins((string) $request->request->get('allowed_origins', ''));
        $site->setWelcomeMessage((string) $request->request->get('welcome_message', '') ?: null);
        $site->setOfflineMessage((string) $request->request->get('offline_message', '') ?: null);
        $site->setPrimaryColor((string) $request->request->get('primary_color', '#0A1F38'));
        $site->setActive($request->request->getBoolean('is_active', true));
        $site->setAgentsOnline($request->request->getBoolean('agents_online', true));
        $site->setShowTeaser($request->request->getBoolean('show_teaser'));
        $site->setAssignmentStrategy((string) $request->request->get('assignment_strategy', 'unassigned'));
    }

    private function findSite(string $id): TicketSite
    {
        $site = $this->em->find(TicketSite::class, $id);
        if (!$site instanceof TicketSite) {
            $this->abort(404);
        }

        return $site;
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
