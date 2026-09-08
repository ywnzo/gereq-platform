<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\AuditLog;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AuditController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['createdAt', 'userName', 'action', 'resource', 'ip'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            perPage: 25,
            filterKeys: ['kind', 'resource', 'from', 'to'],
            maxPerPage: 100,
        );

        $qb = $this->em->getRepository(AuditLog::class)->createQueryBuilder('a');
        $list->applySearch($qb, 'a', ['action', 'userName', 'resource', 'resourceId', 'ip']);

        $kind = $list->filter('kind');
        $this->applyKindFilter($qb, $kind);

        $resource = $list->filter('resource');
        if ($resource !== '') {
            $qb->andWhere('a.resource = :resource')->setParameter('resource', $resource);
        }

        $from = $list->filter('from');
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $qb->andWhere('a.createdAt >= :from')->setParameter(
                'from',
                new \DateTimeImmutable($from . ' 00:00:00'),
            );
        }

        $to = $list->filter('to');
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $qb->andWhere('a.createdAt <= :to')->setParameter(
                'to',
                new \DateTimeImmutable($to . ' 23:59:59'),
            );
        }

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $logs = $qb
            ->orderBy('a.' . $list->sort, $list->direction())
            ->setFirstResult($list->offset())
            ->setMaxResults($list->perPage)
            ->getQuery()
            ->getResult();

        $resources = $this->em->createQueryBuilder()
            ->select('DISTINCT a.resource')
            ->from(AuditLog::class, 'a')
            ->where('a.resource IS NOT NULL')
            ->andWhere("a.resource <> ''")
            ->orderBy('a.resource', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $this->page('admin/audit/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('admin.audit.title'),
            'logs' => $logs,
            'kind' => $kind,
            'resource' => $resource,
            'from' => $from,
            'to' => $to,
            'resources' => $resources,
            'hasFilters' => $list->search !== '' || $kind !== '' || $resource !== '' || $from !== '' || $to !== '',
        ]));
    }

    private function applyKindFilter(\Doctrine\ORM\QueryBuilder $qb, string $kind): void
    {
        if ($kind === '') {
            return;
        }

        $patterns = match ($kind) {
            'created' => ['%.created', '%.enabled', '%.uploaded', '%.published'],
            'updated' => ['%.updated', '%.status', '%.synced', '%changed'],
            'deleted' => ['%.deleted', '%.revoked', '%.disabled'],
            'auth' => ['auth.%'],
            default => [],
        };

        if ($patterns === []) {
            return;
        }

        $parts = [];
        foreach ($patterns as $i => $pattern) {
            $param = 'kind' . $i;
            $parts[] = "a.action LIKE :" . $param;
            $qb->setParameter($param, $pattern);
        }
        $qb->andWhere('(' . implode(' OR ', $parts) . ')');
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
