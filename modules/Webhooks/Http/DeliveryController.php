<?php

declare(strict_types=1);

namespace Modules\Webhooks\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Webhooks\Application\WebhookDispatcher;
use Modules\Webhooks\Domain\Webhook;
use Modules\Webhooks\Domain\WebhookDelivery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeliveryController extends Controller
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
        private readonly WebhookDispatcher $dispatcher,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $this->dispatcher->processDueRetries();

        $webhookId = trim((string) $request->query->get('webhook', ''));
        $status = (string) $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 30)));

        $qb = $this->em->getRepository(WebhookDelivery::class)->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC');

        if ($webhookId !== '') {
            $qb->andWhere('d.webhookId = :webhook')->setParameter('webhook', $webhookId);
        }
        if (in_array($status, WebhookDelivery::STATUSES, true)) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        $webhookIds = array_values(array_unique(array_map(
            static fn (WebhookDelivery $d): string => $d->webhookId(),
            $items,
        )));
        $webhooks = [];
        if ($webhookIds !== []) {
            foreach ($this->em->getRepository(Webhook::class)->findBy(['id' => $webhookIds]) as $webhook) {
                /** @var Webhook $webhook */
                $webhooks[$webhook->id()] = $webhook;
            }
        }

        $filterWebhook = $webhookId !== ''
            ? $this->em->find(Webhook::class, $webhookId)
            : null;

        return $this->page('@webhooks/deliveries/index.html.twig', [
            'pageTitle' => $this->translator->trans('webhooks.delivery.title'),
            'items' => $items,
            'webhooks' => $webhooks,
            'webhookId' => $webhookId,
            'filterWebhook' => $filterWebhook instanceof Webhook ? $filterWebhook : null,
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
        ]);
    }

    public function show(string $id): Response
    {
        $delivery = $this->find($id);
        if ($delivery === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.delivery_not_found'));
        }

        $webhook = $this->em->find(Webhook::class, $delivery->webhookId());

        return $this->page('@webhooks/deliveries/show.html.twig', [
            'pageTitle' => $this->translator->trans('webhooks.delivery.show') . ' ' . $delivery->id(),
            'item' => $delivery,
            'webhook' => $webhook instanceof Webhook ? $webhook : null,
        ]);
    }

    public function retry(string $id): Response
    {
        $delivery = $this->find($id);
        if ($delivery === null) {
            $this->abort(404, $this->translator->trans('webhooks.error.delivery_not_found'));
        }

        $webhook = $this->em->find(Webhook::class, $delivery->webhookId());
        if (!$webhook instanceof Webhook) {
            $this->abort(404, $this->translator->trans('webhooks.error.not_found'));
        }

        $headers = $delivery->requestHeaders();
        unset($headers['X-GEREQ-Signature'], $headers['X-GEREQ-Timestamp']);

        $retry = new WebhookDelivery(
            $webhook->id(),
            $delivery->eventId(),
            $delivery->eventType(),
            $webhook->url(),
            $headers,
            $delivery->requestBody(),
            1,
        );
        $headers['X-GEREQ-Delivery'] = $retry->id();
        $retry->setRequestHeaders($headers);

        $this->em->persist($retry);
        $this->em->flush();
        $this->dispatcher->enqueue($retry);
        $this->auditor->log('webhook.delivery.retried', 'webhook_delivery', $retry->id(), null, [
            'source_delivery_id' => $delivery->id(),
            'webhook_id' => $webhook->id(),
        ]);
        $this->flash('success', $this->translator->trans('webhooks.flash.retry_queued'));

        return $this->redirect('admin.developer.webhook-deliveries.show', ['id' => $retry->id()]);
    }

    private function find(string $id): ?WebhookDelivery
    {
        $delivery = $this->em->find(WebhookDelivery::class, $id);

        return $delivery instanceof WebhookDelivery ? $delivery : null;
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
