<?php

declare(strict_types=1);

namespace Tests\Framework\Chart;

use App\Framework\Chart\ChartScale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the column chart y-axis scale.
 */
#[CoversClass(ChartScale::class)]
final class ChartScaleTest extends TestCase
{
    /**
     * The axis steps by 1, 2 or 5 × 10ⁿ from 0 to just above the highest value.
     *
     * @param list<int|float> $values
     * @param list<int> $ticks
     */
    #[DataProvider('scaleProvider')]
    public function testSeriesScalesToPeak(array $values, array $ticks, int $max): void
    {
        $series = ChartScale::series($this->points($values));

        $this->assertSame($ticks, $series['ticks']);
        $this->assertSame($max, $series['max']);
    }

    /**
     * Values with the expected ticks and axis maximum.
     *
     * @return array<string, array{list<int|float>, list<int>, int}>
     */
    public static function scaleProvider(): array
    {
        return [
            'no values' => [[], [0, 1], 1],
            'all zero' => [[0, 0], [0, 1], 1],
            'small counts' => [[3, 1], [0, 1, 2, 3], 3],
            'steps of 2' => [[7], [0, 2, 4, 6, 8], 8],
            'steps of 5' => [[17, 4], [0, 5, 10, 15, 20], 20],
            'steps of 10' => [[38], [0, 10, 20, 30, 40], 40],
            'steps of 500' => [[1234.56, 80.5], [0, 500, 1000, 1500], 1500],
            'peak between steps' => [[500], [0, 200, 400, 600], 600],
            'negative values ignored' => [[-50.5, 12], [0, 5, 10, 15], 15],
            'only negative values' => [[-20], [0, 1], 1],
        ];
    }

    /**
     * Points are returned unchanged and the total sums every value, negatives included.
     */
    public function testSeriesKeepsPointsAndTotals(): void
    {
        $points = $this->points([10.25, -2.5, 4]);

        $series = ChartScale::series($points);

        $this->assertSame($points, $series['points']);
        $this->assertSame(11.75, $series['total']);
        $this->assertSame(0, ChartScale::series($this->points([]))['total']);
    }

    /**
     * Chart points for the given values.
     *
     * @param list<int|float> $values
     *
     * @return list<array{label: string, axis: string, value: int|float}>
     */
    private function points(array $values): array
    {
        return array_map(
            static fn (int|float $value, int $i): array => [
                'label' => "Day $i",
                'axis' => (string) $i,
                'value' => $value,
            ],
            $values,
            array_keys($values)
        );
    }
}
