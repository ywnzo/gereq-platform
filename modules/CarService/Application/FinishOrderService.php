<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository as Config;
use Gereq\File\FileManager;
use Gereq\Module\CapabilityRegistry;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;
use Modules\Fleet\Domain\Vehicle;
use Twig\Environment;

final class FinishOrderService
{
    public const DOCUMENT_ENTITY = 'carservice_order';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CarServiceProvider $carservice,
        private readonly CapabilityRegistry $capabilities,
        private readonly Environment $twig,
        private readonly Config $config,
        private readonly Authenticator $auth,
        private readonly FileManager $files,
    ) {
    }

    /**
     * @return array{order: ServiceOrder, document: ?Document}
     */
    public function finish(string $orderId): array
    {
        $order = $this->carservice->finishOrder($orderId);
        $document = null;
        if ($this->capabilities->has('documents') && class_exists(Document::class)) {
            $document = $this->generateWorksheet($order);
        }

        return ['order' => $order, 'document' => $document];
    }

    public function generateWorksheet(ServiceOrder $order): Document
    {
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $customer = $order->customerId() !== null ? $this->em->find(Customer::class, $order->customerId()) : null;
        $jobs = $this->carservice->jobsForOrder($order->id());
        $html = $this->twig->render('@carservice/pdf/worksheet.html.twig', [
            'order' => $order,
            'vehicle' => $vehicle instanceof Vehicle ? $vehicle : null,
            'customer' => $customer instanceof Customer ? $customer : null,
            'jobs' => $jobs,
            'jobSeconds' => $this->carservice->totalJobSeconds($order->id()),
            'appName' => (string) $this->config->get('app.name', 'EShop'),
        ]);

        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $binary = $dompdf->output();
            $mime = 'application/pdf';
            $ext = 'pdf';
        } else {
            $binary = $html;
            $mime = 'text/html';
            $ext = 'html';
        }

        $path = 'carservice/' . date('Y/m') . '/zakazka-' . substr($order->id(), 0, 8) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $this->files->filesystem()->write($path, $binary);

        $document = new Document(
            'Zakázkový list ' . substr($order->id(), 0, 8),
            $path,
            'zakazkovy-list-' . substr($order->id(), 0, 8) . '.' . $ext,
            $mime,
            strlen($binary),
            $this->auth->user()?->id(),
        );
        $this->em->persist($document);
        $this->em->persist(new DocumentLink($document->id(), self::DOCUMENT_ENTITY, $order->id()));
        $this->em->flush();

        return $document;
    }
}
