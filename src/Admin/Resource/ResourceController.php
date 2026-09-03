<?php

declare(strict_types=1);

namespace Gereq\Admin\Resource;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ResourceController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly ResourceRegistrar $resources,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $definition = $this->current($request);
        $search = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', '');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $alias = 'e';
        $qb = $this->em->createQueryBuilder()->select($alias)->from($definition->entityClass, $alias);

        if ($search !== '') {
            $parts = [];
            foreach ($definition->fields as $i => $field) {
                if ($field->searchable) {
                    $parts[] = $alias . '.' . $field->name . ' LIKE :q' . $i;
                    $qb->setParameter('q' . $i, '%' . $search . '%');
                }
            }
            if ($parts !== []) {
                $qb->andWhere(implode(' OR ', $parts));
            }
        }

        $sortable = array_map(static fn ($f) => $f->name, array_filter($definition->fields, static fn ($f) => $f->sortable));
        if ($sort !== '' && in_array($sort, $sortable, true)) {
            $qb->orderBy($alias . '.' . $sort, $dir);
        } else {
            $qb->orderBy($alias . '.createdAt', 'DESC');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(' . $alias . '.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return $this->page('admin/resource/index.html.twig', [
            'pageTitle' => $definition->label,
            'definition' => $definition,
            'items' => $items,
            'search' => $search,
            'sort' => $sort,
            'dir' => strtolower($dir),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ]);
    }

    public function create(Request $request): Response
    {
        $definition = $this->current($request);

        return $this->page('admin/resource/form.html.twig', [
            'pageTitle' => 'Nový záznam · ' . $definition->label,
            'definition' => $definition,
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $definition = $this->current($request);
        $entity = new $definition->entityClass();
        $this->fill($entity, $definition, $request);
        $this->em->persist($entity);
        $this->em->flush();
        $this->auditor->log($definition->name . '.created', $definition->name, method_exists($entity, 'id') ? $entity->id() : null);
        $this->flash('success', 'Záznam byl vytvořen.');

        return $this->redirect('admin.' . $definition->name . '.index');
    }

    public function show(Request $request, string $id): Response
    {
        $definition = $this->current($request);
        $item = $this->em->find($definition->entityClass, $id);
        if ($item === null) {
            $this->abort(404, 'Záznam neexistuje.');
        }

        return $this->page('admin/resource/show.html.twig', [
            'pageTitle' => $definition->singular,
            'definition' => $definition,
            'item' => $item,
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $definition = $this->current($request);
        $item = $this->em->find($definition->entityClass, $id);
        if ($item === null) {
            $this->abort(404, 'Záznam neexistuje.');
        }

        return $this->page('admin/resource/form.html.twig', [
            'pageTitle' => 'Upravit · ' . $definition->singular,
            'definition' => $definition,
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $definition = $this->current($request);
        $item = $this->em->find($definition->entityClass, $id);
        if ($item === null) {
            $this->abort(404, 'Záznam neexistuje.');
        }

        $this->fill($item, $definition, $request);
        $this->em->flush();
        $this->auditor->log($definition->name . '.updated', $definition->name, $id);
        $this->flash('success', 'Záznam byl uložen.');

        return $this->redirect('admin.' . $definition->name . '.show', ['id' => $id]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $definition = $this->current($request);
        $item = $this->em->find($definition->entityClass, $id);
        if ($item === null) {
            $this->abort(404, 'Záznam neexistuje.');
        }

        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log($definition->name . '.deleted', $definition->name, $id);
        $this->flash('success', 'Záznam byl smazán.');

        return $this->redirect('admin.' . $definition->name . '.index');
    }

    private function current(Request $request): ResourceDefinition
    {
        $route = (string) $request->attributes->get('_route');
        $parts = explode('.', $route);

        return $this->resources->get($parts[1] ?? '');
    }

    private function fill(object $entity, ResourceDefinition $definition, Request $request): void
    {
        $allowed = [];
        foreach ($definition->formFields() as $field) {
            $allowed[$field->name] = $field;
        }

        foreach ($allowed as $name => $field) {
            $value = $request->request->get($name);
            $setter = 'set' . ucfirst($name);
            if (!method_exists($entity, $setter)) {
                continue;
            }

            if ($field->type === 'checkbox') {
                $entity->{$setter}($request->request->getBoolean($name));
                continue;
            }

            $entity->{$setter}(is_string($value) ? trim($value) : $value);
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
