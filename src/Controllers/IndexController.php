<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Controller\ControllerAbstract;
use Psr\Http\Message\ResponseInterface;

/**
 * Dashboard with headline business figures and the latest orders.
 */
class IndexController extends ControllerAbstract
{
    /**
     * Number of recent orders shown on the dashboard.
     */
    private const RECENT_ORDER_LIMIT = 10;

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
            'orderTypes' => $orderTypes,
            'recentOrders' => $recentOrders,
        ]);
    }
}
