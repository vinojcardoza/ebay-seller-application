<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Dashboard with headline business figures, the current month's sales
 * summary and the latest orders.
 *
 * Month figures follow the orders page: net = sold price − fees, and
 * profit = net − item cost × quantity − postage − packaging.
 */
class IndexController extends ControllerAbstract
{
    /**
     * Number of recent orders shown on the dashboard.
     */
    private const RECENT_ORDER_LIMIT = 10;

    /**
     * Receive the shared controller services and the clock that decides the current month.
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
     * Show the dashboard.
     */
    public function index(): ResponseInterface
    {
        $db = $this->em->getConnection();

        $sales = $db->fetchAssociative(
            'SELECT COALESCE(SUM(total_sold_price), 0) AS revenue, COALESCE(SUM(quantity), 0) AS items
             FROM order_items'
        ) ?: ['revenue' => 0, 'items' => 0];

        $stats = [
            'orders' => (int) $db->fetchOne('SELECT COUNT(*) FROM orders'),
            'revenue' => (float) $sales['revenue'],
            'items' => (int) $sales['items'],
            'products' => (int) $db->fetchOne('SELECT COUNT(*) FROM products WHERE status = 1'),
        ];

        $orderTypes = $db->fetchAllAssociative(
            'SELECT order_type, COUNT(*) AS total FROM orders GROUP BY order_type ORDER BY total DESC'
        );

        $recentOrders = $db->fetchAllAssociative(
            'SELECT o.id, o.order_reference, o.order_date, o.platform, o.order_type, o.full_name,
                    (SELECT SUM(i.total_sold_price) FROM order_items i WHERE i.order_id = o.id) AS total
             FROM orders o
             ORDER BY o.order_date DESC
             LIMIT ' . self::RECENT_ORDER_LIMIT
        );

        return $this->render('index/index', [
            'stats' => $stats,
            'month' => $this->monthSummary(),
            'orderTypes' => $orderTypes,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * Sales figures for the current calendar month, compared with the same
     * stretch of the previous month (its first day up to the same point in time).
     *
     * @return array{label: string, from: string, to: string, current: array<string, float|int>,
     *     previous: array<string, float|int>}
     */
    private function monthSummary(): array
    {
        $now = $this->clock->now();
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $next = $start->modify('+1 month');
        $previousStart = $start->modify('-1 month');
        $previousEnd = min($previousStart->add($start->diff($now)), $start);

        return [
            'label' => $start->format('F Y'),
            'from' => $start->format('Y-m-d'),
            'to' => $next->modify('-1 day')->format('Y-m-d'),
            'current' => $this->periodTotals($start, $next),
            'previous' => $this->periodTotals($previousStart, $previousEnd),
        ];
    }

    /**
     * Order and item totals for orders placed in [$from, $to).
     *
     * @return array{orders: int, units: int, sold: float, fees: float, net: float, cost: float,
     *     shipping: float, profit: float}
     */
    private function periodTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(i.units), 0) AS units, COALESCE(SUM(i.sold), 0) AS sold,'
            . ' COALESCE(SUM(i.fees), 0) AS fees, COALESCE(SUM(i.net), 0) AS net,'
            . ' COALESCE(SUM(i.cost), 0) AS cost,'
            . ' COALESCE(SUM(o.postage_cost), 0) + COALESCE(SUM(o.packaging_cost), 0) AS shipping'
            . ' FROM orders o LEFT JOIN (SELECT order_id, SUM(quantity) AS units, SUM(sold_price) AS sold,'
            . ' SUM(fees_deductions) AS fees, SUM(total_sold_price) AS net, SUM(item_cost * quantity) AS cost'
            . ' FROM order_items GROUP BY order_id) i ON i.order_id = o.id'
            . ' WHERE o.order_date >= ? AND o.order_date < ?',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        ) ?: [];

        $totals = [
            'orders' => (int) ($row['orders'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
        ];

        foreach (['sold', 'fees', 'net', 'cost', 'shipping'] as $key) {
            $totals[$key] = round((float) ($row[$key] ?? 0), 2);
        }

        $totals['profit'] = round($totals['net'] - $totals['cost'] - $totals['shipping'], 2);

        return $totals;
    }
}
