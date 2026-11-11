<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entities\Product;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stock per product, as the legacy app calculated it: delivered purchases
 * minus units sold, plus units ordered from suppliers but not yet delivered.
 *
 * A product is "out" at zero or less, "low" at or below its low stock
 * threshold (`products.low_stock`; none means only "out" applies), else "in".
 */
class StockLevels
{
    /**
     * Create the service.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Stock for every product.
     *
     * @return array<int, array{stock: int, pending: int, state: string}> Keyed by product ID.
     */
    public function all(): array
    {
        $levels = [];

        foreach ($this->rows() as $row) {
            $levels[(int) $row['id']] = $this->level($row);
        }

        return $levels;
    }

    /**
     * Active products that are low or out of stock, lowest stock first (then by title).
     *
     * @return array{total: int, out: int, products: list<array{id: int, sku: string, title: string, stock: int,
     *     threshold: int|null, pending: int, state: string}>} `total` and `out` count every such product;
     *     `products` holds at most $limit of them.
     */
    public function lowStock(int $limit): array
    {
        $products = [];

        foreach ($this->rows() as $row) {
            $level = $this->level($row);

            if ((int) ($row['status'] ?? 0) !== Product::STATUS_ACTIVE || $level['state'] === 'in') {
                continue;
            }

            $products[] = [
                'id' => (int) $row['id'],
                'sku' => (string) ($row['sku'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'stock' => $level['stock'],
                'threshold' => $row['low_stock'] !== null ? (int) $row['low_stock'] : null,
                'pending' => $level['pending'],
                'state' => $level['state'],
            ];
        }

        usort(
            $products,
            static fn (array $a, array $b): int => [$a['stock'], $a['title']] <=> [$b['stock'], $b['title']]
        );

        return [
            'total' => count($products),
            'out' => count(array_filter($products, static fn (array $p): bool => $p['state'] === 'out')),
            'products' => array_slice($products, 0, max(0, $limit)),
        ];
    }

    /**
     * Stock, pending units and state for a query row.
     *
     * @param array<string, mixed> $row
     *
     * @return array{stock: int, pending: int, state: string}
     */
    private function level(array $row): array
    {
        $stock = (int) $row['delivered'] - (int) $row['sold'];

        return [
            'stock' => $stock,
            'pending' => (int) $row['pending'],
            'state' => $stock <= 0 ? 'out' : ($stock <= (int) ($row['low_stock'] ?? 0) ? 'low' : 'in'),
        ];
    }

    /**
     * One row per product with its purchase and sales quantities.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.sku, p.title, p.status, p.low_stock,'
            . ' COALESCE(delivered.qty, 0) AS delivered, COALESCE(pending.qty, 0) AS pending,'
            . ' COALESCE(sold.qty, 0) AS sold'
            . ' FROM products p'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM purchases WHERE status = 1 GROUP BY product)'
            . ' delivered ON delivered.product = p.id'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM purchases WHERE status = 0 GROUP BY product)'
            . ' pending ON pending.product = p.id'
            . ' LEFT JOIN (SELECT product, SUM(quantity) AS qty FROM order_items GROUP BY product)'
            . ' sold ON sold.product = p.id'
        );
    }
}
