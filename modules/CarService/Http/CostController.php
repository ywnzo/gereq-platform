<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CarService\Domain\ServiceCost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CostController extends Controller
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $month = trim((string) $request->query->get('month', date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $items = $this->em->getRepository(ServiceCost::class)->findBy(['yearMonth' => $month], ['createdAt' => 'DESC']);
        $total = '0.00';
        foreach ($items as $item) {
            if ($item instanceof ServiceCost) {
                $total = bcadd($total, $item->amount(), 2);
            }
        }

        return $this->page('@carservice/costs/index.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.costs.title'),
            'items' => $items,
            'month' => $month,
            'total' => $total,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'carservice.cost');
        $month = trim((string) $data['year_month']);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_month'));

            return $this->redirect('admin.carservice.costs');
        }
        $amount = number_format((float) str_replace(',', '.', (string) $data['amount']), 2, '.', '');
        $cost = new ServiceCost($month, $amount, trim((string) ($data['category'] ?? 'general')) ?: 'general');
        $cost->setNote(trim((string) ($data['note'] ?? '')) ?: null);
        $this->em->persist($cost);
        $this->em->flush();
        $this->forgetForm('carservice.cost');
        $this->flash('success', $this->translator->trans('carservice.flash.cost_saved'));

        return $this->redirectTo($this->router->url('admin.carservice.costs') . '?month=' . rawurlencode($month));
    }

    public function destroy(string $id): Response
    {
        $cost = $this->em->find(ServiceCost::class, $id);
        if (!$cost instanceof ServiceCost) {
            $this->abort(404, $this->translator->trans('carservice.error.cost_not_found'));
        }
        $month = $cost->yearMonth();
        $this->em->remove($cost);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('carservice.flash.cost_deleted'));

        return $this->redirectTo($this->router->url('admin.carservice.costs') . '?month=' . rawurlencode($month));
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
