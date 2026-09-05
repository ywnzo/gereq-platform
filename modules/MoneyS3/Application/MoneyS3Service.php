<?php

declare(strict_types=1);

namespace Modules\MoneyS3\Application;

use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

/**
 * Aplikační vrstva MoneyS3 — sync agend / středisek / řad do settings.
 */
final class MoneyS3Service
{
    public function __construct(
        private readonly MoneyS3Client $client,
        private readonly Settings $settings,
    ) {
    }

    public function client(): MoneyS3Client
    {
        return $this->client;
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function defaultAgendaId(): string
    {
        return trim((string) $this->settings->get('moneys3.default_agenda_id', ''));
    }

    public function getError(): ?string
    {
        return $this->client->getError();
    }

    /** @return array<string, mixed>|null */
    public function getAgendas(): ?array
    {
        return $this->client->getAgendas();
    }

    /** @return array<string, mixed>|null */
    public function getCentres(?string $agendaId = null): ?array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return null;
        }

        return $this->client->getCentres($agendaId);
    }

    /** @return array<string, mixed>|null */
    public function getNumericalSeries(?string $agendaId = null): ?array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return null;
        }

        return $this->client->getNumericalSeries($agendaId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function setIssuedInvoice(array $data, ?string $agendaId = null): ?array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return null;
        }

        return $this->client->setIssuedInvoice($agendaId, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function setCashVoucher(array $data, ?string $agendaId = null): ?array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return null;
        }

        return $this->client->setCashVoucher($agendaId, $data);
    }

    /**
     * @return array{ok: bool, message?: string, count?: int}
     */
    public function syncAgendas(): array
    {
        $response = $this->client->getAgendas();
        if ($response === null) {
            return ['ok' => false, 'message' => $this->client->getError() ?? 'moneys3.sync.failed'];
        }

        $items = $response['data']['agendas']['items'] ?? $response['agendas']['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $this->storeCache('agendas', $items);

        return ['ok' => true, 'count' => count($items)];
    }

    /**
     * @return array{ok: bool, message?: string, count?: int}
     */
    public function syncCentres(?string $agendaId = null): array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return ['ok' => false, 'message' => 'moneys3.error.no_agenda'];
        }

        $response = $this->client->getCentres($agendaId);
        if ($response === null) {
            return ['ok' => false, 'message' => $this->client->getError() ?? 'moneys3.sync.failed'];
        }

        $items = $response['data']['centres']['items'] ?? $response['centres']['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $this->storeCache('centres', $items);

        return ['ok' => true, 'count' => count($items)];
    }

    /**
     * @return array{ok: bool, message?: string, count?: int}
     */
    public function syncNumericalSeries(?string $agendaId = null): array
    {
        $agendaId = $agendaId ?: $this->defaultAgendaId();
        if ($agendaId === '') {
            return ['ok' => false, 'message' => 'moneys3.error.no_agenda'];
        }

        $response = $this->client->getNumericalSeries($agendaId);
        if ($response === null) {
            return ['ok' => false, 'message' => $this->client->getError() ?? 'moneys3.sync.failed'];
        }

        $items = $response['data']['numericalSeries']['items'] ?? $response['numericalSeries']['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $this->storeCache('numericalSeries', $items);

        return ['ok' => true, 'count' => count($items)];
    }

    /** @return list<mixed> */
    public function cached(string $name): array
    {
        $cache = $this->settings->get('moneys3.cache', []) ?: [];
        if (!is_array($cache)) {
            return [];
        }
        $items = $cache[$name] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @param list<mixed>|array<string, mixed> $items */
    private function storeCache(string $name, array $items): void
    {
        $cache = $this->settings->get('moneys3.cache', []) ?: [];
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache[$name] = array_values($items);
        $this->settings->set('moneys3.cache', $cache, SettingRecord::TYPE_JSON, 'moneys3');
    }
}
