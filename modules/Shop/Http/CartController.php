<?php

declare(strict_types=1);

namespace Modules\Shop\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Shop\Application\CartService;
use Modules\Shop\Domain\Cart;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CartController extends Controller
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
        private readonly CartService $carts,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $qb = $this->em->getRepository(Cart::class)->createQueryBuilder('c')->orderBy('c.updatedAt', 'DESC');
        self_repair_search($qb, 'c', ['id', 'customerId', 'sessionKey'], $search);
        if (in_array($status, Cart::STATUSES, true)) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
        $items = $qb->getQuery()->getResult();

        return $this->page('@shop/carts/index.html.twig', [
            'pageTitle' => $this->translator->trans('shop.carts.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
            'totalsMap' => $this->totalsMap($items),
            'search' => $search,
            'status' => $status,
            'statuses' => Cart::STATUSES,
        ]);
    }

    public function show(string $id): Response
    {
        $cart = $this->carts->find($id);
        if ($cart === null) {
            $this->abort(404, $this->translator->trans('shop.error.cart_not_found'));
        }

        $customer = null;
        if ($cart->customerId() !== null && class_exists(Customer::class)) {
            $found = $this->em->find(Customer::class, $cart->customerId());
            $customer = $found instanceof Customer ? $found : null;
        }

        $items = $this->carts->items($cart->id());
        $shippingPrice = '0';
        $meta = $cart->meta() ?? [];
        if (isset($meta['shipping']['price'])) {
            $shippingPrice = (string) $meta['shipping']['price'];
        }

        return $this->page('@shop/carts/show.html.twig', [
            'pageTitle' => $this->translator->trans('shop.carts.detail'),
            'item' => $cart,
            'lines' => $items,
            'customer' => $customer,
            'totals' => $this->carts->totals($cart, $shippingPrice),
        ]);
    }

    public function abandon(string $id): Response
    {
        $cart = $this->carts->find($id);
        if ($cart === null) {
            $this->abort(404, $this->translator->trans('shop.error.cart_not_found'));
        }
        if (!$cart->isOpen()) {
            $this->flash('error', $this->translator->trans('shop.error.cart_not_open'));

            return $this->redirect('admin.shop.carts.show', ['id' => $id]);
        }

        $cart->setStatus('abandoned');
        $this->em->flush();
        $this->auditor->log('shop.cart.abandoned', 'cart', $id);
        $this->flash('success', $this->translator->trans('shop.flash.cart_abandoned'));

        return $this->redirect('admin.shop.carts.show', ['id' => $id]);
    }

    /** @param list<Cart> $carts @return array<string, Customer> */
    private function customerMap(array $carts): array
    {
        if (!class_exists(Customer::class)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (Cart $c): ?string => $c->customerId(),
            $carts,
        ))));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')
            ->where('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $map[$c->id()] = $c;
            }
        }

        return $map;
    }

    /**
     * @param list<Cart> $carts
     * @return array<string, array{subtotal: string, shipping: string, total: string, currency: string, item_count: int}>
     */
    private function totalsMap(array $carts): array
    {
        $map = [];
        foreach ($carts as $cart) {
            $map[$cart->id()] = $this->carts->totals($cart);
        }

        return $map;
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
