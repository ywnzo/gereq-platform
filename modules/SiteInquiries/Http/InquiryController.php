<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\SiteInquiries\Application\InquiryIntake;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class InquiryController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EntityManagerInterface $em,
        private readonly InquiryIntake $intake,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly \Gereq\Cms\Cms $cms,
        private readonly \Modules\SiteInquiries\Application\InquiryNotificationOutbox $notifications,
        private readonly \Gereq\Audit\Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['createdAt', 'name', 'email', 'status'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            perPage: 25,
            filterKeys: ['status', 'from', 'to', 'view'],
            maxPerPage: 100,
        );

        $status = $list->filter('status');
        $from = $list->filter('from');
        $to = $list->filter('to');
        $view = $list->filter('view') === 'day' ? 'day' : 'list';

        $qb = $this->em->getRepository(Inquiry::class)->createQueryBuilder('i');
        $list->applySearch($qb, 'i', ['name', 'email', 'phone', 'details', 'routeFrom', 'routeTo']);

        if (in_array($status, [Inquiry::STATUS_OPEN, Inquiry::STATUS_RESOLVED], true)) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $qb->andWhere('i.createdAt >= :from')->setParameter(
                'from',
                new \DateTimeImmutable($from . ' 00:00:00'),
            );
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $qb->andWhere('i.createdAt <= :to')->setParameter(
                'to',
                new \DateTimeImmutable($to . ' 23:59:59'),
            );
        }

        $total = (int) (clone $qb)
            ->select('COUNT(i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Inquiry> $entities */
        $entities = $qb
            ->orderBy('i.' . $list->sort, $list->direction())
            ->addOrderBy('i.createdAt', 'DESC')
            ->setFirstResult($list->offset())
            ->setMaxResults($list->perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(fn (Inquiry $item): array => $this->intake->serialize($item), $entities);
        $groups = $view === 'day' ? $this->groupByDay($items) : [];

        return $this->page('@siteinquiries/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('site_inquiries.index.title'),
            'items' => $items,
            'groups' => $groups,
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'view' => $view,
            'hasFilters' => $list->search !== '' || $status !== '' || $from !== '' || $to !== '',
            'contact' => [
                'phone' => (string) $this->settings->get('site_inquiries.contact_phone', ''),
                'email' => (string) $this->settings->get('site_inquiries.contact_email', ''),
                'person' => (string) $this->settings->get('site_inquiries.contact_person', ''),
                'address' => (string) $this->settings->get('site_inquiries.contact_address', ''),
                'notify_email' => (string) $this->settings->get('site_inquiries.notify_email', ''),
            ],
        ]));
    }

    public function show(Request $request, string $id): Response
    {
        $inquiry = $this->find($id);
        if ($inquiry === null) {
            $this->abort(404);
        }
        $response = $this->page($request->isXmlHttpRequest() ? '@siteinquiries/_detail.html.twig' : '@siteinquiries/show.html.twig', [
            'pageTitle' => $this->translator->trans('site_inquiries.index.title'),
            'item' => $this->intake->serialize($inquiry),
            'notification' => $this->notifications->status($id),
            'attachments' => $this->cms->recordAttachments('site_inquiries.create', $id),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    public function retryNotification(Request $request, string $id): Response
    {
        if (!$this->auth->user()?->can('site_inquiries.view')) {
            $this->abort(403);
        }
        if ($this->find($id) === null) {
            $this->abort(404);
        }
        $status = $this->em->getConnection()->transactional(function () use ($id): string {
            $status = $this->notifications->retry($id);
            $this->auditor->log('site_inquiry.notification_retry_requested', 'site_inquiry', $id, null, ['status' => $status]);
            return $status;
        });
        $message = $this->translator->trans('site_inquiries.notification.' . $status);
        if ($this->wantsJson($request)) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['message' => $message, 'status' => $status], 200, ['Cache-Control' => 'private, no-store']);
        }
        $this->flash('success', $message);
        return $this->redirect('admin.site_inquiries.show', ['id' => $id]);
    }

    public function resolve(Request $request, string $id): Response
    {
        $inquiry = $this->find($id);
        if ($inquiry === null) {
            $this->abort(404);
        }

        if ($inquiry->isResolved()) {
            $inquiry->markOpen();
            $message = $this->translator->trans('site_inquiries.flash.reopened');
            $on = false;
        } else {
            $inquiry->markResolved();
            $message = $this->translator->trans('site_inquiries.flash.resolved');
            $on = true;
        }

        $this->em->flush();
        if (!$this->wantsJson($request)) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse($on, 'admin.site_inquiries.index', [], $message);
    }

    public function destroy(string $id): Response
    {
        $inquiry = $this->find($id);
        if ($inquiry === null) {
            $this->abort(404);
        }

        $this->em->remove($inquiry);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('site_inquiries.flash.deleted'));

        return $this->redirect('admin.site_inquiries.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'site_inquiries.bulk');
        $action = (string) $data['action'];

        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('site_inquiries.bulk.error.empty'));

            return $this->redirect('admin.site_inquiries.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('site_inquiries.bulk.error.confirm'));

            return $this->redirect('admin.site_inquiries.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $inquiry = $this->find($id);
            if ($inquiry === null) {
                continue;
            }

            if ($action === 'delete') {
                $this->em->remove($inquiry);
                $affected++;
                continue;
            }

            if ($action === 'resolve') {
                if (!$inquiry->isResolved()) {
                    $inquiry->markResolved();
                    $affected++;
                }
                continue;
            }

            if ($action === 'reopen') {
                if ($inquiry->isResolved()) {
                    $inquiry->markOpen();
                    $affected++;
                }
            }
        }

        $this->em->flush();
        $this->flash(
            'success',
            $this->translator->trans('site_inquiries.bulk.flash.' . $action, [
                '%count%' => (string) $affected,
            ]),
        );

        return $this->redirect('admin.site_inquiries.index');
    }

    public function settings(): Response
    {
        return $this->page('@siteinquiries/settings.html.twig', [
            'values' => [
                'contact_phone' => (string) $this->settings->get('site_inquiries.contact_phone', '+420 704 797 610'),
                'contact_phone_tel' => (string) $this->settings->get('site_inquiries.contact_phone_tel', '+420704797610'),
                'contact_email' => (string) $this->settings->get('site_inquiries.contact_email', 'info@doprovodyvohralik.cz'),
                'contact_person' => (string) $this->settings->get('site_inquiries.contact_person', 'Adam Vohralík'),
                'contact_ico' => (string) $this->settings->get('site_inquiries.contact_ico', '22174915'),
                'contact_dic' => (string) $this->settings->get('site_inquiries.contact_dic', ''),
                'contact_address' => (string) $this->settings->get(
                    'site_inquiries.contact_address',
                    'Topolová 963, Mladá, 289 24 Milovice',
                ),
                'tagline' => (string) $this->settings->get(
                    'site_inquiries.tagline',
                    'Bezpečný partner pro nadrozměrné přepravy.',
                ),
                'notify_email' => (string) $this->settings->get(
                    'site_inquiries.notify_email',
                    'info@doprovodyvohralik.cz',
                ),
            ],
        ]);
    }

    public function updateSettings(Request $request): Response
    {
        $keys = [
            'contact_phone',
            'contact_phone_tel',
            'contact_email',
            'contact_person',
            'contact_ico',
            'contact_dic',
            'contact_address',
            'tagline',
            'notify_email',
        ];
        foreach ($keys as $key) {
            $value = trim((string) $request->request->get($key, ''));
            $this->settings->set('site_inquiries.' . $key, $value);
        }

        $this->flash('success', $this->translator->trans('site_inquiries.settings.flash.saved'));

        return $this->redirect('admin.site_inquiries.settings');
    }

    private function find(string $id): ?Inquiry
    {
        $inquiry = $this->em->find(Inquiry::class, $id);

        return $inquiry instanceof Inquiry ? $inquiry : null;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{key: string, label: string, count: int, items: list<array<string, mixed>>}>
     */
    private function groupByDay(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = (string) ($item['created_day'] ?? '');
            if ($key === '') {
                $key = 'unknown';
            }
            if (!isset($groups[$key])) {
                $label = $key;
                try {
                    $label = (new \DateTimeImmutable($key))->format('j. n. Y');
                } catch (\Throwable) {
                    $label = $key;
                }
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => 0,
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $item;
            $groups[$key]['count']++;
        }

        return array_values($groups);
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
