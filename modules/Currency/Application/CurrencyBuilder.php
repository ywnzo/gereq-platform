<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Setting\Settings;
use Modules\Currency\Domain\Currency;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Centrální helper pro měny a peněžní částky.
 *
 * make() vrací MoneyValue (raw / rawRounded / localized) v měně uživatele
 * a při potřebě převádí přes CurrencyConverter (ČNB kurzy).
 */
final class CurrencyBuilder
{
    private ?string $cachedShopDefault = null;

    private ?string $cachedDisplay = null;

    private ?string $forcedDisplay = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly CurrencyConverter $converter,
        private readonly Authenticator $auth,
        private readonly RequestStack $requests,
        private readonly UserCurrencyPreferenceStore $userPrefs,
    ) {
    }

    /**
     * Systémová / shop výchozí měna (základ pro ceny bez uvedené měny).
     */
    public function defaultCode(): string
    {
        return $this->shopDefaultCode();
    }

    /**
     * Měna zobrazení: vynucená → session → preferovaná uživatele → shop default.
     */
    public function displayCode(): string
    {
        if ($this->forcedDisplay !== null) {
            return $this->forcedDisplay;
        }
        if ($this->cachedDisplay !== null) {
            return $this->cachedDisplay;
        }

        $session = $this->requests->getCurrentRequest()?->getSession();
        if ($session !== null && $session->isStarted()) {
            $fromSession = strtoupper(trim((string) $session->get('currency.display', '')));
            if ($this->isActiveCode($fromSession)) {
                return $this->cachedDisplay = $fromSession;
            }
        }

        $user = $this->auth->user();
        $preferred = $user !== null ? $this->userPrefs->get($user->id()) : null;
        if (is_string($preferred) && $this->isActiveCode($preferred)) {
            return $this->cachedDisplay = strtoupper($preferred);
        }

        return $this->cachedDisplay = $this->shopDefaultCode();
    }

    /** Dočasně vynutit měnu zobrazení (request scope). */
    public function setDisplayCode(?string $code): void
    {
        if ($code === null || $code === '') {
            $this->forcedDisplay = null;
        } else {
            $normalized = strtoupper(trim($code));
            $this->forcedDisplay = $this->isActiveCode($normalized) ? $normalized : $this->shopDefaultCode();
        }
        $this->cachedDisplay = null;
    }

    public function rememberDisplayCode(string $code): void
    {
        $normalized = $this->code($code);
        $session = $this->requests->getCurrentRequest()?->getSession();
        if ($session !== null) {
            if (!$session->isStarted()) {
                $session->start();
            }
            $session->set('currency.display', $normalized);
        }
        $this->cachedDisplay = $normalized;
    }

    public function default(): ?Currency
    {
        return $this->find($this->shopDefaultCode());
    }

    /**
     * Normalizace ISO kódu; prázdný vstup → shop default (pro ukládání).
     * Pro UI měnu uživatele použij displayCode().
     */
    public function code(?string $code = null): string
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '' || preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            return $this->shopDefaultCode();
        }

        return $normalized;
    }

    public function find(?string $code): ?Currency
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '' || preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            return null;
        }
        $currency = $this->em->find(Currency::class, $normalized);

        return $currency instanceof Currency ? $currency : null;
    }

    public function symbol(?string $code = null): string
    {
        $resolved = ($code === null || trim((string) $code) === '')
            ? $this->displayCode()
            : $this->code($code);
        $currency = $this->find($resolved);
        if ($currency instanceof Currency) {
            return $currency->symbol();
        }

        return match ($resolved) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $resolved,
        };
    }

    public function decimalPlaces(?string $code = null): int
    {
        $resolved = ($code === null || trim((string) $code) === '')
            ? $this->displayCode()
            : $this->code($code);
        $currency = $this->find($resolved);

        return $currency instanceof Currency ? $currency->decimalPlaces() : 2;
    }

    /** Firma je plátce DPH (nastavení z profilu / settings). */
    public function isVatPayer(): bool
    {
        return (bool) $this->settings->get('currency.vat_payer', false);
    }

    /** Sazba DPH v procentech (např. 21). */
    public function vatRate(): float
    {
        $raw = $this->settings->get('currency.vat_rate', '21');
        $rate = is_numeric($raw) ? (float) $raw : 21.0;

        return max(0.0, min(100.0, $rate));
    }

    /**
     * Odstraní DPH z hrubé ceny, pokud jsme plátci.
     * Vstup = cena s DPH → výstup = cena bez DPH.
     */
    public function withoutVat(float $gross): float
    {
        if (!$this->isVatPayer()) {
            return $gross;
        }
        $rate = $this->vatRate();
        if ($rate <= 0.0) {
            return $gross;
        }

        return $gross / (1.0 + ($rate / 100.0));
    }

    /**
     * Ceny z vyhledávačů / marketů (typicky s DPH) → MoneyValue s odpočtem dle nastavení.
     *
     * @param string|null $from zdrojová měna částky (null = shop default)
     * @param string|null $to   cílová měna (null = displayCode uživatele)
     */
    public function makeMarket(string|int|float|null $amount, ?string $from = null, ?string $to = null): MoneyValue
    {
        return $this->make($amount, $from, $to, vatInclusive: true);
    }

    /**
     * Hlavní API — vždy MoneyValue v cílové měně (výchozí: měna uživatele).
     *
     * @param string|null $from zdrojová měna částky (null = shop default)
     * @param string|null $to   cílová měna (null = displayCode uživatele)
     * @param bool $vatInclusive true = částka obsahuje DPH → při plátci odečíst sazbu
     */
    public function make(
        string|int|float|null $amount,
        ?string $from = null,
        ?string $to = null,
        bool $vatInclusive = false,
    ): MoneyValue {
        $sourceCode = $from !== null && $from !== '' ? $this->code($from) : $this->shopDefaultCode();
        $targetCode = $to !== null && $to !== '' ? $this->code($to) : $this->displayCode();
        $symbol = $this->symbol($targetCode);
        $decimals = $this->decimalPlaces($targetCode);

        $parsed = $this->parse($amount);
        if ($parsed === null) {
            return new MoneyValue(
                raw: [$symbol, 0.0],
                rawRounded: [$symbol, $this->roundOutput(0.0, $decimals)],
                localized: '—',
                code: $targetCode,
                symbol: $symbol,
                sourceCode: $sourceCode,
                sourceAmount: null,
                rate: null,
            );
        }

        if ($vatInclusive) {
            $parsed = $this->withoutVat($parsed);
        }

        $rate = null;
        $converted = $parsed;
        if ($sourceCode !== $targetCode) {
            try {
                $result = $this->converter->convert($parsed, $sourceCode, $targetCode);
                $converted = (float) $result['target']['amount'];
                // convert() už zaokrouhluje — vezmi přesnější přes rate
                $rate = (string) ($result['rate'] ?? '');
                if ($rate !== '' && is_numeric($rate)) {
                    $converted = $parsed * (float) $rate;
                }
            } catch (\Throwable) {
                // bez kurzu zobrazíme původní měnu (nesmíme lhát o částce)
                $targetCode = $sourceCode;
                $symbol = $this->symbol($targetCode);
                $decimals = $this->decimalPlaces($targetCode);
                $converted = $parsed;
                $rate = null;
            }
        } else {
            $rate = '1';
        }

        $rounded = $this->roundOutput($converted, $decimals);
        $localized = $this->localize((float) $rounded, $targetCode, $symbol, $decimals);

        return new MoneyValue(
            raw: [$symbol, $converted],
            rawRounded: [$symbol, $rounded],
            localized: $localized,
            code: $targetCode,
            symbol: $symbol,
            sourceCode: $sourceCode,
            sourceAmount: $parsed,
            rate: $rate,
        );
    }

    /** Formát pro UI (localized string). $code = zdrojová měna částky. */
    public function format(string|int|float|null $amount, ?string $code = null): string
    {
        return $this->make($amount, $code)->localized;
    }

    /** Localized s ISO kódem: „1 234,56 CZK“. */
    public function formatWithCode(string|int|float|null $amount, ?string $code = null): string
    {
        $money = $this->make($amount, $code);
        if ($money->isEmpty()) {
            return '—';
        }
        $decimals = $this->decimalPlaces($money->code);
        $number = $this->formatNumber((float) $money->amount(), $money->code, $decimals);

        return trim($number . ' ' . $money->code);
    }

    /** Normalizovaná částka pro DB/API v cílové měně. */
    public function amount(string|int|float|null $amount, ?string $from = null, ?string $to = null): string
    {
        $money = $this->make($amount, $from, $to);
        $decimals = $this->decimalPlaces($money->code);
        $value = (float) $money->amount();

        return number_format($value, $decimals, '.', '');
    }

    public function parse(string|int|float|null $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $text = trim(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(["\xc2\xa0", "\u{202f}"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim(str_ireplace(['Kč', 'CZK', '€', 'EUR', '$', 'USD', '£', 'GBP'], '', $text));
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(?:\.\d{3})+(,\d{1,2})?$/u', $text) === 1) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif (preg_match('/^\d{1,3}(?: \d{3})+(,\d{1,2})?$/u', $text) === 1) {
            $text = str_replace(' ', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/u', $text) === 1) {
            $text = str_replace('.', '', $text);
        } elseif (str_contains($text, ',') && !str_contains($text, '.')) {
            $text = str_replace(',', '.', str_replace(' ', '', $text));
        } elseif (preg_match('/^\d+(?:\.\d{1,2})?$/u', $text) !== 1) {
            if (preg_match('/(\d{1,3}(?:[.\s]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)/u', $text, $m) === 1) {
                return $this->parse($m[1]);
            }

            return null;
        }

        if (!is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    public function iDokladId(?string $code = null): int
    {
        $code = $code !== null && $code !== '' ? $this->code($code) : $this->shopDefaultCode();
        $currency = $this->find($code);
        if ($currency instanceof Currency && $currency->iDokladId() !== null && $currency->iDokladId() > 0) {
            return $currency->iDokladId();
        }

        $fromList = $this->resolveFromIDokladLists($code);
        if ($fromList !== null) {
            return $fromList;
        }

        return 1;
    }

    private function resolveFromIDokladLists(string $code): ?int
    {
        $lists = $this->settings->get('idoklad.lists', []) ?: [];
        if (!is_array($lists)) {
            return null;
        }
        $items = $lists['currencies'] ?? [];
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowCode = strtoupper((string) ($row['Code'] ?? $row['code'] ?? ''));
            if ($rowCode !== $code) {
                continue;
            }
            $id = (int) ($row['Id'] ?? $row['id'] ?? 0);

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $idokladCurrencies
     */
    public function syncIDokladIds(array $idokladCurrencies): int
    {
        $updated = 0;
        foreach ($idokladCurrencies as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['Code'] ?? $row['code'] ?? '')));
            $id = (int) ($row['Id'] ?? $row['id'] ?? 0);
            if ($code === '' || $id <= 0 || preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                continue;
            }
            $currency = $this->find($code);
            if (!$currency instanceof Currency) {
                continue;
            }
            if ($currency->iDokladId() === $id) {
                continue;
            }
            $currency->setIDokladId($id);
            ++$updated;
        }
        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    /** @return list<string> */
    public function activeCodes(): array
    {
        $rows = $this->em->getRepository(Currency::class)->findBy(
            ['isActive' => true],
            ['code' => 'ASC'],
        );
        $codes = [];
        foreach ($rows as $row) {
            if ($row instanceof Currency) {
                $codes[] = $row->code();
            }
        }

        return $codes !== [] ? $codes : [$this->shopDefaultCode()];
    }

    public function clearCache(): void
    {
        $this->cachedShopDefault = null;
        $this->cachedDisplay = null;
    }

    private function shopDefaultCode(): string
    {
        if ($this->cachedShopDefault !== null) {
            return $this->cachedShopDefault;
        }

        $shop = strtoupper(trim((string) $this->settings->get('shop.default_currency', '')));
        if ($this->isActiveCode($shop)) {
            return $this->cachedShopDefault = $shop;
        }

        $default = $this->em->getRepository(Currency::class)->findOneBy(['isDefault' => true]);
        if ($default instanceof Currency && $default->isActive()) {
            return $this->cachedShopDefault = $default->code();
        }

        if ($this->isActiveCode('CZK')) {
            return $this->cachedShopDefault = 'CZK';
        }

        return $this->cachedShopDefault = 'CZK';
    }

    private function isActiveCode(string $code): bool
    {
        if ($code === '' || preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return false;
        }
        $found = $this->find($code);

        return $found instanceof Currency && $found->isActive();
    }

    private function localize(float $amount, string $code, string $symbol, int $decimals): string
    {
        $number = $this->formatNumber($amount, $code, $decimals);

        return match ($code) {
            'USD', 'GBP' => $symbol . ' ' . $number,
            default => trim($number . ' ' . $symbol),
        };
    }

    private function formatNumber(float $amount, string $code, int $decimals): string
    {
        $locale = $this->numberLocale($code);
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $out = $fmt->format($amount);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return match ($code) {
            'USD', 'GBP' => number_format($amount, $decimals, '.', ','),
            default => number_format($amount, $decimals, ',', ' '),
        };
    }

    private function numberLocale(string $code): string
    {
        $userLocale = $this->auth->user()?->locale();
        if (is_string($userLocale) && $userLocale !== '') {
            return str_replace('_', '-', $userLocale);
        }

        return match ($code) {
            'USD', 'GBP' => 'en-US',
            'EUR' => 'de-DE',
            default => 'cs-CZ',
        };
    }

    private function roundOutput(float $amount, int $decimals): float|int
    {
        $rounded = round($amount, max(0, $decimals));
        if ($decimals <= 0 || abs($rounded - round($rounded)) < 0.0000001) {
            return (int) round($rounded);
        }

        return $rounded;
    }
}
