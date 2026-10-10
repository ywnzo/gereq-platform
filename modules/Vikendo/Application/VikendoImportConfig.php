<?php

declare(strict_types=1);

namespace Modules\Vikendo\Application;

use Gereq\Setting\Settings;

/** Runtime konfigurace importu Vikendo (okresy + stránkování) pro KdeAco. */
final class VikendoImportConfig
{
    /** @var array<string, string> EID → název okresu (výchozí Plzeňský kraj) */
    public const DISTRICTS = [
        '3401' => 'Domažlice',
        '3404' => 'Klatovy',
        '3405' => 'Plzeň-město',
        '3406' => 'Plzeň-jih',
        '3407' => 'Plzeň-sever',
        '3408' => 'Rokycany',
        '3410' => 'Tachov',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Vybrané okresy jako řádky (eid, name, region_eid).
     *
     * @return list<array{eid: string, name: string, region_eid: string}>
     */
    public function districts(): array
    {
        $raw = $this->settings->get('vikendo.district_eids', array_keys(self::DISTRICTS));
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/[\s,;]+/', $raw) ?: [];
            }
        }
        if (!is_array($raw)) {
            return $this->defaultDistrictRows();
        }

        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $eid = trim((string) ($item['eid'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $regionEid = trim((string) ($item['region_eid'] ?? ''));
            } else {
                $eid = trim((string) $item);
                $name = self::DISTRICTS[$eid] ?? $eid;
                $regionEid = isset(self::DISTRICTS[$eid]) ? VikendoClient::REGION_PLZENSKY : '';
            }
            if ($eid === '' || isset($seen[$eid])) {
                continue;
            }
            if ($name === '') {
                $name = self::DISTRICTS[$eid] ?? $eid;
            }
            $seen[$eid] = true;
            $out[] = [
                'eid' => $eid,
                'name' => $name,
                'region_eid' => $regionEid,
            ];
        }

        return $out !== [] ? $out : $this->defaultDistrictRows();
    }

    /** @return list<string> */
    public function districtEids(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['eid'],
            $this->districts(),
        ));
    }

    /** @return list<string> */
    public function districtLabels(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['name'],
            $this->districts(),
        ));
    }

    /** @return array<string, string> eid → name */
    public function districtNameMap(): array
    {
        $map = [];
        foreach ($this->districts() as $row) {
            $map[$row['eid']] = $row['name'];
        }

        return $map;
    }

    public function perPage(): int
    {
        $value = (int) $this->settings->get('vikendo.per_page', 100);

        return max(1, min(100, $value > 0 ? $value : 100));
    }

    public function maxPages(): int
    {
        $value = (int) $this->settings->get('vikendo.max_pages', 40);

        return max(1, min(100, $value > 0 ? $value : 40));
    }

    public function startFrom(): ?string
    {
        $value = trim((string) $this->settings->get('vikendo.start_from', ''));

        return $value !== '' ? $value : null;
    }

    public function importEnabled(): bool
    {
        return filter_var($this->settings->get('vikendo.import_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param list<array{eid?: string, name?: string, region_eid?: string}|string> $rows
     */
    public function saveDistricts(array $rows): void
    {
        $clean = [];
        $seen = [];
        foreach ($rows as $item) {
            if (is_array($item)) {
                $eid = trim((string) ($item['eid'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $regionEid = trim((string) ($item['region_eid'] ?? ''));
            } else {
                $eid = trim((string) $item);
                $name = self::DISTRICTS[$eid] ?? '';
                $regionEid = isset(self::DISTRICTS[$eid]) ? VikendoClient::REGION_PLZENSKY : '';
            }
            if ($eid === '' || !preg_match('/^\d{3,6}$/', $eid) || isset($seen[$eid])) {
                continue;
            }
            if ($name === '') {
                $name = self::DISTRICTS[$eid] ?? ('Okres ' . $eid);
            }
            $seen[$eid] = true;
            $clean[] = [
                'eid' => $eid,
                'name' => $name,
                'region_eid' => $regionEid,
            ];
        }
        if ($clean === []) {
            $clean = $this->defaultDistrictRows();
        }
        $this->settings->set(
            'vikendo.district_eids',
            $clean,
            \Gereq\Setting\SettingRecord::TYPE_JSON,
            'vikendo',
        );
    }

    /** @return list<array{eid: string, name: string, region_eid: string}> */
    private function defaultDistrictRows(): array
    {
        $out = [];
        foreach (self::DISTRICTS as $eid => $name) {
            $out[] = [
                'eid' => $eid,
                'name' => $name,
                'region_eid' => VikendoClient::REGION_PLZENSKY,
            ];
        }

        return $out;
    }
}
