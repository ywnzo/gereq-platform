<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Sreality;

/**
 * Mapuje interní listing na advert_data pro addAdvert (Sreality XML-RPC).
 */
final class SrealityAdvertMapper
{
    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $config
     * @param array<string, mixed> $apiSpec
     * @return array<string, mixed>
     */
    public function map(array $listing, array $config = [], array $apiSpec = []): array
    {
        $offer = strtolower((string) ($listing['offer_type'] ?? $listing['transaction'] ?? 'sale'));
        $property = strtolower((string) ($listing['property_type'] ?? $listing['category'] ?? 'flat'));
        $currency = strtoupper((string) ($listing['currency'] ?? 'CZK'));
        $lifetime = (int) ($config['advert_lifetime'] ?? 3);
        if ($lifetime <= 0) {
            $lifetime = 3;
        }

        $advertFunction = match (true) {
            in_array($offer, ['rent', 'pronajem', 'pronájem', 'lease'], true) => 2,
            in_array($offer, ['auction', 'drazba', 'dražba'], true) => 3,
            in_array($offer, ['share', 'podil', 'podíl'], true) => 4,
            default => 1,
        };

        $advertType = match (true) {
            in_array($property, ['house', 'dum', 'dům', 'vila'], true) => 2,
            in_array($property, ['land', 'pozemek', 'plot'], true) => 3,
            in_array($property, ['commercial', 'komercni', 'komerční', 'office', 'kancelar'], true) => 4,
            in_array($property, ['other', 'ostatni', 'ostatní', 'garage', 'garaz'], true) => 5,
            default => 1,
        };

        $priceUnit = $advertFunction === 2 ? 2 : 1;
        $currencyCode = match ($currency) {
            'USD' => 2,
            'EUR' => 3,
            default => 1,
        };

        $rkid = (string) ($listing['id'] ?? $listing['slug'] ?? '');
        $city = trim((string) ($listing['city'] ?? $listing['locality_city'] ?? ''));
        if ($city === '') {
            $city = 'neuvedeno';
        }
        $description = trim((string) ($listing['description'] ?? $listing['title'] ?? ''));
        if ($description === '') {
            $description = (string) ($listing['title'] ?? 'Nabídka');
        }

        $advert = [
            'advert_function' => $advertFunction,
            'advert_lifetime' => $lifetime,
            'advert_price' => (float) ($listing['price'] ?? 0),
            'advert_price_currency' => $currencyCode,
            'advert_price_unit' => $priceUnit,
            'advert_type' => $advertType,
            'description' => $description,
            'locality_city' => $city,
            'locality_inaccuracy_level' => (int) ($config['locality_inaccuracy_level'] ?? 1),
            'advert_rkid' => $rkid,
            'advert_code' => (string) ($listing['slug'] ?? $rkid),
        ];

        $street = trim((string) ($listing['street'] ?? $listing['address'] ?? ''));
        if ($street !== '') {
            $advert['locality_street'] = $street;
        }
        if (isset($listing['latitude']) && $listing['latitude'] !== null && $listing['latitude'] !== '') {
            $advert['locality_latitude'] = (float) $listing['latitude'];
        }
        if (isset($listing['longitude']) && $listing['longitude'] !== null && $listing['longitude'] !== '') {
            $advert['locality_longitude'] = (float) $listing['longitude'];
        }

        $area = $listing['area_m2'] ?? $listing['usable_area'] ?? null;
        if ($area !== null && $area !== '') {
            $advert['usable_area'] = (int) round((float) $area);
        }

        $subtype = $this->mapSubtype($listing, $advertType, $apiSpec);
        if ($subtype !== null) {
            $advert['advert_subtype'] = $subtype;
        }

        // Povinné booly podle kategorie — default false, ať payload projde validací.
        if ($advertType === 1) {
            $advert['balcony'] = (bool) ($listing['balcony'] ?? false);
            $advert['loggia'] = (bool) ($listing['loggia'] ?? false);
            $advert['terrace'] = (bool) ($listing['terrace'] ?? false);
            $advert['cellar'] = (bool) ($listing['cellar'] ?? false);
            $advert['garage'] = (bool) ($listing['garage'] ?? false);
            $advert['parking_lots'] = (bool) ($listing['parking'] ?? $listing['parking_lots'] ?? false);
            $advert['floor_number'] = (int) ($listing['floor'] ?? $listing['floor_number'] ?? 1);
            $advert['ownership'] = (int) ($listing['ownership'] ?? 1);
            $advert['building_condition'] = (int) ($listing['building_condition'] ?? 2);
            $advert['building_type'] = (int) ($listing['building_type'] ?? 2);
        }
        if ($advertType === 2) {
            $advert['basin'] = (bool) ($listing['pool'] ?? $listing['basin'] ?? false);
            $advert['cellar'] = (bool) ($listing['cellar'] ?? false);
            $advert['garage'] = (bool) ($listing['garage'] ?? false);
            $advert['parking_lots'] = (bool) ($listing['parking'] ?? $listing['parking_lots'] ?? false);
            $advert['estate_area'] = (int) ($listing['estate_area'] ?? $listing['land_area'] ?? $area ?? 0);
            $advert['building_condition'] = (int) ($listing['building_condition'] ?? 2);
            $advert['building_type'] = (int) ($listing['building_type'] ?? 2);
            $advert['object_type'] = (int) ($listing['object_type'] ?? 2);
            $rooms = (int) ($listing['rooms'] ?? 0);
            if ($rooms > 0) {
                $advert['advert_room_count'] = min(5, max(1, $rooms));
            }
        }
        if ($advertType === 3) {
            $advert['estate_area'] = (int) ($listing['estate_area'] ?? $listing['land_area'] ?? $area ?? 0);
        }
        if (in_array($advertType, [4, 5], true)) {
            $advert['garage'] = (bool) ($listing['garage'] ?? false);
            $advert['parking_lots'] = (bool) ($listing['parking'] ?? $listing['parking_lots'] ?? false);
            $advert['building_condition'] = (int) ($listing['building_condition'] ?? 2);
            $advert['building_type'] = (int) ($listing['building_type'] ?? 2);
            if ($advertType === 4) {
                $advert['object_type'] = (int) ($listing['object_type'] ?? 2);
            }
        }

        if ($advertFunction === 2) {
            $ready = $listing['ready_date'] ?? null;
            $advert['ready_date'] = is_string($ready) && $ready !== ''
                ? $ready
                : (new \DateTimeImmutable('+1 month'))->format('Ymd');
        }

        if (!empty($config['seller_rkid'])) {
            $advert['seller_rkid'] = (string) $config['seller_rkid'];
        }

        return $advert;
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $apiSpec
     */
    private function mapSubtype(array $listing, int $advertType, array $apiSpec): ?int
    {
        if (isset($listing['advert_subtype']) && is_numeric($listing['advert_subtype'])) {
            return (int) $listing['advert_subtype'];
        }
        $raw = strtolower(trim((string) ($listing['disposition'] ?? $listing['rooms_label'] ?? $listing['subtype'] ?? '')));
        $rooms = (int) ($listing['rooms'] ?? 0);

        if ($advertType === 1) {
            $map = [
                '1+kk' => 2, '1+1' => 3, '2+kk' => 4, '2+1' => 5,
                '3+kk' => 6, '3+1' => 7, '4+kk' => 8, '4+1' => 9,
                '5+kk' => 10, '5+1' => 11, '6' => 12, 'atyp' => 16, 'pokoj' => 47,
            ];
            if ($raw !== '' && isset($map[$raw])) {
                return $map[$raw];
            }
            return match (true) {
                $rooms <= 1 => 3,
                $rooms === 2 => 5,
                $rooms === 3 => 7,
                $rooms === 4 => 9,
                $rooms >= 5 => 11,
                default => 16,
            };
        }
        if ($advertType === 2) {
            return 37; // Rodinný
        }
        if ($advertType === 3) {
            return 19; // Bydlení
        }
        if ($advertType === 4) {
            return 32; // Ostatní komerční
        }

        return 36; // Ostatní
    }
}
