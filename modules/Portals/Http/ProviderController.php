<?php

declare(strict_types=1);

namespace Modules\Portals\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Portals\Application\OpenApiSpecReader;
use Modules\Portals\Domain\PortalAccount;
use Modules\Portals\Domain\PortalProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProviderController extends Controller
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
        private readonly TranslatorInterface $translator,
        private readonly OpenApiSpecReader $openApi,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'code', 'sortOrder', 'driver'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: [],
        );

        $qb = $this->em->getRepository(PortalProvider::class)->createQueryBuilder('p');
        $list->applySearch($qb, 'p', ['name', 'code', 'description']);
        $qb->orderBy('p.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@portals/providers/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('portals.providers.title'),
            'items' => $items,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@portals/providers/form.html.twig', [
            'pageTitle' => $this->translator->trans('portals.providers.new'),
            'item' => null,
            'drivers' => PortalProvider::DRIVERS,
            'fieldTypes' => PortalProvider::FIELD_TYPES,
            'fieldRows' => $this->emptyFieldRows(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(PortalProvider::class)->findOneBy(['code' => $data['code']]);
        if ($existing instanceof PortalProvider) {
            $this->flash('error', $this->translator->trans('portals.providers.error.code_exists'));

            return $this->redirect('admin.portals.providers.create');
        }

        $item = new PortalProvider($data['code'], $data['name'], $data['driver']);
        $this->apply($item, $data);
        $this->em->persist($item);
        $this->em->flush();
        $this->auditor->log('portals.provider_created', 'portal_provider', $item->id(), null, ['code' => $item->code()]);
        $this->forgetForm('portals.provider.save');
        $this->flash('success', $this->translator->trans('portals.providers.flash.created'));

        return $this->redirect('admin.portals.providers.show', ['id' => $item->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.providers.error.not_found'));
        }

        $spec = $this->openApi->read($item->openapiUrl());
        $operationCount = 0;
        foreach ($spec['groups'] as $ops) {
            $operationCount += count($ops);
        }

        $apiSpec = $item->apiSpec();
        $codebookCount = 0;
        $methodCount = 0;
        if ($apiSpec !== []) {
            $books = $apiSpec['codebooks'] ?? [];
            $methods = $apiSpec['methods'] ?? [];
            $codebookCount = is_array($books) ? count($books) : 0;
            $methodCount = is_array($methods) ? count($methods) : 0;
            if ($operationCount === 0) {
                $operationCount = $methodCount;
            }
        }

        return $this->page('@portals/providers/show.html.twig', [
            'pageTitle' => $item->name(),
            'item' => $item,
            'spec' => $spec,
            'apiSpec' => $apiSpec,
            'operationCount' => $operationCount,
            'codebookCount' => $codebookCount,
            'methodCount' => $methodCount,
            'accountCount' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(a.id)')
                ->from(PortalAccount::class, 'a')
                ->where('a.providerId = :id')
                ->setParameter('id', $id)
                ->getQuery()
                ->getSingleScalarResult(),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.providers.error.not_found'));
        }

        return $this->page('@portals/providers/form.html.twig', [
            'pageTitle' => $this->translator->trans('portals.providers.edit', ['%name%' => $item->name()]),
            'item' => $item,
            'drivers' => PortalProvider::DRIVERS,
            'fieldTypes' => PortalProvider::FIELD_TYPES,
            'fieldRows' => $this->fieldRowsFrom($item),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.providers.error.not_found'));
        }
        $data = $this->validated($request, allowCodeChange: false);
        $this->apply($item, $data);
        $this->em->flush();
        $this->auditor->log('portals.provider_updated', 'portal_provider', $id);
        $this->forgetForm('portals.provider.save');
        $this->flash('success', $this->translator->trans('portals.providers.flash.updated'));

        return $this->redirect('admin.portals.providers.show', ['id' => $id]);
    }

    public function toggle(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.providers.error.not_found'));
        }
        $item->setActive(!$item->active());
        $this->em->flush();
        $this->flash('success', $this->translator->trans('portals.providers.flash.toggled'));

        return $this->redirect('admin.portals.providers.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('portals.providers.error.not_found'));
        }
        $used = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(PortalAccount::class, 'a')
            ->where('a.providerId = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
        if ($used > 0) {
            $this->flash('error', $this->translator->trans('portals.providers.error.in_use', ['%count%' => (string) $used]));

            return $this->redirect('admin.portals.providers.index');
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log('portals.provider_deleted', 'portal_provider', $id);
        $this->flash('success', $this->translator->trans('portals.providers.flash.deleted'));

        return $this->redirect('admin.portals.providers.index');
    }

    private function find(string $id): ?PortalProvider
    {
        $item = $this->em->find(PortalProvider::class, $id);

        return $item instanceof PortalProvider ? $item : null;
    }

    /**
     * @return array{
     *   code: string,
     *   name: string,
     *   driver: string,
     *   description: ?string,
     *   api_base_url: ?string,
     *   openapi_url: ?string,
     *   active: bool,
     *   sort_order: int,
     *   fields: list<array<string, mixed>>
     * }
     */
    private function validated(Request $request, bool $allowCodeChange = true): array
    {
        $data = $this->validate($request, 'portals.provider.save');
        $activeRaw = $data['active'] ?? null;
        $active = $activeRaw === true || $activeRaw === 1 || $activeRaw === '1' || $activeRaw === 'on';

        return [
            'code' => $allowCodeChange ? strtolower(trim((string) $data['code'])) : '',
            'name' => trim((string) $data['name']),
            'driver' => (string) $data['driver'],
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'api_base_url' => trim((string) ($data['api_base_url'] ?? '')) ?: null,
            'openapi_url' => trim((string) ($data['openapi_url'] ?? '')) ?: null,
            'active' => $active,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'fields' => $this->parseFields($request),
        ];
    }

    /**
     * @param array{
     *   code: string,
     *   name: string,
     *   driver: string,
     *   description: ?string,
     *   api_base_url: ?string,
     *   openapi_url: ?string,
     *   active: bool,
     *   sort_order: int,
     *   fields: list<array<string, mixed>>
     * } $data
     */
    private function apply(PortalProvider $item, array $data): void
    {
        $item->setName($data['name']);
        $item->setDriver($data['driver']);
        $item->setDescription($data['description']);
        $item->setApiBaseUrl($data['api_base_url']);
        $item->setOpenapiUrl($data['openapi_url']);
        $item->setActive($data['active']);
        $item->setSortOrder($data['sort_order']);
        $item->setFields($data['fields']);
    }

    /** @return list<array<string, mixed>> */
    private function parseFields(Request $request): array
    {
        $keys = $request->request->all('field_key');
        $labels = $request->request->all('field_label');
        $types = $request->request->all('field_type');
        $required = $request->request->all('field_required');
        $hints = $request->request->all('field_hint');
        $placeholders = $request->request->all('field_placeholder');
        if (!is_array($keys)) {
            return [];
        }

        $out = [];
        foreach ($keys as $i => $key) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($labels[$i] ?? ''),
                'type' => (string) ($types[$i] ?? 'text'),
                'required' => !empty($required[$i]),
                'hint' => (string) ($hints[$i] ?? ''),
                'placeholder' => (string) ($placeholders[$i] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, type: string, required: bool, hint: string, placeholder: string}> */
    private function emptyFieldRows(): array
    {
        $blank = ['key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'hint' => '', 'placeholder' => ''];

        return array_fill(0, 5, $blank);
    }

    /**
     * @return list<array{key: string, label: string, type: string, required: bool, hint: string, placeholder: string}>
     */
    private function fieldRowsFrom(PortalProvider $item): array
    {
        $rows = $item->fields();
        while (count($rows) < 5) {
            $rows[] = ['key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'hint' => '', 'placeholder' => ''];
        }

        return $rows;
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
