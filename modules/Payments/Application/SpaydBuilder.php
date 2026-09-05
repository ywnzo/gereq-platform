<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

/** Czech Short Payment Descriptor (SPD/SPAYD) builder — provider-agnostic. */
final class SpaydBuilder
{
    /**
     * @param array{
     *   iban?: string,
     *   account?: string,
     *   bank_code?: string,
     *   amount: string|float,
     *   currency?: string,
     *   vs?: string,
     *   message?: string,
     *   recipient?: string
     * } $data
     */
    public static function build(array $data): string
    {
        $parts = ['SPD*1.0'];
        $iban = trim((string) ($data['iban'] ?? ''));
        if ($iban === '' && isset($data['account'], $data['bank_code'])) {
            $iban = self::czechAccountToIban((string) $data['account'], (string) $data['bank_code']);
        }
        if ($iban !== '') {
            $parts[] = 'ACC:' . strtoupper(str_replace(' ', '', $iban));
        }
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $parts[] = 'AM:' . $amount;
        $parts[] = 'CC:' . strtoupper((string) ($data['currency'] ?? 'CZK'));
        if (!empty($data['vs'])) {
            $parts[] = 'X-VS:' . preg_replace('/\D+/', '', (string) $data['vs']);
        }
        if (!empty($data['message'])) {
            $parts[] = 'MSG:' . self::sanitize((string) $data['message'], 60);
        }
        if (!empty($data['recipient'])) {
            $parts[] = 'RN:' . self::sanitize((string) $data['recipient'], 35);
        }

        return implode('*', $parts);
    }

    public static function czechAccountToIban(string $account, string $bankCode): string
    {
        $account = preg_replace('/\s+/', '', $account) ?? '';
        $bankCode = str_pad(preg_replace('/\D+/', '', $bankCode) ?? '', 4, '0', STR_PAD_LEFT);
        $prefix = '000000';
        $number = $account;
        if (str_contains($account, '-')) {
            [$prefix, $number] = explode('-', $account, 2);
        }
        $prefix = str_pad(preg_replace('/\D+/', '', $prefix) ?? '', 6, '0', STR_PAD_LEFT);
        $number = str_pad(preg_replace('/\D+/', '', $number) ?? '', 10, '0', STR_PAD_LEFT);
        $bban = $bankCode . $prefix . $number;
        $checkInput = $bban . '123500'; // CZ = 1235
        $mod = self::mod97($checkInput);
        $check = str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT);

        return 'CZ' . $check . $bban;
    }

    private static function mod97(string $numeric): int
    {
        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ((int) ($remainder . $digit)) % 97;
        }

        return $remainder;
    }

    private static function sanitize(string $value, int $max): string
    {
        $value = preg_replace('/[^\p{L}\p{N} \.\-]/u', '', $value) ?? '';
        $value = trim(str_replace('*', '', $value));

        return mb_substr($value, 0, $max);
    }
}
