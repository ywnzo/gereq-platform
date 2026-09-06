<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application;

use Modules\SearchEngines\Application\Drivers\AutoKellyClient;
use Modules\SearchEngines\Application\Drivers\AutodilyProClient;
use Modules\SearchEngines\Application\Drivers\HeurekaClient;
use Modules\SearchEngines\Application\Drivers\HledejcenyClient;
use Modules\SearchEngines\Application\Drivers\JamaClient;
use Modules\SearchEngines\Application\Drivers\MojekoloClient;
use Modules\SearchEngines\Application\Drivers\SeznamZboziClient;

/**
 * Facade: veřejné srovnávače / e-shopy (Seznam Zboží, Heureka, Hledejceny, autodíly, Mojekolo).
 */
final class SearchEnginesProvider
{
    private readonly HeurekaClient $heureka;
    private readonly SeznamZboziClient $seznam;
    private readonly HledejcenyClient $hledejceny;
    private readonly MojekoloClient $mojekolo;
    private readonly JamaClient $jama;
    private readonly AutodilyProClient $autodilypro;
    private readonly AutoKellyClient $autokelly;

    public function __construct(
        ?HeurekaClient $heureka = null,
        ?SeznamZboziClient $seznam = null,
        ?HledejcenyClient $hledejceny = null,
        ?MojekoloClient $mojekolo = null,
        ?JamaClient $jama = null,
        ?AutodilyProClient $autodilypro = null,
        ?AutoKellyClient $autokelly = null,
    ) {
        $this->heureka = $heureka ?? new HeurekaClient();
        $this->seznam = $seznam ?? new SeznamZboziClient();
        $this->hledejceny = $hledejceny ?? new HledejcenyClient();
        $this->mojekolo = $mojekolo ?? new MojekoloClient();
        $this->jama = $jama ?? new JamaClient();
        $this->autodilypro = $autodilypro ?? new AutodilyProClient();
        $this->autokelly = $autokelly ?? new AutoKellyClient();
    }

    /**
     * @return list<array{id: string, name: string, region: string, available: bool, paginated: bool}>
     */
    public function engines(): array
    {
        return [
            [
                'id' => 'heureka',
                'name' => 'Heureka',
                'region' => 'CZ',
                'available' => $this->heureka->configured(),
                'paginated' => true,
            ],
            [
                'id' => 'seznam',
                'name' => 'Seznam Zboží',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
            [
                'id' => 'hledejceny',
                'name' => 'Hledejceny',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
            [
                'id' => 'jama',
                'name' => 'Autodíly Jama',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
            [
                'id' => 'autodilypro',
                'name' => 'Autodílypro',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
            [
                'id' => 'autokelly',
                'name' => 'AutoKelly',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
            [
                'id' => 'mojekolo',
                'name' => 'Mojekolo',
                'region' => 'CZ',
                'available' => true,
                'paginated' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function availableSourceIds(): array
    {
        $ids = [];
        foreach ($this->engines() as $engine) {
            if ($engine['available']) {
                $ids[] = $engine['id'];
            }
        }

        return $ids;
    }

    public function isAvailable(string $source): bool
    {
        return in_array($source, $this->availableSourceIds(), true);
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $source, string $query, int $limit = 20, int $page = 1): array
    {
        $source = trim($source);
        $query = trim($query);
        $limit = max(1, min(80, $limit));
        $page = max(1, $page);

        return match ($source) {
            'heureka' => $this->heureka->search($query, $limit, $page),
            'seznam' => $this->seznam->search($query, $limit),
            'hledejceny' => $this->hledejceny->search($query, $limit),
            'jama' => $this->jama->search($query, $limit),
            'autodilypro' => $this->autodilypro->search($query, $limit),
            'autokelly' => $this->autokelly->search($query, $limit),
            'mojekolo' => $this->mojekolo->search($query, $limit),
            default => ['ok' => false, 'items' => [], 'error' => 'searchengines.error.unknown_source', 'has_more' => false],
        };
    }

    public function searchUrl(string $source, string $query): string
    {
        return match (trim($source)) {
            'heureka' => HeurekaClient::searchUrl($query),
            'seznam' => SeznamZboziClient::searchUrl($query),
            'hledejceny' => HledejcenyClient::searchUrl($query),
            'jama' => JamaClient::searchUrl($query),
            'autodilypro' => AutodilyProClient::searchUrl($query),
            'autokelly' => AutoKellyClient::searchUrl($query),
            'mojekolo' => MojekoloClient::searchUrl($query),
            default => '',
        };
    }

    /**
     * Nabídky jednotlivých obchodů u produktu (Heureka / Seznam).
     *
     * @return array{ok: bool, offers: list<array<string, mixed>>, error: ?string}
     */
    public function offers(string $source, string $productUrl): array
    {
        return match (trim($source)) {
            'heureka' => $this->heureka->offers($productUrl),
            'seznam' => $this->seznam->offers($productUrl),
            default => ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.unknown_source'],
        };
    }

    /**
     * @return list<array{id: string, name: string, region: string, url: string}>
     */
    public function searchLinks(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $out = [];
        foreach ($this->engines() as $engine) {
            $url = $this->searchUrl($engine['id'], $q);
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => $engine['id'],
                'name' => $engine['name'],
                'region' => $engine['region'],
                'url' => $url,
            ];
        }

        return $out;
    }
}
