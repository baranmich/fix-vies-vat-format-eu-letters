<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountNumberNormalizerTest extends TestCase
{
    #[DataProvider('normalizeCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizeCases(): iterable
    {
        yield 'zero-padded GPC'         => ['0000000123456789', '123456789'];
        yield 'plain digits'            => ['123456789',        '123456789'];
        yield 'CZ prefix dash'          => ['19-2000145399',    '192000145399'];
        yield 'spaces'                  => ['1 000 000 005',    '1000000005'];
        yield 'prefix + zero padding'   => ['0000019-2000145399', '192000145399'];
        yield 'leading zeros only'      => ['0000000000',       ''];
        yield 'empty'                   => ['',                  ''];
        yield 'IBAN style stripped'     => ['CZ6508000000192000145399', '6508000000192000145399'];
    }

    public function testEqualsZeroPaddedVsPlain(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('0000000123456789', '123456789'));
        self::assertTrue(AccountNumberNormalizer::equals('123456789', '0000000123456789'));
    }

    public function testEqualsDifferentAccounts(): void
    {
        self::assertFalse(AccountNumberNormalizer::equals('1000000005', '1000000006'));
    }

    public function testEqualsPrefixVsBase(): void
    {
        // Note: prefixed account `19-1000000005` normalizes to `191000000005`,
        // a different value than `1000000005`. So they are NOT considered same.
        self::assertFalse(AccountNumberNormalizer::equals('19-1000000005', '1000000005'));
    }

    public function testEqualsEmptyEmpty(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('', ''));
        self::assertTrue(AccountNumberNormalizer::equals('0000', ''));
    }
}
