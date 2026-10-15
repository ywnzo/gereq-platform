<?php

declare(strict_types=1);

namespace Gereq\Ares;

/**
 * Domain layer over ARES — entity lookup and standardized RÚIAN addresses.
 */
final class AresService
{
    public function __construct(private readonly AresClient $client)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchSubjects(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $ico = $this->client->normalizeIco($query);

        if ($ico !== null && preg_match('/^\d{2,8}$/', preg_replace('/\D+/', '', $query) ?? '') === 1) {
            try {
                return [$this->mapSubject($this->client->getSubject($ico))];
            } catch (AresException $e) {
                if ($e->status() === 404) {
                    return [];
                }
                throw $e;
            }
        }

        $response = $this->client->searchSubjects([
            'obchodniJmeno' => $query,
            'pocet' => $limit,
            'start' => 0,
        ]);

        $items = $response['ekonomickeSubjekty'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = $this->mapSubject($item);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchAddresses(string $query, int $limit = 10, ?string $municipality = null): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $scope = trim((string) $municipality);
        // Storefront passes okres — never fall back to whole ČR when set.
        if ($scope !== '') {
            return $this->searchAddressesInDistrict($query, $limit, $scope);
        }

        // Single-word municipality name → municipality; if nothing, try street (Karlova ≠ municipality).
        if ($this->looksLikeCityOnly($query)) {
            $cities = $this->searchCity($query, $limit);
            if ($cities !== []) {
                return $cities;
            }

            return $this->searchStreetName($query, $limit);
        }

        $mapped = $this->searchTextAddress($query, $limit);
        if ($mapped !== []) {
            return $mapped;
        }

        $street = $this->guessStreetToken($query);
        if ($street !== null) {
            return $this->searchStreetName($street, $limit);
        }

        return [];
    }

    /**
     * Restrict results to one okres (events region). ARES `nazevOkresu` alone is unreliable,
     * so we bias the text query and then filter on the returned okres name.
     *
     * @return list<array<string, mixed>>
     */
    private function searchAddressesInDistrict(string $query, int $limit, string $district): array
    {
        $district = $this->normalizeDistrict($district);
        $cityHint = $this->cityHintForDistrict($district);
        $fetch = min(30, max($limit * 3, $limit));

        // City-only query — try municipality search first so it is not drowned by street hits.
        if ($this->looksLikeCityOnly($query)) {
            $cities = $this->filterByDistrict($this->searchCity($query, $fetch), $district, $limit);
            if ($cities !== []) {
                return $cities;
            }
        }

        // Prefer the typed text (may already include a town),
        // then bias with the district seat so vague streets stay inside the okres.
        $textQueries = [$query];
        if (!$this->queryMentions($query, $cityHint)) {
            $textQueries[] = $query . ', ' . $cityHint;
        }
        if ($cityHint !== $district && !$this->queryMentions($query, $district)) {
            $textQueries[] = $query . ', ' . $district;
        }

        foreach ($textQueries as $textQuery) {
            $rows = $this->filterByDistrict($this->searchTextAddress($textQuery, $fetch), $district, $limit);
            if ($rows !== []) {
                return $rows;
            }
        }

        $street = $this->guessStreetToken($query) ?? ($this->looksLikeCityOnly($query) ? null : $query);
        if ($street !== null && !$this->queryMentions($street, $cityHint)) {
            $rows = $this->filterByDistrict(
                $this->searchStreetInMunicipality($street, $cityHint, $fetch),
                $district,
                $limit,
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    private function normalizeDistrict(string $value): string
    {
        $value = trim($value);
        // Legacy aliases from older seeds / UI.
        return match ($value) {
            'Plzeň' => 'Plzeň-město',
            default => $value,
        };
    }

    private function cityHintForDistrict(string $district): string
    {
        return match ($district) {
            'Plzeň-město', 'Plzeň-sever', 'Plzeň-jih' => 'Plzeň',
            default => $district,
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filterByDistrict(array $rows, string $district, int $limit): array
    {
        $needle = mb_strtolower($district);
        $out = [];
        foreach ($rows as $row) {
            $okres = mb_strtolower(trim((string) ($row['district'] ?? '')));
            $city = mb_strtolower(trim((string) ($row['city'] ?? '')));
            $text = mb_strtolower(trim((string) ($row['text'] ?? '')));
            $cityHint = mb_strtolower($this->cityHintForDistrict($district));
            $matches = $okres === $needle
                || ($okres === '' && ($city === $needle || $city === $cityHint || str_contains($text, $needle)));
            if (!$matches) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchTextAddress(string $query, int $limit): array
    {
        try {
            $response = $this->client->searchAddresses([
                'typStandardizaceAdresy' => 'VYHOVUJICI_ADRESY',
                'textovaAdresa' => mb_substr($query, 0, 1500),
                'pocet' => $limit,
                'start' => 0,
            ]);
        } catch (AresException $e) {
            if ($this->isTooManyResults($e)) {
                $street = $this->guessStreetToken($query);
                if ($street !== null) {
                    $byStreet = $this->searchStreetName($street, $limit);
                    if ($byStreet !== []) {
                        return $byStreet;
                    }
                }
                $city = $this->guessCityToken($query);
                if ($city !== null) {
                    return $this->searchCity($city, $limit);
                }

                return [];
            }
            throw $e;
        }

        return $this->mapAddressList($response, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchCity(string $city, int $limit): array
    {
        $response = $this->client->searchAddresses([
            'typStandardizaceAdresy' => 'UPLNA_STANDARDIZACE',
            'nazevObce' => mb_substr($city, 0, 48),
            'pocet' => $limit,
            'start' => 0,
        ]);

        return $this->mapAddressList($response, $limit, cityHint: $city);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchStreetName(string $street, int $limit): array
    {
        try {
            $response = $this->client->searchAddresses([
                'typStandardizaceAdresy' => 'VYHOVUJICI_ADRESY',
                'nazevUlice' => mb_substr($street, 0, 48),
                'pocet' => $limit,
                'start' => 0,
            ]);
        } catch (AresException $e) {
            // Common street names exist in hundreds of towns — ask for a municipality.
            if ($this->isTooManyResults($e)) {
                return [];
            }
            throw $e;
        }

        return $this->mapAddressList($response, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchStreetInMunicipality(string $street, string $municipality, int $limit): array
    {
        try {
            $response = $this->client->searchAddresses([
                'typStandardizaceAdresy' => 'VYHOVUJICI_ADRESY',
                'nazevUlice' => mb_substr($street, 0, 48),
                'nazevObce' => mb_substr($municipality, 0, 48),
                'pocet' => $limit,
                'start' => 0,
            ]);
        } catch (AresException $e) {
            if ($this->isTooManyResults($e)) {
                return [];
            }
            throw $e;
        }

        return $this->mapAddressList($response, $limit);
    }

    private function queryMentions(string $query, string $needle): bool
    {
        return str_contains(mb_strtolower($query), mb_strtolower($needle));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function mapSubject(array $raw): array
    {
        $sidlo = is_array($raw['sidlo'] ?? null) ? $raw['sidlo'] : [];
        $address = $this->mapAddress($sidlo);

        return [
            'ico' => (string) ($raw['ico'] ?? $raw['icoId'] ?? ''),
            'dic' => isset($raw['dic']) ? (string) $raw['dic'] : null,
            'name' => (string) ($raw['obchodniJmeno'] ?? ''),
            'legal_form' => isset($raw['pravniForma']) ? (string) $raw['pravniForma'] : null,
            'address' => $address,
            'address_text' => $address['text'] ?? null,
            'label' => trim(sprintf(
                'IČO %s%s',
                (string) ($raw['ico'] ?? ''),
                ($address['text'] ?? '') !== '' ? ' · ' . $address['text'] : '',
            )),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function mapAddressList(array $response, int $limit, ?string $cityHint = null): array
    {
        $items = $response['standardizovaneAdresy'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mapped = $this->mapAddress($item);
            if (($mapped['city'] ?? '') === '' && $cityHint !== null) {
                $mapped['city'] = $cityHint;
                $mapped['text'] = $mapped['text'] !== '' ? $mapped['text'] : $cityHint;
                $mapped['label'] = $mapped['text'];
            }
            if (($mapped['text'] ?? '') === '' && ($mapped['city'] ?? '') === '') {
                continue;
            }
            $out[] = $mapped;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function mapAddress(array $raw): array
    {
        $streetName = trim((string) ($raw['nazevUlice'] ?? ''));
        $house = $this->formatHouseNumber(
            isset($raw['cisloDomovni']) ? (int) $raw['cisloDomovni'] : null,
            isset($raw['cisloOrientacni']) ? (int) $raw['cisloOrientacni'] : null,
            isset($raw['cisloOrientacniPismeno']) ? (string) $raw['cisloOrientacniPismeno'] : null,
        );
        $street = trim($streetName . ($house !== '' ? ' ' . $house : ''));

        $city = trim((string) ($raw['nazevObce'] ?? ''));
        $cityPart = trim((string) ($raw['nazevCastiObce'] ?? ''));
        if ($cityPart === '') {
            $cityPart = trim((string) ($raw['nazevMestskeCastiObvodu'] ?? ''));
        }
        $district = trim((string) ($raw['nazevOkresu'] ?? ''));

        $zip = isset($raw['psc']) ? preg_replace('/\D+/', '', (string) $raw['psc']) : '';
        $zip = is_string($zip) ? $zip : '';

        $text = trim((string) ($raw['textovaAdresa'] ?? ''));
        if ($text === '') {
            $parts = array_filter([$street, $cityPart, trim(($zip !== '' ? $zip . ' ' : '') . $city)]);
            $text = implode(', ', $parts);
        }

        return [
            'text' => $text,
            'label' => $text,
            'street' => $street !== '' ? $street : null,
            'street_name' => $streetName !== '' ? $streetName : null,
            'house_number' => $house !== '' ? $house : null,
            'city' => $city !== '' ? $city : null,
            'city_part' => $cityPart !== '' ? $cityPart : null,
            'district' => $district !== '' ? $district : null,
            'zip' => $zip !== '' ? $zip : null,
            'ruian_code' => isset($raw['kodAdresnihoMista']) ? (int) $raw['kodAdresnihoMista'] : null,
            'municipality_code' => isset($raw['kodObce']) ? (int) $raw['kodObce'] : null,
        ];
    }

    private function formatHouseNumber(?int $domovni, ?int $orientacni, ?string $letter): string
    {
        if ($domovni === null || $domovni <= 0) {
            return '';
        }

        $out = (string) $domovni;
        if ($orientacni !== null && $orientacni > 0) {
            $out .= '/' . $orientacni;
            $letter = $letter !== null ? trim($letter) : '';
            if ($letter !== '') {
                $out .= $letter;
            }
        }

        return $out;
    }

    private function looksLikeCityOnly(string $query): bool
    {
        if (preg_match('/\d/u', $query) === 1) {
            return false;
        }

        if (str_contains($query, ',') || str_contains($query, '/')) {
            return false;
        }

        $words = preg_split('/\s+/u', $query) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        $count = count($words);

        // Municipality: 1–3 words without a house number.
        if ($count >= 1 && $count <= 3) {
            return true;
        }

        return false;
    }

    private function guessCityToken(string $query): ?string
    {
        $parts = preg_split('/[,\s]+/u', $query) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== '' && preg_match('/\d/u', $p) !== 1));
        if ($parts === []) {
            return null;
        }

        return mb_substr((string) end($parts), 0, 48);
    }

    /** First non-numeric token — typically a street name in a broad text query. */
    private function guessStreetToken(string $query): ?string
    {
        $parts = preg_split('/[,\s]+/u', $query) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/\d/u', $part) === 1) {
                continue;
            }
            if (mb_strlen($part) < 2) {
                continue;
            }

            return mb_substr($part, 0, 48);
        }

        return null;
    }

    private function isTooManyResults(AresException $e): bool
    {
        return str_contains(mb_strtolower($e->getMessage()), 'příliš mnoho')
            || str_contains(mb_strtolower($e->getMessage()), 'prilis mnoho');
    }
}
