<?php

declare(strict_types=1);

namespace App\Framework\Chart;

/**
 * Builds chart series for `views/feedbacks/_column_chart.php`, with a clean
 * y-axis scale: 0 and up to 4 equal steps of 1, 2 or 5 × 10ⁿ.
 */
final class ChartScale
{
    /**
     * Number of steps the axis is aimed at above 0.
     */
    private const STEPS = 4;

    /**
     * Add the y-axis scale and total to chart points. Negative values sit on
     * the baseline: the scale only covers the highest positive value.
     *
     * @param list<array{label: string, axis: string, value: int|float}> $points
     *
     * @return array{points: list<array{label: string, axis: string, value: int|float}>, ticks: list<int>,
     *     max: int, total: int|float}
     */
    public static function series(array $points): array
    {
        $values = array_column($points, 'value');
        $peak = max([0, ...$values]);
        $rough = max(1, $peak / self::STEPS);
        $magnitude = 10 ** (int) floor(log10($rough));
        $step = 10 * $magnitude;

        foreach ([1, 2, 5] as $multiple) {
            if ($multiple * $magnitude >= $rough) {
                $step = $multiple * $magnitude;
                break;
            }
        }

        $max = $step * max(1, (int) ceil($peak / $step));

        return [
            'points' => $points,
            'ticks' => range(0, $max, $step),
            'max' => $max,
            'total' => array_sum($values),
        ];
    }
}
