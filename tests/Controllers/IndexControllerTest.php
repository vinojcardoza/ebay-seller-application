<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Catalog\StockLevels;
use App\Controllers\IndexController;
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
 * Tests for the dashboard, with real views and a fake DBAL connection.
 */
#[CoversClass(IndexController::class)]
#[CoversClass(StockLevels::class)]
final class IndexControllerTest extends TestCase
{
    /**
     * Totals row returned for the current month's period query.
     *
     * @var array<string, string|int>
     */
    private array $currentMonth = [];

    /**
     * Totals row returned for the previous month's comparison period query.
     *
     * @var array<string, string|int>
     */
    private array $previousMonth = [];

    /**
     * All-time item cost (item cost × quantity) returned by the sales totals query.
     */
    private string $allTimeCost = '20000.00';

    /**
     * Rows the stock query returns.
     *
     * @var list<array<string, string|null>>
     */
    private array $stockRows = [];

    /**
     * Parameters passed to each period query, in call order (current month first).
     *
     * @var list<list<mixed>>
     */
    private array $periodParams = [];

    /**
     * Seed a current month with sales up on the previous month but profit down.
     */
    protected function setUp(): void
    {
        $this->periodParams = [];
        $this->allTimeCost = '20000.00';
        $this->stockRows = [
            $this->stockRow(1, 'Zener <Diode>', 20, 5, 0, '10'),
            $this->stockRow(2, 'Heatsink TO-220', 12, 9, 0, '5'),
            $this->stockRow(4, 'LCD Display', 8, 8, 20, '10'),
        ];
        $this->currentMonth = $this->totals(
            orders: 40,
            units: 52,
            sold: '1150.00',
            fees: '150.00',
            net: '1000.00',
            cost: '600.00',
            shipping: '100.00'
        );
        $this->previousMonth = $this->totals(
            orders: 32,
            units: 40,
            sold: '920.00',
            fees: '120.00',
            net: '800.00',
            cost: '350.00',
            shipping: '50.00'
        );
    }

    /**
     * The dashboard renders the all-time KPIs (including profit), order types and no recent orders.
     */
    public function testIndexShowsOverallFigures(): void
    {
        $response = $this->controller()->index();
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression('#kpi-value">1,234</p>\s*<p class="kpi-label">Total orders<#', $body);
        $this->assertMatchesRegularExpression('#kpi-value">£56,789.50</p>\s*<p class="kpi-label">Sales<#', $body);
        $this->assertMatchesRegularExpression(
            '#tint-info"><i class="ti ti-trending-up"[^>]*></i></span>\s*<div>\s*'
                . '<p class="kpi-value">£35,000.00</p>\s*<p class="kpi-label">Total profit<#',
            $body,
            'Profit: 56,789.50 − 20,000.00 − 1,789.50.'
        );
        $this->assertMatchesRegularExpression('#kpi-value">87</p>\s*<p class="kpi-label">Active products<#', $body);
        $this->assertStringContainsString('Orders by type', $body);
        $this->assertStringNotContainsString('Recent orders', $body);
    }

    /**
     * The low stock card lists active low and out of stock products, lowest first, with links and counts.
     */
    public function testIndexListsLowStockProducts(): void
    {
        $body = $this->body($this->controller()->index());

        $this->assertMatchesRegularExpression('#<h2>\s*Low stock\s*<span[^>]*>\s*· 2 products#', $body);
        $this->assertStringContainsString('href="/products?stock=out&amp;status=active">', $body);
        $this->assertStringContainsString('1 out of stock', $body);
        $this->assertStringContainsString('href="/products?stock=low&amp;status=active"', $body);
        $this->assertStringNotContainsString('Zener', $body, 'In-stock products are not listed.');
        $this->assertLessThan(strpos($body, 'Heatsink TO-220'), strpos($body, 'LCD Display'), 'Lowest stock first.');

        $out = $this->rowFor($body, '/product/4"');
        $this->assertMatchesRegularExpression('#text-danger">\s*0\s*<#', $out);
        $this->assertStringContainsString('SKU-4', $out);
        $this->assertStringContainsString('href="/purchases?status=0&amp;q=SKU-4">', $out);
        $this->assertStringContainsString('tint-danger">Out of stock', $out);

        $low = $this->rowFor($body, '/product/2"');
        $this->assertMatchesRegularExpression('#fw-semibold">\s*3\s*<#', $low);
        $this->assertStringContainsString('<td class="text-end">5</td>', $low);
        $this->assertStringContainsString('tint-warning">Low stock', $low);
        $this->assertStringNotContainsString('Showing the', $body);
    }

    /**
     * Only the 10 lowest are listed, with a note giving the total.
     */
    public function testIndexLimitsLowStockList(): void
    {
        $this->stockRows = array_map(
            fn (int $id): array => $this->stockRow($id, 'Product ' . $id, 0, 0, 0, '5'),
            range(1, 12)
        );

        $body = $this->body($this->controller()->index());

        $this->assertSame(10, substr_count($body, 'tint-danger">Out of stock'));
        $this->assertStringContainsString('Showing the 10 lowest of 12.', $body);
    }

    /**
     * Without low stock products the card says everything is stocked and links nowhere.
     */
    public function testIndexWithoutLowStock(): void
    {
        $this->stockRows = [$this->stockRow(1, 'Zener Diode', 20, 5, 0, '10')];

        $body = $this->body($this->controller()->index());

        $this->assertStringContainsString('Every active product is above its low stock level.', $body);
        $this->assertStringNotContainsString('stock=low', $body);
    }

    /**
     * An all-time loss is shown in red with the minus sign before the pound sign.
     */
    public function testIndexShowsTotalLoss(): void
    {
        $this->allTimeCost = '60000.00';

        $body = $this->body($this->controller()->index());

        $this->assertMatchesRegularExpression(
            '#tint-danger"><i class="ti ti-trending-up"[^>]*></i></span>\s*<div>\s*'
                . '<p class="kpi-value">−£5,000.00</p>\s*<p class="kpi-label">Total profit<#',
            $body
        );
    }

    /**
     * The month summary names the month, links to its orders and shows its figures.
     */
    public function testIndexShowsCurrentMonthSummary(): void
    {
        $body = $this->body($this->controller()->index());

        $this->assertStringContainsString('Sales this month', $body);
        $this->assertStringContainsString('November 2026', $body);
        $this->assertStringContainsString('href="/orders?from=2026-11-01&amp;to=2026-11-30"', $body);

        $figures = ['Net sales' => '£1,000.00', 'Orders' => '40', 'Profit' => '£300.00', 'Average order' => '£25.00'];

        foreach ($figures as $label => $value) {
            $this->assertMatchesRegularExpression(
                '#kpi-label mt-0">' . $label . '</p>\s*<p class="kpi-value mb-1">' . preg_quote($value, '#') . '<#',
                $body
            );
        }

        $breakdown = ['Units sold' => '52', 'Gross sales' => '£1,150.00', 'Fees' => '£150.00',
            'Item cost' => '£600.00', 'Postage &amp; packaging' => '£100.00', 'Margin' => '30.0%'];

        foreach ($breakdown as $label => $value) {
            $this->assertMatchesRegularExpression(
                '#' . preg_quote($label, '#') . '</dt>\s*<dd class="mb-0 fw-semibold">'
                    . preg_quote($value, '#') . '<#',
                $body
            );
        }
    }

    /**
     * Each headline figure shows its change against the same stretch of last month.
     */
    public function testIndexComparesWithLastMonth(): void
    {
        $body = $this->body($this->controller()->index());

        $badge = '#tint-%s">\s*<i class="ti ti-trending-%s"[^>]*></i>\s*%s#';

        $this->assertMatchesRegularExpression(sprintf($badge, 'success', 'up', '\+25\.0%'), $body);
        $this->assertMatchesRegularExpression(sprintf($badge, 'danger', 'down', '−25\.0%'), $body);
        $this->assertStringContainsString('vs same time last month', $body);
        $this->assertStringNotContainsString('No sales same time last month', $body);
    }

    /**
     * Without sales last month there is nothing to compare, and an empty month has no margin.
     */
    public function testIndexWithEmptyMonths(): void
    {
        $this->currentMonth = $this->totals();
        $this->previousMonth = $this->totals();

        $body = $this->body($this->controller()->index());

        $this->assertStringContainsString('No sales same time last month', $body);
        $this->assertStringNotContainsString('vs same time last month', $body);
        $this->assertMatchesRegularExpression('#Margin</dt>\s*<dd class="mb-0 fw-semibold">—<#', $body);
    }

    /**
     * A loss is shown with the minus sign before the pound sign.
     */
    public function testIndexShowsNegativeProfit(): void
    {
        $this->currentMonth = $this->totals(orders: 2, net: '10.00', cost: '25.50');

        $body = $this->body($this->controller()->index());

        $this->assertMatchesRegularExpression('#Profit</p>\s*<p class="kpi-value mb-1">−£15.50<#', $body);
    }

    /**
     * The current month runs to the start of next month; last month's comparison stops at the same point in time.
     *
     * @param list<string> $current
     * @param list<string> $previous
     */
    #[DataProvider('periodProvider')]
    public function testIndexQueriesMonthPeriods(string $now, array $current, array $previous): void
    {
        $this->controller($now)->index();

        $this->assertSame([$current, $previous], $this->periodParams);
    }

    /**
     * Clock times with the expected current and comparison period bounds.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function periodProvider(): array
    {
        return [
            'mid month' => [
                '2026-11-10 12:00:00',
                ['2026-11-01 00:00:00', '2026-12-01 00:00:00'],
                ['2026-10-01 00:00:00', '2026-10-10 12:00:00'],
            ],
            'first moment of the month' => [
                '2026-11-01 00:00:00',
                ['2026-11-01 00:00:00', '2026-12-01 00:00:00'],
                ['2026-10-01 00:00:00', '2026-10-01 00:00:00'],
            ],
            'longer than last month' => [
                '2026-03-31 18:30:00',
                ['2026-03-01 00:00:00', '2026-04-01 00:00:00'],
                ['2026-02-01 00:00:00', '2026-03-01 00:00:00'],
            ],
            'january' => [
                '2027-01-15 09:00:00',
                ['2027-01-01 00:00:00', '2027-02-01 00:00:00'],
                ['2026-12-01 00:00:00', '2026-12-15 09:00:00'],
            ],
        ];
    }

    /**
     * A period totals row as the database returns it.
     *
     * @return array<string, string|int>
     */
    private function totals(
        int $orders = 0,
        int $units = 0,
        string $sold = '0',
        string $fees = '0',
        string $net = '0',
        string $cost = '0',
        string $shipping = '0',
    ): array {
        return compact('orders', 'units', 'sold', 'fees', 'net', 'cost', 'shipping');
    }

    /**
     * Build the controller with a frozen clock and a fake connection answering each dashboard query.
     */
    private function controller(string $now = '2026-11-10 12:00:00'): IndexController
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string => match (true) {
                str_contains($sql, 'products') => '87',
                str_contains($sql, 'postage_cost') => '1789.50',
                default => '1234',
            }
        );
        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'o.order_date >= ?')) {
                    return ['revenue' => '56789.50', 'items' => '4321', 'cost' => $this->allTimeCost];
                }

                $this->periodParams[] = $params;

                return count($this->periodParams) === 1 ? $this->currentMonth : $this->previousMonth;
            }
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'GROUP BY order_type')
                ? [['order_type' => 'Purchase', 'total' => '1200'], ['order_type' => 'Refund', 'total' => '34']]
                : $this->stockRows
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $factory = new Psr17Factory();

        return new IndexController(
            new PlatesRender(),
            $factory,
            $factory,
            $em,
            new FrozenClock($now),
            new StockLevels($em)
        );
    }

    /**
     * An active product's stock query row.
     *
     * @return array<string, string|null>
     */
    private function stockRow(int $id, string $title, int $delivered, int $sold, int $pending, ?string $lowStock): array
    {
        return [
            'id' => (string) $id,
            'sku' => 'SKU-' . $id,
            'title' => $title,
            'status' => '1',
            'low_stock' => $lowStock,
            'delivered' => (string) $delivered,
            'pending' => (string) $pending,
            'sold' => (string) $sold,
        ];
    }

    /**
     * The table row containing the given text.
     */
    private function rowFor(string $body, string $text): string
    {
        $at = strpos($body, $text);
        $this->assertNotFalse($at, "Row with $text not found.");
        $start = strrpos(substr($body, 0, $at), '<tr>');
        $end = strpos($body, '</tr>', $at);

        return substr($body, (int) $start, (int) $end - (int) $start);
    }

    /**
     * The response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
