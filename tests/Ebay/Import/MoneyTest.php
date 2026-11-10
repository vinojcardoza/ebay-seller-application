<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\Import\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for pence conversion, formatting and proportional splitting.
 */
#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    /**
     * Amounts and their value in pence.
     *
     * @return array<string, array{string|int|float|null, int}>
     */
    public static function toPenceProvider(): array
    {
        return [
            'decimal string' => ['6.99', 699],
            'float rounding' => [0.29, 29],
            'integer' => [5, 500],
            'negative' => ['-1.50', -150],
            'empty' => ['', 0],
            'null' => [null, 0],
        ];
    }

    /**
     * Amounts convert to whole pence, rounding float error.
     */
    #[DataProvider('toPenceProvider')]
    public function testToPence(string|int|float|null $amount, int $pence): void
    {
        $this->assertSame($pence, Money::toPence($amount));
    }

    /**
     * Pence format as two-decimal strings without thousands separators.
     */
    public function testFormat(): void
    {
        $this->assertSame('6.99', Money::format(699));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('1234.50', Money::format(123450));
        $this->assertSame('-0.62', Money::format(-62));
    }

    /**
     * Totals, weights and the expected shares.
     *
     * @return array<string, array{int, list<int>, list<int>}>
     */
    public static function splitProvider(): array
    {
        return [
            'proportional' => [100, [600, 400], [60, 40]],
            'remainder to last' => [100, [1, 1, 1], [33, 33, 34]],
            'single' => [183, [699], [183]],
            'zero weights split evenly' => [10, [0, 0], [5, 5]],
            'no weights' => [10, [], []],
        ];
    }

    /**
     * Shares follow the weights and always add up to the total.
     *
     * @param list<int> $weights
     * @param list<int> $expected
     */
    #[DataProvider('splitProvider')]
    public function testSplit(int $total, array $weights, array $expected): void
    {
        $shares = Money::split($total, $weights);

        $this->assertSame($expected, $shares);

        if ($weights !== []) {
            $this->assertSame($total, array_sum($shares));
        }
    }
}
