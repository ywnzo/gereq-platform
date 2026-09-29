<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Event\EventRegistry;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationException;
use Gereq\Webhooks\Application\SsrfGuard;
use Gereq\Webhooks\Domain\Webhook;
use Gereq\Webhooks\Domain\WebhookDelivery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class WebhookController extends Controller
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
        private readonly EventRegistry $eventRegistry,
        private readonly SsrfGuard $ssrf,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->getRepository(Webhook::class)->createQueryBuilder('w')
            ->orderBy('w.createdAt', 'DESC');

        self_repair_search($qb, 'w', ['name', 'url'], $search);
        if (in_array($status, Webhook::STATUSES, true)) {
            $qb->andWhere('w.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $qb)->select('COUNT(w.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return $this->page('webhooks/webhooks/index.html.twig', [
            'pageTitle' => $this->translator->trans('webhooks.title'),
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
        ]);
    }

    public function create(): Response
    {
        return $this->page('webhooks/webhooks/form.html.twig', [
            'pageTitle' => $this->translator->trans('webhooks.new'),
            'item' => null,
            'availableEvents' => $this->availableEvents(),
            'defaultTimeout' => (int) config('api.webhooks.timeout_seconds', 10),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $webhook = new Webhook($data['name'], $data['url'], $data['events'], $data['timeout_seconds']);
        $this->em->persist($webhook);
        $this->em->flush();
        $this->auditor->log('webhook.created', 'webhook', $webhook->id(), null, [
            'name' => $webhook->name(),
            'url' => $webhook->url(),
        ]);
        $this->forgetForm('webhooks.save');
        $this->flash('success', $this->translator->trans('webhooks.flash.created', ['%name%' => $webhook->name(), '%secret%' => $webhook->secret()]));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $webhook->id()]);
    }

    public function show(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $deliveries = $this->em->getRepository(WebhookDelivery::class)->findBy(
            ['webhookId' => $webhook->id()],
            ['createdAt' => 'DESC'],
            20,
        );

        return $this->page('webhooks/webhooks/show.html.twig', [
            'pageTitle' => $webhook->name(),
            'item' => $webhook,
            'deliveries' => $deliveries,
        ]);
    }

    public function edit(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        return $this->page('webhooks/webhooks/form.html.twig', [
            'pageTitle' => $this->translator->trans('webhooks.edit'),
            'item' => $webhook,
            'availableEvents' => $this->availableEvents(),
            'defaultTimeout' => $webhook->timeoutSeconds(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $data = $this->validated($request);
        $old = ['name' => $webhook->name(), 'url' => $webhook->url(), 'status' => $webhook->status()];
        $webhook->setName($data['name']);
        $webhook->setUrl($data['url']);
        $webhook->setEvents($data['events']);
        $webhook->setTimeoutSeconds($data['timeout_seconds']);
        $this->em->flush();
        $this->auditor->log('webhook.updated', 'webhook', $webhook->id(), $old, [
            'name' => $webhook->name(),
            'url' => $webhook->url(),
            'status' => $webhook->status(),
        ]);
        $this->forgetForm('webhooks.save');
        $this->flash('success', $this->translator->trans('webhooks.flash.saved'));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $name = $webhook->name();
        $this->em->remove($webhook);
        $this->em->flush();
        $this->auditor->log('webhook.deleted', 'webhook', $id);
        $this->flash('success', $this->translator->trans('webhooks.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.developer.webhooks.index');
    }

    public function test(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $this->events->emit(
            'webhook.test',
            ['type' => 'webhook', 'id' => $webhook->id()],
            [
                'webhook_id' => $webhook->id(),
                'webhook_name' => $webhook->name(),
                'message' => 'Testovací událost z GEREQ Platform.',
            ],
        );
        $this->flash('success', $this->translator->trans('webhooks.flash.test_sent'));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
    }

    public function rotateSecret(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $secret = $webhook->rotateSecret();
        $this->em->flush();
        $this->auditor->log('webhook.secret_rotated', 'webhook', $webhook->id());
        $this->flash('success', $this->translator->trans('webhooks.flash.secret_rotated', ['%secret%' => $secret]));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
    }

    public function pause(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $webhook->pause();
        $this->em->flush();
        $this->flash('success', $this->translator->trans('webhooks.flash.paused'));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
    }

    public function activate(string $id): Response
    {
        $webhook = $this->find($id);
        if ($webhook === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        try {
            $this->ssrf->assertSafe($webhook->url());
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
        }

        $webhook->activate();
        $this->em->flush();
        $this->flash('success', $this->translator->trans('webhooks.flash.activated'));

        return $this->redirect('admin.developer.webhooks.show', ['id' => $id]);
    }

    private function find(string $id): ?Webhook
    {
        $webhook = $this->em->find(Webhook::class, $id);

        return $webhook instanceof Webhook ? $webhook : null;
    }

    /**
     * @return array{name: string, url: string, events: list<string>, timeout_seconds: int}
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'webhooks.save');

        $name = trim((string) $data['name']);
        $url = trim((string) $data['url']);
        $timeout = (int) ($data['timeout_seconds'] ?? config('api.webhooks.timeout_seconds', 10));
        if ($timeout < 1) {
            $timeout = 1;
        }
        if ($timeout > 120) {
            $timeout = 120;
        }

        try {
            $this->ssrf->assertSafe($url);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['url' => $e->getMessage()], [
                'name' => $name,
                'url' => $url,
                'timeout_seconds' => $timeout,
            ], 'webhooks.save');
        }

        /** @var list<string>|string|null $rawEvents */
        $rawEvents = $request->request->all('events') ?: ($data['events'] ?? []);
        if (is_string($rawEvents)) {
            $rawEvents = array_filter(array_map('trim', explode(',', $rawEvents)));
        }
        if (!is_array($rawEvents)) {
            $rawEvents = [];
        }

        $custom = trim((string) $request->request->get('events_custom', ''));
        if ($custom !== '') {
            foreach (preg_split('/[\s,]+/', $custom) ?: [] as $part) {
                $rawEvents[] = $part;
            }
        }

        $events = [];
        foreach ($rawEvents as $event) {
            $event = trim((string) $event);
            if ($event === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_.*-]+$/i', $event)) {
                continue;
            }
            $events[] = $event;
        }
        $events = array_values(array_unique($events));
        if ($events === []) {
            $events = ['*'];
        }

        return [
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'timeout_seconds' => $timeout,
        ];
    }

    /** @return list<array{type: string, description: string}> */
    private function availableEvents(): array
    {
        $items = [];
        foreach ($this->eventRegistry->webhookEnabled() as $definition) {
            $items[] = [
                'type' => $definition->type,
                'description' => $definition->description !== '' ? $definition->description : $definition->type,
            ];
        }

        return $items;
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
