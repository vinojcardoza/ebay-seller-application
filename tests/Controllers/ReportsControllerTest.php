<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\ReportsController;
use App\Framework\Template\PlatesRender;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\FrozenClock;

/**
 * Tests for the sales reports page, with real views and a fake DBAL connection.
 */
#[CoversClass(ReportsController::class)]
final class ReportsControllerTest extends TestCase
{
    /**
     * Daily totals rows keyed by Y-m-d, as the grouped query returns them.
     *
     * @var array<string, array<string, string|int>>
     */
    private array $days = [];

    /**
     * Monthly totals rows keyed by Y-m, as the grouped query returns them.
     *
     * @var array<string, array<string, string|int>>
     */
    private array $months = [];

    /**
     * Parameters passed to the daily and monthly queries, keyed 'daily'/'monthly'.
     *
     * @var array<string, list<mixed>>
     */
    private array $params = [];

    /**
     * Seed two days with sales and three months (one a loss).
     */
    protected function setUp(): void
    {
        $this->params = [];
        $this->days = [
            '2026-11-10' => $this->totals(3, 4, '50.00', '10.00', '40.00', '15.00', '5.00'),
            '2026-10-12' => $this->totals(1, 1, '6.00', '1.50', '4.50', '6.00', '1.55'),
        ];
        $this->months = [
            '2026-11' => $this->totals(40, 52, '1150.00', '150.00', '1000.00', '600.00', '100.00'),
            '2026-09' => $this->totals(2, 2, '6.00', '1.50', '4.50', '6.00', '1.55'),
            '2025-12' => $this->totals(10, 12, '230.00', '30.00', '200.00', '90.00', '30.00'),
        ];
    }

    /**
     * The page renders both reports with their headings and periods.
     */
    public function testIndexRendersBothReports(): void
    {
        $response = $this->controller()->index();
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<title>Reports', $body);
        $this->assertStringContainsString('Last 30 days', $body);
        $this->assertStringContainsString('12 Oct – 10 Nov 2026', $body);
        $this->assertStringContainsString('Monthly', $body);
        $this->assertStringContainsString('Dec 2025 – Nov 2026', $body);
        $this->assertStringContainsString('Monthly breakdown', $body);
    }

    /**
     * The daily report shows its totals as headline figures.
     */
    public function testIndexShowsDailySummary(): void
    {
        $daily = $this->section($this->body($this->controller()->index()), 'daily');

        $figures = ['£44.50' => 'Net sales', '4' => 'Orders', '£16.95' => 'Profit', '£11.13' => 'Average order'];

        foreach ($figures as $value => $label) {
            $this->assertMatchesRegularExpression(
                '#kpi-value">' . preg_quote((string) $value, '#') . '</p>\s*<p class="kpi-label">' . $label . '<#',
                $daily
            );
        }
    }

    /**
     * The daily report has no breakdown table; its only table is the chart's "Show as table" view.
     */
    public function testIndexHasNoDailyBreakdown(): void
    {
        $daily = $this->section($this->body($this->controller()->index()), 'daily');

        $this->assertStringNotContainsString('breakdown', $daily);
        $this->assertStringNotContainsString('table-hover', $daily);
        $this->assertStringNotContainsString('href="/orders?', $daily);
        $this->assertSame(1, substr_count($daily, '<table'));
    }

    /**
     * The monthly report lists 12 months newest first, empty months included, linking months with orders.
     */
    public function testIndexListsMonths(): void
    {
        $monthly = $this->section($this->body($this->controller()->index()), 'monthly');
        $table = $this->table($monthly);

        $this->assertSame(12, substr_count($table, '<tr') - 2);
        $this->assertLessThan(strpos($table, 'December 2025'), strpos($table, 'November 2026'));
        $this->assertStringContainsString('href="/orders?from=2026-11-01&amp;to=2026-11-30"', $table);
        $this->assertStringContainsString('href="/orders?from=2025-12-01&amp;to=2025-12-31"', $table);
        $this->assertStringNotContainsString('from=2026-10-01', $table);
        $this->assertMatchesRegularExpression(
            '#text-body-secondary">\s*<td class="text-nowrap">\s*October 2026#',
            $table
        );
    }

    /**
     * The monthly report shows its totals as headline figures.
     */
    public function testIndexShowsMonthlySummary(): void
    {
        $monthly = $this->section($this->body($this->controller()->index()), 'monthly');

        $figures = ['£1,204.50' => 'Net sales', '52' => 'Orders', '£376.95' => 'Profit', '£23.16' => 'Average order'];

        foreach ($figures as $value => $label) {
            $this->assertMatchesRegularExpression(
                '#kpi-value">' . preg_quote((string) $value, '#') . '</p>\s*<p class="kpi-label">' . $label . '<#',
                $monthly
            );
        }
    }

    /**
     * A month's row shows orders, units, each amount, profit and margin.
     */
    public function testIndexShowsMonthFigures(): void
    {
        $table = $this->table($this->section($this->body($this->controller()->index()), 'monthly'));

        $this->assertMatchesRegularExpression(
            '#November 2026\s*</a>\s*</td>\s*'
                . $this->cells('40', '52', '£1,150.00', '£150.00', '£1,000.00', '£600.00', '£100.00')
                . '\s*<td class="text-end text-nowrap">\s*£300.00\s*</td>\s*<td class="text-end">30.0%</td>#',
            $table
        );
    }

    /**
     * A loss is shown in red with the minus sign before the pound sign, and a negative margin.
     */
    public function testIndexShowsLoss(): void
    {
        $table = $this->table($this->section($this->body($this->controller()->index()), 'monthly'));

        $this->assertMatchesRegularExpression(
            '#September 2026\s*</a>.*?<td class="text-end text-nowrap text-danger">\s*−£3.05\s*</td>'
                . '\s*<td class="text-end">−67.8%</td>#s',
            $table
        );
    }

    /**
     * The totals row sums every month.
     */
    public function testIndexShowsMonthlyTotals(): void
    {
        $table = $this->table($this->section($this->body($this->controller()->index()), 'monthly'));

        $this->assertMatchesRegularExpression(
            '#<th scope="row">Total</th>\s*'
                . $this->cells('52', '66', '£1,386.00', '£181.50', '£1,204.50', '£696.00', '£131.55')
                . '\s*<td class="text-end text-nowrap">\s*£376.95\s*</td>\s*<td class="text-end">31.3%</td>#',
            $table
        );
    }

    /**
     * The daily chart plots profit per day in pounds, with pound axis labels and a "Profit" table view;
     * a loss-making day shows its negative profit but no bar.
     */
    public function testIndexChartsDailyProfit(): void
    {
        $daily = $this->section($this->body($this->controller()->index()), 'daily');

        // 10 Nov: 40.00 − 15.00 − 5.00; 12 Oct: 4.50 − 6.00 − 1.55.
        $this->assertStringContainsString('Profit per day', $daily);
        $this->assertStringNotContainsString('Net sales per day', $daily);
        $this->assertStringContainsString('£16.95 total', $daily);
        $this->assertMatchesRegularExpression('#data-tip-value="£20.00"\s+data-tip-label="Tue 10 Nov 2026"#', $daily);
        $this->assertStringContainsString('aria-label="Tue 10 Nov 2026: £20.00"', $daily);
        $this->assertStringContainsString('aria-label="Mon 12 Oct 2026: −£3.05"', $daily);
        $this->assertStringContainsString('<span>£20</span>', $daily);
        $this->assertSame(1, substr_count($daily, 'column-chart-fill'));
        $this->assertSame(30, substr_count($daily, 'class="column-chart-bar"'));
        $this->assertStringContainsString('<th scope="col" class="text-end">Profit</th></tr>', $daily);
    }

    /**
     * The monthly chart plots net sales per month with a "Net sales" table view.
     */
    public function testIndexChartsMonthlyNetSales(): void
    {
        $monthly = $this->section($this->body($this->controller()->index()), 'monthly');
        $this->assertStringContainsString('Net sales per month', $monthly);
        $this->assertMatchesRegularExpression(
            '#data-tip-value="£1,000.00"\s+data-tip-label="November 2026"#',
            $monthly
        );
        $this->assertSame(12, substr_count($monthly, 'class="column-chart-bar"'));
        $this->assertStringContainsString('<th scope="col" class="text-end">Net sales</th></tr>', $monthly);
    }

    /**
     * With no orders every figure is zero, margins are dashes and nothing links to orders.
     */
    public function testIndexWithNoOrders(): void
    {
        $this->days = [];
        $this->months = [];

        $body = $this->body($this->controller()->index());

        $this->assertStringNotContainsString('href="/orders?', $body);
        $this->assertStringNotContainsString('column-chart-fill', $body);
        $this->assertStringContainsString('£0.00 total', $body);
        $this->assertMatchesRegularExpression('#kpi-value">£0.00</p>\s*<p class="kpi-label">Average order<#', $body);
        $this->assertSame(12 + 1, substr_count($body, '<td class="text-end">—</td>'));
    }

    /**
     * The daily report covers today and the 29 days before; the monthly one the current month and the 11 before.
     *
     * @param list<string> $daily
     * @param list<string> $monthly
     */
    #[DataProvider('periodProvider')]
    public function testIndexQueriesReportPeriods(string $now, array $daily, array $monthly): void
    {
        $this->controller($now)->index();

        $this->assertSame(['daily' => $daily, 'monthly' => $monthly], $this->params);
    }

    /**
     * Clock times with the expected daily and monthly query bounds.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function periodProvider(): array
    {
        return [
            'mid month' => [
                '2026-11-10 12:00:00',
                ['2026-10-12 00:00:00', '2026-11-11 00:00:00'],
                ['2025-12-01 00:00:00', '2026-12-01 00:00:00'],
            ],
            'end of a long month' => [
                '2026-03-31 23:59:59',
                ['2026-03-02 00:00:00', '2026-04-01 00:00:00'],
                ['2025-04-01 00:00:00', '2026-04-01 00:00:00'],
            ],
            'new year' => [
                '2027-01-01 00:00:00',
                ['2026-12-03 00:00:00', '2027-01-02 00:00:00'],
                ['2026-02-01 00:00:00', '2027-02-01 00:00:00'],
            ],
        ];
    }

    /**
     * A period totals row as the grouped query returns it (without the period key).
     *
     * @return array<string, string|int>
     */
    private function totals(
        int $orders,
        int $units,
        string $sold,
        string $fees,
        string $net,
        string $cost,
        string $shipping,
    ): array {
        return compact('orders', 'units', 'sold', 'fees', 'net', 'cost', 'shipping');
    }

    /**
     * Pattern for consecutive right-aligned table cells (orders, units, then amounts) with the given values.
     */
    private function cells(string ...$values): string
    {
        return implode('\s*', array_map(
            static fn (string $value): string => '<td class="text-end[^"]*">' . preg_quote($value, '#') . '</td>',
            $values
        ));
    }

    /**
     * The HTML of one report section ('daily' or 'monthly').
     */
    private function section(string $body, string $id): string
    {
        $start = strpos($body, 'aria-labelledby="report-' . $id . '"');
        $this->assertNotFalse($start, "Missing $id section");
        $end = strpos($body, '</section>', $start);

        return substr($body, $start, ($end === false ? strlen($body) : $end) - $start);
    }

    /**
     * The breakdown table within a report section.
     */
    private function table(string $section): string
    {
        $start = strpos($section, '<table class="table table-hover');
        $this->assertNotFalse($start, 'Missing breakdown table');

        return substr($section, $start);
    }

    /**
     * Build the controller with a frozen clock and a fake connection answering the grouped queries.
     */
    private function controller(string $now = '2026-11-10 12:00:00'): ReportsController
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociativeIndexed')->willReturnCallback(
            function (string $sql, array $params = []): array {
                $key = str_contains($sql, 'DATE_FORMAT') ? 'monthly' : 'daily';
                $this->params[$key] = $params;

                return $key === 'monthly' ? $this->months : $this->days;
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $factory = new Psr17Factory();

        return new ReportsController(new PlatesRender(), $factory, $factory, $em, new FrozenClock($now));
    }

    /**
     * The response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
