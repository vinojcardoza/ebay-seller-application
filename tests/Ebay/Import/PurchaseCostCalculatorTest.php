<?php

declare(strict_types=1);

namespace Tests\Ebay\Import;

use App\Ebay\Import\PurchaseCostCalculator;
use App\Entities\Product;
use App\Entities\Purchase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for FIFO unit cost, ported from the legacy getPurchaseCost().
 */
#[CoversClass(PurchaseCostCalculator::class)]
final class PurchaseCostCalculatorTest extends TestCase
{
    /**
     * Units already sold and the expected cost of the next unit, for stock of
     * 10 units at £15.00 (1.50 each) then 5 units at £10.00 (2.00 each).
     *
     * @return array<string, array{int, string}>
     */
    public static function fifoProvider(): array
    {
        return [
            'nothing sold' => [0, '1.50'],
            'first batch almost used' => [9, '1.50'],
            'first batch used up' => [10, '2.00'],
            'all stock sold' => [15, '0.00'],
            'oversold' => [20, '0.00'],
        ];
    }

    /**
     * The cost is that of the oldest batch with unsold stock.
     */
    #[DataProvider('fifoProvider')]
    public function testFifoUnitCost(int $sold, string $expected): void
    {
        $purchases = [$this->purchase(10, '15.00'), $this->purchase(5, '10.00')];

        $this->assertSame($expected, PurchaseCostCalculator::fifoUnitCost($purchases, $sold));
    }

    /**
     * Unit costs are rounded to the penny, empty-quantity batches are skipped, and no purchases cost nothing.
     */
    public function testFifoEdgeCases(): void
    {
        $this->assertSame('0.33', PurchaseCostCalculator::fifoUnitCost([$this->purchase(3, '1.00')], 0));
        $this->assertSame('0.67', PurchaseCostCalculator::fifoUnitCost([$this->purchase(3, '2.00')], 0));
        $this->assertSame(
            '2.00',
            PurchaseCostCalculator::fifoUnitCost([$this->purchase(0, '9.99'), $this->purchase(1, '2.00')], 0)
        );
        $this->assertSame('0.00', PurchaseCostCalculator::fifoUnitCost([], 0));
    }

    /**
     * unitCost() loads delivered purchases oldest first and the units sold for the product.
     */
    public function testUnitCostQueriesDeliveredPurchasesAndSoldUnits(): void
    {
        $product = new Product();
        (new ReflectionProperty($product, 'id'))->setValue($product, 42);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(
                ['product' => $product, 'status' => Purchase::STATUS_DELIVERED],
                ['receivedDate' => 'ASC', 'id' => 'ASC']
            )
            ->willReturn([$this->purchase(10, '15.00'), $this->purchase(5, '10.00')]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('FROM order_items WHERE product = ?'), [42])
            ->willReturn('12');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('getConnection')->willReturn($connection);

        $this->assertSame('2.00', (new PurchaseCostCalculator($em))->unitCost($product));
    }

    /**
     * Build a purchase batch.
     */
    private function purchase(int $quantity, string $totalPrice): Purchase
    {
        return (new Purchase())->setQuantity($quantity)->setTotalPrice($totalPrice);
    }
}
