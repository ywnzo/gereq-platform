<?php

declare(strict_types=1);

namespace Integrations\Packeta\Application;

use Gereq\Setting\Settings;
use Modules\Shop\Application\ShippingCarrierInterface;
use Modules\Shop\Domain\Shipment;

/**
 * Packeta / Zásilkovna REST XML carrier.
 * Settings: shop.packeta_api_password, shop.packeta_api_key, shop.packeta_eshop, shop.packeta_sender_label.
 */
final class PacketaCarrier implements ShippingCarrierInterface
{
    private const API = 'https://www.zasilkovna.cz/api/rest';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return 'packeta';
    }

    public function createShipment(Shipment $shipment, array $context = []): array
    {
        $password = trim((string) $this->settings->get('shop.packeta_api_password', ''));
        if ($password === '') {
            return [
                'status' => 'draft',
                'tracking_number' => null,
                'external_id' => null,
                'label_url' => null,
                'meta' => ['error' => 'Packeta API password not configured', 'provider' => 'packeta'],
            ];
        }

        $attrs = $this->buildPacketAttributes($shipment, $context);
        $xml = $this->call('createPacket', [
            'apiPassword' => $password,
            'packetAttributes' => $attrs,
        ]);

        $fault = $this->xpathText($xml, '//fault');
        if ($fault !== null) {
            return [
                'status' => 'draft',
                'tracking_number' => null,
                'external_id' => null,
                'label_url' => null,
                'meta' => [
                    'provider' => 'packeta',
                    'error' => $fault,
                    'stringId' => $this->xpathText($xml, '//stringId'),
                ],
            ];
        }

        $packetId = $this->xpathText($xml, '//result/id') ?? $this->xpathText($xml, '//id');
        $barcode = $this->xpathText($xml, '//result/barcode') ?? $this->xpathText($xml, '//barcode');

        $labelUrl = null;
        if ($packetId !== null && $packetId !== '') {
            $labelUrl = $this->fetchLabelDataUrl($password, $packetId);
        }

        return [
            'status' => $labelUrl ? 'label_ready' : 'created',
            'tracking_number' => $barcode,
            'external_id' => $packetId,
            'label_url' => $labelUrl,
            'meta' => [
                'provider' => 'packeta',
                'tracking_url' => $barcode
                    ? 'https://www.zasilkovna.cz/cs/sledovani-zasilky?number=' . rawurlencode($barcode)
                    : null,
                'packet' => [
                    'id' => $packetId,
                    'barcode' => $barcode,
                ],
            ],
        ];
    }

    public function syncStatus(Shipment $shipment): array
    {
        $password = trim((string) $this->settings->get('shop.packeta_api_password', ''));
        $packetId = $shipment->externalId();
        if ($password === '' || $packetId === null || $packetId === '') {
            return [
                'status' => $shipment->status(),
                'tracking_number' => $shipment->trackingNumber(),
                'meta' => $shipment->meta(),
            ];
        }

        $xml = $this->call('packetTracking', [
            'apiPassword' => $password,
            'packetId' => $packetId,
        ]);

        $events = [];
        foreach ($xml->xpath('//result/record') ?: [] as $record) {
            $events[] = [
                'date_time' => (string) ($record->dateTime ?? ''),
                'status_code' => (string) ($record->statusCode ?? ''),
                'code_text' => (string) ($record->codeText ?? ''),
                'status_text' => (string) ($record->statusText ?? ''),
            ];
        }

        $last = $events[0] ?? null;
        $mapped = $shipment->status();
        if (is_array($last)) {
            $code = (int) ($last['status_code'] ?? 0);
            $mapped = match (true) {
                $code >= 7 => 'delivered',
                $code >= 4 => 'in_transit',
                $code >= 1 => 'created',
                default => $mapped,
            };
        }

        return [
            'status' => $mapped,
            'tracking_number' => $shipment->trackingNumber(),
            'meta' => array_merge($shipment->meta(), ['tracking_events' => $events]),
        ];
    }

    /**
     * Public widget config for storefront Packeta.Widget.pick().
     *
     * @return array{api_key: string, country: string, language: string, vendors: list<string>}
     */
    public function widgetConfig(string $country = 'cz'): array
    {
        return [
            'api_key' => (string) $this->settings->get('shop.packeta_api_key', ''),
            'country' => strtolower($country),
            'language' => 'cs',
            'vendors' => ['zpoint', 'zbox'],
            'widget_url' => 'https://widget.packeta.com/v6/www/js/library.js',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private function buildPacketAttributes(Shipment $shipment, array $context): array
    {
        $name = trim((string) ($context['name'] ?? $context['customer_name'] ?? 'Zákazník'));
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        $first = $parts[0] ?? 'Zákazník';
        $last = $parts[1] ?? $first;

        $attrs = [
            'number' => (string) ($context['order_number'] ?? $shipment->orderId()),
            'name' => $first,
            'surname' => $last,
            'email' => (string) ($context['email'] ?? $context['customer_email'] ?? ''),
            'phone' => (string) ($context['phone'] ?? ''),
            'value' => (string) ($context['order_total'] ?? '0'),
            'currency' => strtoupper((string) ($context['currency'] ?? 'CZK')),
            'weight' => (string) ($context['weight'] ?? '1'),
            'eshop' => (string) $this->settings->get('shop.packeta_eshop', $this->settings->get('shop.store_name', 'GEREQ')),
        ];

        $pointId = $shipment->parcelPointId() ?: (string) ($context['parcel_point_id'] ?? '');
        if ($pointId !== '') {
            $attrs['addressId'] = $pointId;
        } else {
            $attrs['addressId'] = (string) ($context['carrier_id'] ?? $this->settings->get('shop.packeta_address_carrier_id', '106'));
            $attrs['street'] = (string) ($context['street'] ?? '');
            $attrs['houseNumber'] = (string) ($context['house_number'] ?? '');
            $attrs['city'] = (string) ($context['city'] ?? '');
            $attrs['zip'] = (string) ($context['zip'] ?? '');
        }

        if (!empty($context['cod'])) {
            $attrs['cod'] = (string) $context['cod'];
        }
        if (!empty($context['note'])) {
            $attrs['note'] = (string) $context['note'];
        }

        return $attrs;
    }

    private function fetchLabelDataUrl(string $password, string $packetId): ?string
    {
        $xml = $this->call('packetLabelPdf', [
            'apiPassword' => $password,
            'packetId' => $packetId,
            'format' => 'A6 on A6',
            'offset' => '0',
        ]);
        $pdf = $this->xpathText($xml, '//result') ?? $this->xpathText($xml, '//packetLabelPdfResult');
        if ($pdf === null || $pdf === '') {
            return null;
        }

        return 'data:application/pdf;base64,' . $pdf;
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, array $params): \SimpleXMLElement
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><' . $method . '/>');
        $this->arrayToXml($xml, $params);
        $body = $xml->asXML() ?: '';

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/xml\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::API, false, $ctx);
        if ($raw === false || $raw === '') {
            return new \SimpleXMLElement('<?xml version="1.0"?><response><fault>Packeta HTTP failed</fault></response>');
        }
        $parsed = @simplexml_load_string($raw);

        return $parsed instanceof \SimpleXMLElement
            ? $parsed
            : new \SimpleXMLElement('<?xml version="1.0"?><response><fault>Invalid Packeta XML</fault></response>');
    }

    /** @param array<string, mixed> $data */
    private function arrayToXml(\SimpleXMLElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $parent->addChild((string) $key);
                if ($child instanceof \SimpleXMLElement) {
                    $this->arrayToXml($child, $value);
                }
            } else {
                $parent->addChild((string) $key, htmlspecialchars((string) $value, ENT_XML1));
            }
        }
    }

    private function xpathText(\SimpleXMLElement $xml, string $path): ?string
    {
        $nodes = $xml->xpath($path);
        if ($nodes === false || $nodes === [] || !isset($nodes[0])) {
            return null;
        }
        $text = trim((string) $nodes[0]);

        return $text !== '' ? $text : null;
    }
}
