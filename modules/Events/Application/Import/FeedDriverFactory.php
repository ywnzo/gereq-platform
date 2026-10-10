<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

use Modules\Vikendo\Application\VikendoClient;
use Modules\Vikendo\Application\VikendoImportConfig;
use Modules\Events\Domain\EventFeed;

/** Builds a concrete EventSourceInterface from an EventFeed row. */
final class FeedDriverFactory
{
    public function __construct(
        private readonly VikendoClient $vikendo,
        private readonly VikendoImportConfig $vikendoConfig,
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
    }

    public function make(EventFeed $feed): EventSourceInterface
    {
        return match ($feed->driver()) {
            'ical' => new IcalSource(
                key: $feed->key(),
                url: $feed->url(),
                region: $feed->region(),
                organizer: $feed->organizer(),
                http: $this->http,
            ),
            'tribe_json' => new TribeJsonSource(
                key: $feed->key(),
                url: $feed->url(),
                region: $feed->region(),
                organizer: $feed->organizer() ?? 'MKS',
                categorySlug: $feed->configString('category', 'mks'),
                perPage: max(1, $feed->configInt('per_page', 50)),
                http: $this->http,
            ),
            'html_list' => new HtmlListSource(
                key: $feed->key(),
                url: $feed->url(),
                region: $feed->region(),
                organizer: $feed->organizer() ?? 'MKS',
                maxPages: max(1, $feed->configInt('max_pages', 8)),
                fetchDetails: ($feed->config()['fetch_details'] ?? true) !== false,
                map: HtmlScrapeMap::fromConfig($feed->config()),
                http: $this->http,
            ),
            'klatovy_asp' => new KlatovyAspSource(
                key: $feed->key(),
                url: $feed->url(),
                region: $feed->region(),
                organizer: $feed->organizer() ?? 'MKS Klatovy',
                venues: $feed->configList('venues') ?: ['KT_KD'],
                http: $this->http,
            ),
            'vikendo' => $this->makeVikendo($feed),
            default => throw new \InvalidArgumentException('Neznámý driver: ' . $feed->driver()),
        };
    }

    private function makeVikendo(EventFeed $feed): VikendoSource
    {
        if (!$this->vikendoConfig->importEnabled()) {
            throw new \RuntimeException('Import Vikendo je vypnutý v Admin → KdeAco → Vikendo.');
        }

        return new VikendoSource(
            client: $this->vikendo,
            key: $feed->key(),
            fallbackRegion: $feed->region(),
            organizer: $feed->organizer() ?? 'Vikendo',
            districtEids: $this->vikendoConfig->districtEids(),
            perPage: $this->vikendoConfig->perPage(),
            maxPages: $this->vikendoConfig->maxPages(),
            startFrom: $this->vikendoConfig->startFrom(),
            districtNames: $this->vikendoConfig->districtNameMap(),
        );
    }
}
