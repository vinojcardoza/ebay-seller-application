<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Chart\ChartScale;
use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sales reports: day by day for the last 30 days and month by month for the
 * last 12 months, each with summary figures and a net sales chart; the
 * monthly report also has a breakdown table.
 *
 * Figures follow the orders page: net = sold price − fees, and profit =
 * net − item cost × quantity − postage − packaging. Orders of every type
 * count, so refunds and returns reduce the totals of the day they were made.
 */
class ReportsController extends ControllerAbstract
{
    /**
     * Days in the daily report, ending today.
     */
    public const DAYS = 30;

    /**
     * Months in the monthly report, ending with the current month.
     */
    public const MONTHS = 12;

    /**
     * Amount columns summed for each period, in display order.
     */
    private const AMOUNTS = ['sold', 'fees', 'net', 'cost', 'shipping'];

    /**
     * Receive the shared controller services and the clock that decides "today".
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * Show the daily and monthly sales reports.
     */
    public function index(): ResponseInterface
    {
        $today = $this->clock->now()->setTime(0, 0);

        return $this->render('reports/index', [
            'daily' => $this->dailyReport($today),
            'monthly' => $this->monthlyReport($today),
        ]);
    }

    /**
     * Sales per day for the DAYS days ending today.
     *
     * @return array<string, mixed> See report().
     */
    private function dailyReport(DateTimeImmutable $today): array
    {
        $start = $today->modify('-' . (self::DAYS - 1) . ' days');
        $totals = $this->periodTotals('DATE(o.order_date)', $start, $today->modify('+1 day'));
        $rows = [];

        for ($day = $start, $i = 0; $day <= $today; $day = $day->modify('+1 day'), $i++) {
            $key = $day->format('Y-m-d');
            $rows[] = $this->row($totals[$key] ?? [], [
                'label' => $day->format('D j M Y'),
                'axis' => $i % 7 === 0 ? $day->format('j M') : '',
                'from' => $key,
                'to' => $key,
            ]);
        }

        return $this->report($rows, $start, $today);
    }

    /**
     * Sales per month for the MONTHS months ending with the current month.
     *
     * @return array<string, mixed> See report().
     */
    private function monthlyReport(DateTimeImmutable $today): array
    {
        $last = $today->modify('first day of this month');
        $start = $last->modify('-' . (self::MONTHS - 1) . ' months');
        $totals = $this->periodTotals("DATE_FORMAT(o.order_date, '%Y-%m')", $start, $last->modify('+1 month'));
        $rows = [];

        for ($month = $start; $month <= $last; $month = $month->modify('+1 month')) {
            $rows[] = $this->row($totals[$month->format('Y-m')] ?? [], [
                'label' => $month->format('F Y'),
                'axis' => $month->format('M'),
                'from' => $month->format('Y-m-d'),
                'to' => $month->modify('last day of this month')->format('Y-m-d'),
            ]);
        }

        return $this->report($rows, $start, $last->modify('last day of this month'));
    }

    /**
     * Order and item totals per period for orders placed in [$from, $to),
     * keyed by the period expression's value (e.g. "2026-11-10" or "2026-11").
     *
     * @return array<string, array<string, mixed>>
     */
    private function periodTotals(string $period, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->em->getConnection()->fetchAllAssociativeIndexed(
            "SELECT $period AS period, COUNT(*) AS orders, COALESCE(SUM(i.units), 0) AS units,"
            . ' COALESCE(SUM(i.sold), 0) AS sold, COALESCE(SUM(i.fees), 0) AS fees,'
            . ' COALESCE(SUM(i.net), 0) AS net, COALESCE(SUM(i.cost), 0) AS cost,'
            . ' COALESCE(SUM(o.postage_cost), 0) + COALESCE(SUM(o.packaging_cost), 0) AS shipping'
            . ' FROM orders o LEFT JOIN (SELECT order_id, SUM(quantity) AS units, SUM(sold_price) AS sold,'
            . ' SUM(fees_deductions) AS fees, SUM(total_sold_price) AS net, SUM(item_cost * quantity) AS cost'
            . ' FROM order_items GROUP BY order_id) i ON i.order_id = o.id'
            . ' WHERE o.order_date >= ? AND o.order_date < ?'
            . ' GROUP BY period',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        );
    }

    /**
     * One report row: the period's labels plus its figures (zero when it had no orders) and profit.
     *
     * @param array<string, mixed> $totals Database row for the period, or [] when it had no orders.
     * @param array{label: string, axis: string, from: string, to: string} $period
     *
     * @return array{label: string, axis: string, from: string, to: string, orders: int, units: int,
     *     sold: float, fees: float, net: float, cost: float, shipping: float, profit: float}
     */
    private function row(array $totals, array $period): array
    {
        $row = $period + [
            'orders' => (int) ($totals['orders'] ?? 0),
            'units' => (int) ($totals['units'] ?? 0),
        ];

        foreach (self::AMOUNTS as $key) {
            $row[$key] = round((float) ($totals[$key] ?? 0), 2);
        }

        $row['profit'] = round($row['net'] - $row['cost'] - $row['shipping'], 2);

        return $row;
    }

    /**
     * A report: its rows, the totals across them, and a net sales chart series.
     *
     * @param list<array<string, mixed>> $rows Rows from row(), oldest first.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>,
     *     chart: array<string, mixed>, from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function report(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $totals = [];

        foreach (['orders', 'units', ...self::AMOUNTS, 'profit'] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        foreach ([...self::AMOUNTS, 'profit'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        $points = array_map(
            static fn (array $row): array => ['label' => $row['label'], 'axis' => $row['axis'], 'value' => $row['net']],
            $rows
        );

        return [
            'rows' => $rows,
            'totals' => $totals,
            'chart' => ChartScale::series($points),
            'from' => $from,
            'to' => $to,
        ];
    }
}
