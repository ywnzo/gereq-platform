<?php

declare(strict_types=1);

namespace Modules\Orders\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Orders\Domain\Order;
use Modules\Shop\Application\ShippingCarrierRegistry;
use Modules\Shop\Domain\Shipment;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Admin actions: create/sync Packeta (or other carrier) shipment for an order. */
final class OrderShipmentController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ShippingCarrierRegistry $carriers,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function create(string $id): Response
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }

        $existing = $this->em->getRepository(Shipment::class)->findOneBy(['orderId' => $id], ['createdAt' => 'DESC']);
        $carrierKey = $existing instanceof Shipment
            ? $existing->carrier()
            : $this->guessCarrier($order);

        $shipment = $existing instanceof Shipment && $existing->status() === 'draft'
            ? $existing
            : new Shipment($id, $carrierKey);

        if (!$existing instanceof Shipment || $shipment !== $existing) {
            $shipment->setParcelPointId($order->parcelPointId());
            $shipment->setShippingMethodCode($order->shippingMethodCode());
            $this->em->persist($shipment);
        }

        $driver = $this->carriers->getOrManual($carrierKey);
        $result = $driver->createShipment($shipment, [
            'order_number' => $order->number(),
            'customer_name' => $order->shippingAddress()['name'] ?? null,
            'email' => $order->billingAddress()['email'] ?? null,
            'phone' => $order->shippingAddress()['phone'] ?? null,
            'parcel_point_id' => $order->parcelPointId(),
            'shipping_address' => $order->shippingAddress(),
        ]);

        $this->applyResult($shipment, $result);
        if (!empty($result['tracking_number'])) {
            $order->setTrackingNumber((string) $result['tracking_number']);
        }
        $this->em->flush();
        $this->flash('success', isset($result['meta']['error'])
            ? ('Zásilka: ' . (string) $result['meta']['error'])
            : 'Zásilka u dopravce vytvořena.');

        return $this->redirect('admin.orders.show', ['id' => $id]);
    }

    public function sync(string $id): Response
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            $this->abort(404, $this->translator->trans('orders.error.not_found'));
        }
        $shipment = $this->em->getRepository(Shipment::class)->findOneBy(['orderId' => $id], ['createdAt' => 'DESC']);
        if (!$shipment instanceof Shipment) {
            $this->flash('error', 'Žádná zásilka k synchronizaci.');

            return $this->redirect('admin.orders.show', ['id' => $id]);
        }

        $result = $this->carriers->getOrManual($shipment->carrier())->syncStatus($shipment);
        $this->applyResult($shipment, $result);
        if (!empty($result['tracking_number'])) {
            $order->setTrackingNumber((string) $result['tracking_number']);
        }
        $this->em->flush();
        $this->flash('success', 'Stav zásilky synchronizován.');

        return $this->redirect('admin.orders.show', ['id' => $id]);
    }

    /** @param array<string, mixed> $result */
    private function applyResult(Shipment $shipment, array $result): void
    {
        if (isset($result['status']) && is_string($result['status']) && in_array($result['status'], Shipment::STATUSES, true)) {
            $shipment->setStatus($result['status']);
        }
        if (array_key_exists('tracking_number', $result)) {
            $shipment->setTrackingNumber(isset($result['tracking_number']) ? (string) $result['tracking_number'] : null);
        }
        if (array_key_exists('external_id', $result)) {
            $shipment->setExternalId(isset($result['external_id']) ? (string) $result['external_id'] : null);
        }
        if (array_key_exists('label_url', $result)) {
            $shipment->setLabelUrl(isset($result['label_url']) ? (string) $result['label_url'] : null);
        }
        if (isset($result['meta']) && is_array($result['meta'])) {
            $shipment->setMeta(array_merge($shipment->meta(), $result['meta']));
        }
    }

    private function guessCarrier(Order $order): string
    {
        $code = strtolower((string) ($order->shippingMethodCode() ?? ''));
        if (str_contains($code, 'packeta') || str_contains($code, 'zasilkovna')) {
            return 'packeta';
        }
        if (str_contains($code, 'ppl')) {
            return 'ppl';
        }

        return 'manual';
    }
}
