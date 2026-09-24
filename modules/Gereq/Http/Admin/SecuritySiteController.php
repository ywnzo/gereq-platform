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
use Modules\Gereq\Domain\SecurityEvent;
use Modules\Gereq\Domain\SecuritySite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SecuritySiteController extends Controller
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
        private readonly Repository $config,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest($request, sortable: ['name', 'createdAt'], defaultSort: 'createdAt', defaultDir: 'desc');
        $qb = $this->em->getRepository(SecuritySite::class)->createQueryBuilder('s');
        $list->applySearch($qb, 's', ['name', 'publicKey']);
        $qb->orderBy('s.' . $list->sort, $list->direction());
        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@gereq/security/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('gereq.security.title'),
            'items' => $items,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@gereq/security/form.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.security.new'),
            'site' => null,
            'modes' => SecuritySite::MODES,
        ]);
    }

    public function store(Request $request): Response
    {
        $site = new SecuritySite(trim((string) $request->request->get('name', 'Security site')));
        $this->applyForm($site, $request);
        $plain = $site->consumeIssuedSecret();
        $this->em->persist($site);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.security.created'));
        if (is_string($plain) && $plain !== '') {
            $request->getSession()?->set('gereq.security.secret.' . $site->id(), $plain);
        }

        return $this->redirect('admin.gereq.security.show', ['id' => $site->id()]);
    }

    public function show(string $id): Response
    {
        $site = $this->find($id);
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityEvent::class, 'e')
            ->where('e.securitySiteId = :id')
            ->setParameter('id', $id)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
        $secret = $this->request()?->getSession()?->get('gereq.security.secret.' . $id);
        $this->request()?->getSession()?->remove('gereq.security.secret.' . $id);
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        return $this->page('@gereq/security/show.html.twig', [
            'pageTitle' => $site->name(),
            'site' => $site,
            'events' => $events,
            'plainSecret' => is_string($secret) ? $secret : null,
            'embedSnippet' => $site->embedSnippet($base),
            'monitorSnippet' => $site->monitorSnippet($base),
            'modes' => SecuritySite::MODES,
        ]);
    }

    public function edit(string $id): Response
    {
        $site = $this->find($id);

        return $this->page('@gereq/security/form.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.security.edit'),
            'site' => $site,
            'modes' => SecuritySite::MODES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $site = $this->find($id);
        $this->applyForm($site, $request);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.security.updated'));

        return $this->redirect('admin.gereq.security.show', ['id' => $id]);
    }

    public function rotate(string $id): Response
    {
        $site = $this->find($id);
        $plain = $site->rotateSecret();
        $this->em->flush();
        $this->request()?->getSession()?->set('gereq.security.secret.' . $id, $plain);
        $this->flash('success', $this->translator->trans('gereq.security.secret_rotated'));

        return $this->redirect('admin.gereq.security.show', ['id' => $id]);
    }

    public function issueTestKeys(string $id): Response
    {
        $site = $this->find($id);
        $keys = $site->issueTestKeys();
        $this->em->flush();
        $this->request()?->getSession()?->set('gereq.security.secret.' . $id, $keys['secret']);
        $this->flash('success', $this->translator->trans('gereq.security.test_keys_issued'));

        return $this->redirect('admin.gereq.security.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $site = $this->find($id);
        $this->em->remove($site);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.security.deleted'));

        return $this->redirect('admin.gereq.security.index');
    }

    private function applyForm(SecuritySite $site, Request $request): void
    {
        $site->setName((string) $request->request->get('name', $site->name()));
        $site->setAllowedOrigins((string) $request->request->get('allowed_origins', ''));
        $site->setFormEnabled($request->request->getBoolean('form_enabled'));
        $site->setFormCaptchaAlways($request->request->getBoolean('form_captcha_always'));
        $site->setMonitorEnabled($request->request->getBoolean('monitor_enabled'));
        $site->setProtectionMode((string) $request->request->get('protection_mode', 'observe'));
        $site->setPrimaryColor((string) $request->request->get('primary_color', '#0A1F38'));
        $site->setBadgeText($request->request->get('badge_text') !== null ? (string) $request->request->get('badge_text') : null);
        $site->setCaptchaDifficulty((int) $request->request->get('captcha_difficulty', 2));
        $site->setActive($request->request->getBoolean('is_active', true));
    }

    private function find(string $id): SecuritySite
    {
        $site = $this->em->find(SecuritySite::class, $id);
        if (!$site instanceof SecuritySite) {
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
