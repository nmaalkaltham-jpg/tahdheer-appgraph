<?php

namespace Tests\Unit\Commerce;

use App\Domain\Payments\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §٣ — المبالغ من المزوّد بلا فاصلةٍ عائمة ولا تسامح. */
class MoneyTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?int, ?string}> */
    public static function amounts(): iterable
    {
        yield 'float 552.5' => [552.5, 55250, null];
        yield 'string 552.50' => ['552.50', 55250, null];
        yield 'int 400' => [400, 40000, null];
        yield 'one halala' => ['0.01', 1, null];
        yield 'float that is not exact in binary' => [0.1 + 0.2, 30, null];
        yield '10.05 (classic rounding trap)' => [10.05, 1005, null];
        yield 'trailing zeros ok' => ['1.500', 150, null];
        yield 'three decimals' => ['552.555', null, 'AMOUNT_PRECISION'];
        yield 'zero' => [0, null, 'AMOUNT_NOT_POSITIVE'];
        yield 'zero string' => ['0.00', null, 'AMOUNT_NOT_POSITIVE'];
        yield 'negative' => [-400, null, 'AMOUNT_MALFORMED'];
        yield 'exponent string' => ['4e2', null, 'AMOUNT_MALFORMED'];
        yield 'comma decimal' => ['400,00', null, 'AMOUNT_MALFORMED'];
        yield 'missing' => [null, null, 'AMOUNT_MISSING'];
        yield 'empty' => ['', null, 'AMOUNT_MISSING'];
        yield 'array' => [['400'], null, 'AMOUNT_MALFORMED'];
        yield 'bool' => [true, null, 'AMOUNT_MALFORMED'];
        yield 'huge' => ['123456789012345.00', null, 'AMOUNT_MALFORMED'];
        yield 'nan' => [NAN, null, 'AMOUNT_MALFORMED'];
    }

    #[DataProvider('amounts')]
    public function test_provider_amounts_parse_exactly_or_are_refused(mixed $input, ?int $minor, ?string $problem): void
    {
        $this->assertSame(['minor' => $minor, 'problem' => $problem], Money::parseProviderAmount($input));
    }

    public function test_formatting_minor_units_is_exact(): void
    {
        $this->assertSame('552.50', Money::formatMinor(55250));
        $this->assertSame('10.05', Money::formatMinor(1005));
        $this->assertSame('0.01', Money::formatMinor(1));
        $this->assertSame('99999999.99', Money::formatMinor(9999999999));
    }

    public function test_every_minor_amount_round_trips(): void
    {
        for ($minor = 1; $minor <= 20000; $minor++) {
            $this->assertSame($minor, Money::parseProviderAmount(Money::formatMinor($minor))['minor']);
        }
    }
}
