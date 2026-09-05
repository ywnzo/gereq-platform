<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository as Config;
use Gereq\Event\EventBus;
use Gereq\Setting\Settings;
use Modules\Customers\Domain\Customer;
use Modules\Orders\Domain\Invoice;
use Modules\Orders\Domain\Order;
use Modules\Payments\Application\SpaydBuilder;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** Issue invoices from orders, render PDF/HTML, optionally e-mail the customer. */
final class InvoiceIssuingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumberSequencer $numbers,
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly EventBus $events,
        private readonly string $basePath,
    ) {
    }

    public function issueForOrder(Order $order, bool $sendEmail = true): Invoice
    {
        $existing = $this->em->getRepository(Invoice::class)->findOneBy(['orderId' => $order->id()]);
        if ($existing instanceof Invoice) {
            if ($existing->status() === 'draft') {
                $this->fillFromOrder($existing, $order);
                $existing->issue(null, (new \DateTimeImmutable())->modify('+14 days'));
                $this->em->flush();
            }
            $this->renderAndStore($existing, $order);
            if ($sendEmail) {
                $this->emailInvoice($existing, $order);
            }

            return $existing;
        }

        $invoice = new Invoice($order->id(), $this->numbers->nextInvoiceNumber(), $order->total());
        $this->fillFromOrder($invoice, $order);
        $invoice->issue(null, (new \DateTimeImmutable())->modify('+14 days'));
        $this->em->persist($invoice);
        $this->em->flush();

        $this->renderAndStore($invoice, $order);
        $this->events->emit('invoice.issued', ['type' => 'invoice', 'id' => $invoice->id()], [
            'number' => $invoice->number(),
            'order_id' => $order->id(),
            'total' => $invoice->total(),
        ]);

        if ($sendEmail) {
            $this->emailInvoice($invoice, $order);
        }

        return $invoice;
    }

    public function renderAndStore(Invoice $invoice, ?Order $order = null): string
    {
        $order ??= $this->em->find(Order::class, $invoice->orderId());
        $html = $this->renderHtml($invoice, $order instanceof Order ? $order : null);
        $dir = rtrim($this->basePath, '/') . '/storage/invoices';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('orders.error.invoice_storage');
        }

        $htmlPath = $dir . '/' . $invoice->number() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfPath = $dir . '/' . $invoice->number() . '.pdf';
        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            file_put_contents($pdfPath, $dompdf->output());

            return $pdfPath;
        }

        $lines = $this->pdfLines($invoice, $order instanceof Order ? $order : null);
        file_put_contents($pdfPath, SimpleInvoicePdf::render($lines, $invoice->number()));

        return $pdfPath;
    }

    /** @return list<string> */
    private function pdfLines(Invoice $invoice, ?Order $order): array
    {
        $seller = $invoice->seller();
        $buyer = $invoice->buyer();
        $lines = [
            'FAKTURA ' . $invoice->number(),
            'Stav: ' . $invoice->status(),
            'Datum: ' . ($invoice->issuedAt()?->format('d.m.Y') ?? date('d.m.Y')),
            'Splatnost: ' . ($invoice->dueAt()?->format('d.m.Y') ?? '—'),
            '',
            'Dodavatel: ' . (string) ($seller['name'] ?? ''),
            'ICO: ' . (string) ($seller['ico'] ?? '') . '  DIC: ' . (string) ($seller['dic'] ?? ''),
            (string) ($seller['address'] ?? ''),
            '',
            'Odberatel: ' . (string) ($buyer['name'] ?? ''),
            (string) ($buyer['address'] ?? ''),
            (string) ($buyer['email'] ?? ''),
            '',
        ];
        if ($order instanceof Order) {
            $lines[] = 'Objednavka: ' . $order->number();
            foreach ($this->em->getRepository(\Modules\Orders\Domain\OrderLine::class)->findBy(['orderId' => $order->id()]) as $line) {
                if ($line instanceof \Modules\Orders\Domain\OrderLine) {
                    $lines[] = sprintf('%s  %s x %s = %s', $line->title(), $line->qty(), $line->unitPrice(), $line->lineTotal());
                }
            }
            $lines[] = '';
        }
        $lines[] = 'CELKEM: ' . $invoice->total() . ' ' . $invoice->currency();
        $lines[] = 'VS: ' . (string) ($invoice->paymentReference() ?? $invoice->number());

        return $lines;
    }

    public function pathFor(Invoice $invoice): ?string
    {
        $dir = rtrim($this->basePath, '/') . '/storage/invoices';
        $pdf = $dir . '/' . $invoice->number() . '.pdf';
        if (is_file($pdf)) {
            return $pdf;
        }
        $html = $dir . '/' . $invoice->number() . '.html';

        return is_file($html) ? $html : null;
    }

    public function emailInvoice(Invoice $invoice, Order $order): void
    {
        $customer = $this->em->find(Customer::class, $order->customerId());
        $to = $customer instanceof Customer ? $customer->email() : null;
        if ($to === null || $to === '') {
            return;
        }

        $path = $this->pathFor($invoice) ?? $this->renderAndStore($invoice, $order);
        $appName = (string) $this->settings->get('app.name', $this->config->get('app.name', 'GEREQ'));
        $email = (new Email())
            ->to($to)
            ->subject(sprintf('%s — faktura %s', $appName, $invoice->number()))
            ->text(sprintf(
                "Dobrý den,\n\nv příloze naleznete fakturu %s k objednávce %s.\n\n%s\n",
                $invoice->number(),
                $order->number(),
                $appName,
            ));

        if (is_file($path)) {
            $email->attachFromPath($path, basename($path));
        }

        try {
            $this->mailer->send($email);
        } catch (\Throwable) {
            // Mail may be null transport in local — ignore
        }
    }

    private function fillFromOrder(Invoice $invoice, Order $order): void
    {
        $invoice->setCurrency($order->currency());
        $invoice->setTotal($order->total());
        $invoice->setPaymentReference($order->number());

        $seller = [
            'name' => (string) $this->settings->get('invoices.seller_name', $this->settings->get('app.name', 'GEREQ')),
            'ico' => (string) $this->settings->get('invoices.seller_ico', ''),
            'dic' => (string) $this->settings->get('invoices.seller_dic', ''),
            'address' => (string) $this->settings->get('invoices.seller_address', ''),
            'email' => (string) $this->settings->get('invoices.seller_email', ''),
            'iban' => (string) $this->settings->get('payments.bank_iban', ''),
            'account' => (string) $this->settings->get('payments.bank_account', ''),
            'bank_code' => (string) $this->settings->get('payments.bank_code', ''),
        ];
        $invoice->setSeller($seller);

        $customer = $this->em->find(Customer::class, $order->customerId());
        $billing = $order->billingAddress() ?? [];
        $buyer = [
            'name' => $billing['name'] ?? ($customer instanceof Customer ? $customer->name() : ''),
            'email' => $billing['email'] ?? ($customer instanceof Customer ? $customer->email() : ''),
            'ico' => $billing['ico'] ?? '',
            'dic' => $billing['dic'] ?? '',
            'address' => trim(implode(', ', array_filter([
                $billing['street'] ?? null,
                trim(($billing['zip'] ?? '') . ' ' . ($billing['city'] ?? '')),
                $billing['country'] ?? null,
            ]))),
        ];
        $invoice->setBuyer($buyer);

        $iban = (string) ($seller['iban'] ?? '');
        $account = (string) ($seller['account'] ?? '');
        $bankCode = (string) ($seller['bank_code'] ?? '');
        if ($iban !== '' || ($account !== '' && $bankCode !== '')) {
            $invoice->setSpaydPayload(SpaydBuilder::build([
                'iban' => $iban,
                'account' => $account,
                'bank_code' => $bankCode,
                'amount' => $invoice->total(),
                'currency' => $invoice->currency(),
                'vs' => preg_replace('/\D+/', '', $invoice->number()) ?: $invoice->number(),
                'message' => 'Invoice ' . $invoice->number(),
                'recipient' => (string) ($seller['name'] ?? ''),
            ]));
        }
    }

    private function renderHtml(Invoice $invoice, ?Order $order): string
    {
        $lines = [];
        if ($order instanceof Order) {
            $orderLines = $this->em->getRepository(\Modules\Orders\Domain\OrderLine::class)->findBy(['orderId' => $order->id()]);
            foreach ($orderLines as $line) {
                if (!$line instanceof \Modules\Orders\Domain\OrderLine) {
                    continue;
                }
                $lines[] = [
                    'title' => $line->title(),
                    'sku' => $line->sku(),
                    'qty' => $line->qty(),
                    'unit_price' => $line->unitPrice(),
                    'total' => $line->lineTotal(),
                ];
            }
        }

        try {
            return $this->twig->render('@orders/invoice_pdf.html.twig', [
                'invoice' => $invoice,
                'order' => $order,
                'lines' => $lines,
                'seller' => $invoice->seller(),
                'buyer' => $invoice->buyer(),
                'app_name' => (string) $this->config->get('app.name', 'GEREQ'),
            ]);
        } catch (\Throwable) {
            return $this->fallbackHtml($invoice, $lines);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function fallbackHtml(Invoice $invoice, array $lines): string
    {
        $seller = htmlspecialchars((string) ($invoice->seller()['name'] ?? ''), ENT_QUOTES);
        $buyer = htmlspecialchars((string) ($invoice->buyer()['name'] ?? ''), ENT_QUOTES);
        $number = htmlspecialchars($invoice->number(), ENT_QUOTES);
        $total = htmlspecialchars($invoice->total() . ' ' . $invoice->currency(), ENT_QUOTES);
        $rows = '';
        foreach ($lines as $line) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) ($line['title'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($line['qty'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($line['unit_price'] ?? ''), ENT_QUOTES),
                htmlspecialchars((string) ($line['total'] ?? ''), ENT_QUOTES),
            );
        }

        return <<<HTML
<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>Faktura {$number}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:6px;text-align:left}</style>
</head><body>
<h1>Faktura {$number}</h1>
<p><strong>Dodavatel:</strong> {$seller}<br><strong>Odběratel:</strong> {$buyer}</p>
<table><thead><tr><th>Položka</th><th>Množství</th><th>Cena</th><th>Celkem</th></tr></thead><tbody>{$rows}</tbody></table>
<p><strong>Celkem k úhradě:</strong> {$total}</p>
<p>VS: {$number}</p>
</body></html>
HTML;
    }
}
