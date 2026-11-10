<?php

declare(strict_types=1);

namespace App\Ebay\Import;

/**
 * Money helpers working in whole pence, so amounts split and add up exactly.
 */
final class Money
{
    /**
     * Convert a decimal amount (e.g. "6.99" from eBay or a DECIMAL column) to pence.
     */
    public static function toPence(string|int|float|null $amount): int
    {
        return $amount === null || $amount === '' ? 0 : (int) round((float) $amount * 100);
    }

    /**
     * Format pence as a DECIMAL(…,2) string, e.g. 699 → "6.99".
     */
    public static function format(int $pence): string
    {
        return number_format($pence / 100, 2, '.', '');
    }

    /**
     * Split a total in proportion to weights. Shares are rounded down and the
     * last share takes the remainder, so the result always sums to the total.
     * When every weight is zero the total is split evenly.
     *
     * @param list<int> $weights
     *
     * @return list<int>
     */
    public static function split(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_sum($weights);

        if ($sum <= 0) {
            $weights = array_fill(0, count($weights), 1);
            $sum = count($weights);
        }

        $shares = [];
        $allocated = 0;
        $last = count($weights) - 1;

        foreach ($weights as $i => $weight) {
            $share = $i === $last ? $total - $allocated : intdiv($total * $weight, $sum);
            $shares[] = $share;
            $allocated += $share;
        }

        return $shares;
    }
}
