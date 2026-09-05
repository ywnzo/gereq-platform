<?php

declare(strict_types=1);

namespace Modules\Feeds\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Feeds\Application\FeedsProvider;
use Modules\Feeds\Domain\FeedDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FeedController extends Controller
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
        private readonly FeedsProvider $feeds,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(FeedDefinition::class)->createQueryBuilder('f')
            ->orderBy('f.code', 'ASC');
        self_repair_search($qb, 'f', ['code', 'name'], $search);

        return $this->page('@feeds/index.html.twig', [
            'pageTitle' => $this->translator->trans('feeds.title'),
            'items' => $qb->getQuery()->getResult(),
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@feeds/form.html.twig', [
            'pageTitle' => $this->translator->trans('feeds.new'),
            'item' => null,
            'configJson' => "{\n  \"currency\": \"CZK\",\n  \"base_url\": \"\"\n}",
            'drivers' => FeedDefinition::DRIVERS,
            'formats' => FeedDefinition::FORMATS,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(FeedDefinition::class)->findOneBy(['code' => $data['code']]) instanceof FeedDefinition) {
            $this->abort(422, $this->translator->trans('feeds.error.code_taken'));
        }

        $feed = new FeedDefinition($data['code'], $data['name'], $data['driver']);
        $this->applyData($feed, $data);
        $this->em->persist($feed);
        $this->em->flush();

        $payload = $this->publicFeed($feed);
        $this->events->emit('feed.created', ['type' => 'feed_definition', 'id' => $feed->id()], $payload);
        $this->auditor->log('feeds.created', 'feed_definition', $feed->id(), null, [
            'code' => $feed->code(),
            'name' => $feed->name(),
        ]);
        $this->forgetForm('feeds.save');
        $this->flash('success', $this->translator->trans('feeds.flash.created', ['%name%' => $feed->name()]));

        return $this->redirect('admin.feeds.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('feeds.error.not_found'));
        }

        return $this->page('@feeds/form.html.twig', [
            'pageTitle' => $this->translator->trans('feeds.edit'),
            'item' => $item,
            'configJson' => json_encode($item->config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'drivers' => FeedDefinition::DRIVERS,
            'formats' => FeedDefinition::FORMATS,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('feeds.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(FeedDefinition::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof FeedDefinition && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('feeds.error.code_taken'));
        }

        $old = ['code' => $item->code(), 'name' => $item->name()];
        $item->setCode($data['code']);
        $item->setName($data['name']);
        $this->applyData($item, $data);
        $this->em->flush();

        $payload = $this->publicFeed($item);
        $this->events->emit('feed.updated', ['type' => 'feed_definition', 'id' => $item->id()], $payload);
        $this->auditor->log('feeds.updated', 'feed_definition', $item->id(), $old, [
            'code' => $item->code(),
            'name' => $item->name(),
        ]);
        $this->forgetForm('feeds.save');
        $this->flash('success', $this->translator->trans('feeds.flash.saved'));

        return $this->redirect('admin.feeds.index');
    }

    public function preview(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('feeds.error.not_found'));
        }

        $generated = $this->feeds->generate($item->code());
        if ($generated === null) {
            $this->abort(422, $this->translator->trans('feeds.error.generate_failed'));
        }

        return $this->page('@feeds/preview.html.twig', [
            'pageTitle' => $this->translator->trans('feeds.preview'),
            'item' => $item,
            'contentType' => $generated['content_type'],
            'body' => $generated['body'],
        ]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('feeds.error.not_found'));
        }

        $name = $item->name();
        $payload = $this->publicFeed($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('feed.deleted', ['type' => 'feed_definition', 'id' => $id], $payload);
        $this->auditor->log('feeds.deleted', 'feed_definition', $id);
        $this->flash('success', $this->translator->trans('feeds.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.feeds.index');
    }

    private function find(string $id): ?FeedDefinition
    {
        $item = $this->em->find(FeedDefinition::class, $id);

        return $item instanceof FeedDefinition ? $item : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     driver: string,
     *     format: string,
     *     config: ?array<string, mixed>,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'feeds.save');
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'code' => strtolower(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'driver' => strtolower(trim((string) ($data['driver'] ?? 'google_merchant'))),
            'format' => strtolower(trim((string) ($data['format'] ?? 'xml'))),
            'config' => $this->parseConfig((string) ($data['config_json'] ?? $request->request->get('config_json', '{}'))),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseConfig(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array{
     *     driver: string,
     *     format: string,
     *     config: ?array<string, mixed>,
     *     active: bool
     * } $data
     */
    private function applyData(FeedDefinition $feed, array $data): void
    {
        $feed->setDriver($data['driver']);
        $feed->setFormat($data['format']);
        $feed->setConfig($data['config']);
        $feed->setActive($data['active']);
    }

    /** @return array<string, mixed> */
    private function publicFeed(FeedDefinition $feed): array
    {
        return [
            'id' => $feed->id(),
            'code' => $feed->code(),
            'name' => $feed->name(),
            'driver' => $feed->driver(),
            'format' => $feed->format(),
            'active' => $feed->isActive(),
            'config' => $feed->config(),
            'created_at' => $feed->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $feed->updatedAt()->format(\DateTimeInterface::ATOM),
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
