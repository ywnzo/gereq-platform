<?php

declare(strict_types=1);

namespace Modules\Orders\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Orders\Application\InvoiceIssuingService;
use Modules\Orders\Application\NumberSequencer;
use Modules\Orders\Domain\Invoice;
use Modules\Orders\Domain\InvoicePayment;
use Modules\Orders\Domain\Order;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class InvoiceController extends Controller
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
        private readonly NumberSequencer $numbers,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceIssuingService $issuer,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['number', 'status', 'total', 'createdAt', 'issuedAt', 'dueAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(Invoice::class)->createQueryBuilder('i');
        if ($list->search !== '') {
            $qb->andWhere('i.number LIKE :q')->setParameter('q', '%' . $list->search . '%');
        }
        if (in_array($status, Invoice::STATUSES, true)) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        $qb->orderBy('i.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(i.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@orders/invoices/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('orders.invoices.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => Invoice::STATUSES,
        ]));
    }

    public function show(string $id): Response
    {
        $invoice = $this->find($id);
        if ($invoice === null) {
            $this->abort(404, $this->translator->trans('orders.error.invoice_not_found'));
        }
        $order = $this->em->find(Order::class, $invoice->orderId());
        $payments = $this->em->getRepository(InvoicePayment::class)->findBy(['invoiceId' => $id], ['paidAt' => 'DESC']);

        return $this->page('@orders/invoices/show.html.twig', [
            'pageTitle' => $invoice->number(),
            'item' => $invoice,
            'order' => $order instanceof Order ? $order : null,
            'payments' => $payments,
        ]);
    }

    public function pdf(string $id): Response
    {
        $invoice = $this->find($id);
        if ($invoice === null) {
            $this->abort(404, $this->translator->trans('orders.error.invoice_not_found'));
        }
        $order = $this->em->find(Order::class, $invoice->orderId());
        $path = $this->issuer->pathFor($invoice)
            ?? $this->issuer->renderAndStore($invoice, $order instanceof Order ? $order : null);
        $mime = str_ends_with($path, '.pdf') ? 'application/pdf' : 'text/html; charset=UTF-8';

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $invoice->number() . (str_ends_with($path, '.pdf') ? '.pdf' : '.html') . '"',
        ]);
    }

    public function createFromOrder(string $orderId): Response
    {
        $order = $this->em->find(Order::class, $orderId);
        if (!$order instanceof Order) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        if (!in_array($order->status(), ['confirmed', 'fulfilled'], true)) {
            $this->flash('error', $this->translator->trans('orders.error.invoice_order_status'));

            return $this->redirect('admin.orders.show', ['id' => $orderId]);
        }
        $invoice = new Invoice($orderId, $this->numbers->nextInvoiceNumber(), $order->total());
        $this->em->persist($invoice);
        $this->em->flush();
        $this->auditor->log('invoices.created', 'invoice', $invoice->id(), null, [
            'number' => $invoice->number(),
            'order_id' => $orderId,
        ]);
        $this->flash('success', $this->translator->trans('orders.flash.invoice_created', ['%number%' => $invoice->number()]));

        return $this->redirect('admin.invoices.show', ['id' => $invoice->id()]);
    }

    public function issue(Request $request, string $id): Response
    {
        $invoice = $this->find($id);
        if ($invoice === null) {
            $this->abort(404, $this->translator->trans('orders.error.invoice_not_found'));
        }
        if ($invoice->status() !== 'draft') {
            $this->flash('error', $this->translator->trans('orders.error.invoice_not_draft'));

            return $this->redirect('admin.invoices.show', ['id' => $id]);
        }
        $dueRaw = trim((string) $request->request->get('due_at', ''));
        $dueAt = $dueRaw !== '' ? new \DateTimeImmutable($dueRaw) : (new \DateTimeImmutable())->modify('+14 days');
        $invoice->issue(null, $dueAt);
        $this->em->flush();
        $order = $this->em->find(Order::class, $invoice->orderId());
        $this->issuer->renderAndStore($invoice, $order instanceof Order ? $order : null);
        $payload = $this->publicInvoice($invoice);
        $this->events->emit('invoice.issued', ['type' => 'invoice', 'id' => $invoice->id()], $payload);
        $this->auditor->log('invoices.issued', 'invoice', $id);
        $this->flash('success', $this->translator->trans('orders.flash.invoice_issued', ['%number%' => $invoice->number()]));

        return $this->redirect('admin.invoices.show', ['id' => $id]);
    }

    public function recordPayment(Request $request, string $id): Response
    {
        $invoice = $this->find($id);
        if ($invoice === null) {
            $this->abort(404, $this->translator->trans('orders.error.invoice_not_found'));
        }
        if (!in_array($invoice->status(), ['issued', 'paid'], true)) {
            $this->flash('error', $this->translator->trans('orders.error.invoice_not_payable'));

            return $this->redirect('admin.invoices.show', ['id' => $id]);
        }
        $data = $this->validate($request, 'orders.payment.save');
        $payment = new InvoicePayment(
            $id,
            (string) $data['amount'],
            trim((string) $data['method']),
            isset($data['paid_at']) && trim((string) $data['paid_at']) !== ''
                ? new \DateTimeImmutable((string) $data['paid_at'])
                : null,
        );
        $this->em->persist($payment);

        $paid = (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0)')
            ->from(InvoicePayment::class, 'p')
            ->where('p.invoiceId = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
        $paid += (float) $payment->amount();

        if ($paid + 0.001 >= (float) $invoice->total()) {
            $invoice->markPaid();
            $this->em->flush();
            $this->events->emit('invoice.paid', ['type' => 'invoice', 'id' => $invoice->id()], $this->publicInvoice($invoice));
        } else {
            $this->em->flush();
        }

        $this->auditor->log('invoices.payment', 'invoice', $id, null, ['amount' => $payment->amount()]);
        $this->forgetForm('orders.payment.save');
        $this->flash('success', $this->translator->trans('orders.flash.payment_recorded', ['%amount%' => $payment->amount()]));

        return $this->redirect('admin.invoices.show', ['id' => $id]);
    }

    private function find(string $id): ?Invoice
    {
        $i = $this->em->find(Invoice::class, $id);

        return $i instanceof Invoice ? $i : null;
    }

    /** @return array<string, mixed> */
    private function publicInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id(),
            'order_id' => $invoice->orderId(),
            'number' => $invoice->number(),
            'status' => $invoice->status(),
            'issued_at' => $invoice->issuedAt()?->format(\DateTimeInterface::ATOM),
            'due_at' => $invoice->dueAt()?->format('Y-m-d'),
            'total' => $invoice->total(),
            'created_at' => $invoice->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $invoice->updatedAt()->format(\DateTimeInterface::ATOM),
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
