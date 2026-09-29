<?php

declare(strict_types=1);

namespace Gereq\Currency\Infrastructure;

use Gereq\Currency\Application\RateProviderInterface;
use Gereq\Currency\Application\RateQuote;

/**
 * Daily CNB rates (foreign exchange market).
 *
 * @see https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/
 */
final class CnbRateProvider implements RateProviderInterface
{
    public const PROVIDER_ID = 'cnb';

    private const DEFAULT_URL = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';

    public function __construct(private readonly string $url = self::DEFAULT_URL)
    {
    }

    public function id(): string
    {
        return self::PROVIDER_ID;
    }

    public function label(): string
    {
        return 'ČNB — denní kurzy';
    }

    public function fetch(array $neededCodes, ?\DateTimeImmutable $asOf = null): array
    {
        $needed = [];
        foreach ($neededCodes as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '' && $code !== 'CZK') {
                $needed[$code] = true;
            }
        }

        if ($needed === []) {
            return [];
        }

        $url = $this->url;
        if ($asOf !== null) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'date=' . $asOf->format('d.m.Y');
        }

        $body = $this->download($url);
        return $this->parse($body, array_keys($needed));
    }

    /**
     * @param list<string> $neededCodes
     * @return list<RateQuote>
     */
    public function parse(string $body, array $neededCodes): array
    {
        $needed = array_fill_keys(array_map('strtoupper', $neededCodes), true);
        $lines = preg_split('/\R/', trim($body)) ?: [];
        if ($lines === []) {
            throw new \RuntimeException('ČNB vrátila prázdnou odpověď.');
        }

        $header = array_shift($lines) ?? '';
        $validAt = $this->parseHeaderDate($header);

        // Skip column header "země|měna|množství|kód|kurz"
        if ($lines !== [] && str_contains($lines[0], 'kód')) {
            array_shift($lines);
        }

        $quotes = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            if (count($parts) < 5) {
                continue;
            }

            $amount = (float) str_replace(',', '.', trim($parts[2]));
            $code = strtoupper(trim($parts[3]));
            $rawRate = (float) str_replace(',', '.', trim($parts[4]));

            if ($amount <= 0 || $rawRate <= 0 || !isset($needed[$code])) {
                continue;
            }

            // CNB: "amount" units of foreign currency = rawRate CZK → 1 unit = rawRate/amount CZK
            $ratePerUnit = $rawRate / $amount;
            $quotes[] = new RateQuote(
                baseCode: $code,
                quoteCode: 'CZK',
                rate: number_format($ratePerUnit, 12, '.', ''),
                validAt: $validAt,
                provider: self::PROVIDER_ID,
                sourceLabel: sprintf('ČNB %s (množství %s)', $code, rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.')),
            );
        }

        if ($quotes === []) {
            throw new \RuntimeException('ČNB nevrátila kurzy pro požadované měny: ' . implode(', ', array_keys($needed)));
        }

        return $quotes;
    }

    private function parseHeaderDate(string $header): \DateTimeImmutable
    {
        // "03.09.2026 #170"
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($header), $m) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%s-%s-%s', $m[3], $m[2], $m[1]), new \DateTimeZone('Europe/Prague'));
            if ($date instanceof \DateTimeImmutable) {
                return $date->setTime(14, 30); // ČNB publikuje kolem poledne; 14:30 je bezpečná platnost
            }
        }

        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Nelze inicializovat cURL pro ČNB.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'GEREQ-Platform/0.1 (+currency; ČNB rates)',
                CURLOPT_HTTPHEADER => ['Accept: text/plain'],
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                throw new \RuntimeException(sprintf(
                    'Stažení kurzů ČNB selhalo (HTTP %d)%s.',
                    $status,
                    $error !== '' ? ': ' . $error : '',
                ));
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => "User-Agent: GEREQ-Platform/0.1\r\nAccept: text/plain\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Stažení kurzů ČNB selhalo (file_get_contents).');
        }

        return $body;
    }
}
