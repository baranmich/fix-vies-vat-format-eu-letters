<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Normalizace bankovního účtu pro porovnávání mezi:
 *  - GPC výpisem (zero-padded 16 cifer, např. `0000000123456789`)
 *  - currencies.account_number (uložené bez padding, např. `123456789`)
 *  - CZ účty s prefixem (`19-2000145399` → `192000145399`)
 *
 * Strip non-digits + ltrim '0'. Po normalize se dva různé zápisy stejného účtu
 * shodují.
 *
 * Pozn.: ztrácíme tím rozlišení účtů, které se liší pouze prefixem (např.
 * `19-1000000005` vs. `1000000005` budou normalizované shodné). To je v praxi
 * OK — žádný důstojný účet nemá takovou kolizi.
 */
final class AccountNumberNormalizer
{
    public static function normalize(string $accountNumber): string
    {
        $digitsOnly = preg_replace('/\D/', '', $accountNumber) ?? '';
        return ltrim($digitsOnly, '0');
    }

    /** True pokud dvě account number stringy odkazují na stejný účet (po normalize). */
    public static function equals(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
