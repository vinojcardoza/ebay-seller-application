<?php

declare(strict_types=1);

namespace Tests\Controllers;

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
     * The dashboard renders the all-time KPIs, order types and recent orders.
     */
    public function testIndexShowsOverallFigures(): void
    {
        $response = $this->controller()->index();
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression('#kpi-value">1,234</p>\s*<p class="kpi-label">Total orders<#', $body);
        $this->assertMatchesRegularExpression('#kpi-value">£56,789.50</p>\s*<p class="kpi-label">Sales<#', $body);
        $this->assertMatchesRegularExpression('#kpi-value">87</p>\s*<p class="kpi-label">Active products<#', $body);
        $this->assertStringContainsString('Orders by type', $body);
        $this->assertStringContainsString('12-34567-89012', $body);
        $this->assertStringContainsString('Ann &lt;Smith&gt;', $body);
        $this->assertStringContainsString('£19.99', $body);
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
            static fn (string $sql): string => str_contains($sql, 'products') ? '87' : '1234'
        );
        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'o.order_date >= ?')) {
                    return ['revenue' => '56789.50', 'items' => '4321'];
                }

                $this->periodParams[] = $params;

                return count($this->periodParams) === 1 ? $this->currentMonth : $this->previousMonth;
            }
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'GROUP BY order_type')
                ? [['order_type' => 'Purchase', 'total' => '1200'], ['order_type' => 'Refund', 'total' => '34']]
                : [[
                    'id' => '9', 'order_reference' => '12-34567-89012', 'order_date' => '2026-11-09 10:00:00',
                    'platform' => 'Ebay', 'order_type' => 'Purchase', 'full_name' => 'Ann <Smith>', 'total' => '19.99',
                ]]
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $factory = new Psr17Factory();

        return new IndexController(new PlatesRender(), $factory, $factory, $em, new FrozenClock($now));
    }

    /**
     * The response body as a string.
     */
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
