<?php

declare(strict_types=1);

namespace Tests\Catalog;

use App\Catalog\StockLevels;
use App\Entities\Product;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for stock levels and the low stock list.
 */
#[CoversClass(StockLevels::class)]
final class StockLevelsTest extends TestCase
{
    /**
     * Rows the stock query returns.
     *
     * @var list<array<string, mixed>>
     */
    private array $rows = [];

    /**
     * SQL of the last query run.
     */
    private string $sql = '';

    /**
     * Seed products in every state: 1 in stock, 2 low, 3 at its threshold, 4 out,
     * 5 oversold, 6 without a threshold (only "out" applies), 7 inactive and out.
     */
    protected function setUp(): void
    {
        $this->rows = [
            $this->row(1, 'Zener Diode', 20, 5, 0, 10),
            $this->row(2, 'Heatsink TO-220', 12, 9, 0, 5),
            $this->row(3, 'Racket Grip', 10, 5, 4, 5),
            $this->row(4, 'LCD Display', 8, 8, 20, 10),
            $this->row(5, 'Buzzer', 3, 5, 0, 2),
            $this->row(6, 'Capacitor', 2, 1, 0, null),
            $this->row(7, 'Old Relay', 1, 1, 0, 10, Product::STATUS_INACTIVE),
        ];
    }

    /**
     * Every product gets its stock (delivered − sold), units on order and state.
     */
    public function testAll(): void
    {
        $levels = $this->levels()->all();

        $this->assertSame(['stock' => 15, 'pending' => 0, 'state' => 'in'], $levels[1]);
        $this->assertSame(['stock' => 3, 'pending' => 0, 'state' => 'low'], $levels[2]);
        $this->assertSame(['stock' => 5, 'pending' => 4, 'state' => 'low'], $levels[3], 'At the threshold is low.');
        $this->assertSame(['stock' => 0, 'pending' => 20, 'state' => 'out'], $levels[4]);
        $this->assertSame(['stock' => -2, 'pending' => 0, 'state' => 'out'], $levels[5]);
        $this->assertSame(
            ['stock' => 1, 'pending' => 0, 'state' => 'in'],
            $levels[6],
            'No threshold: only out applies.'
        );
        $this->assertSame('out', $levels[7]['state']);
        $this->assertStringContainsString('FROM purchases WHERE status = 1', $this->sql);
        $this->assertStringContainsString('FROM purchases WHERE status = 0', $this->sql);
        $this->assertStringContainsString('FROM order_items', $this->sql);
    }

    /**
     * The low stock list has active low and out products only, lowest stock first, with counts.
     */
    public function testLowStock(): void
    {
        $low = $this->levels()->lowStock(10);

        $this->assertSame(4, $low['total']);
        $this->assertSame(2, $low['out']);
        $this->assertSame([5, 4, 2, 3], array_column($low['products'], 'id'));
        $this->assertSame([
            'id' => 3,
            'sku' => 'SKU-3',
            'title' => 'Racket Grip',
            'stock' => 5,
            'threshold' => 5,
            'pending' => 4,
            'state' => 'low',
        ], $low['products'][3]);
    }

    /**
     * Products with the same stock are ordered by title.
     */
    public function testLowStockTiesSortByTitle(): void
    {
        $this->rows = [
            $this->row(1, 'Bravo', 0, 0, 0, 5),
            $this->row(2, 'Alpha', 0, 0, 0, 5),
        ];

        $this->assertSame([2, 1], array_column($this->levels()->lowStock(10)['products'], 'id'));
    }

    /**
     * The limit caps the list but not the counts.
     */
    public function testLowStockLimit(): void
    {
        $low = $this->levels()->lowStock(2);

        $this->assertSame(4, $low['total']);
        $this->assertSame(2, $low['out']);
        $this->assertSame([5, 4], array_column($low['products'], 'id'));
        $this->assertSame([], $this->levels()->lowStock(0)['products']);
    }

    /**
     * Without low stock products the list is empty.
     */
    public function testNoLowStock(): void
    {
        $this->rows = [$this->row(1, 'Zener Diode', 20, 5, 0, 10)];

        $this->assertSame(['total' => 0, 'out' => 0, 'products' => []], $this->levels()->lowStock(10));
    }

    /**
     * A stock query row.
     *
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        string $title,
        int $delivered,
        int $sold,
        int $pending,
        ?int $lowStock,
        int $status = Product::STATUS_ACTIVE,
    ): array {
        return [
            'id' => (string) $id,
            'sku' => 'SKU-' . $id,
            'title' => $title,
            'status' => (string) $status,
            'low_stock' => $lowStock !== null ? (string) $lowStock : null,
            'delivered' => (string) $delivered,
            'pending' => (string) $pending,
            'sold' => (string) $sold,
        ];
    }

    /**
     * The service over a fake connection returning the seeded rows.
     */
    private function levels(): StockLevels
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            $this->sql = $sql;

            return $this->rows;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new StockLevels($em);
    }
}
