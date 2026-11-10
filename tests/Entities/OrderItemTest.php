<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Order;
use App\Entities\OrderItem;
use App\Entities\Product;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OrderItem entity's defaults, accessors and fluent setters.
 */
#[CoversClass(OrderItem::class)]
final class OrderItemTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $orderItem = new OrderItem();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $orderItem->getCreated());
        $this->assertGreaterThanOrEqual($before, $orderItem->getCreated());
        $this->assertLessThanOrEqual($after, $orderItem->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $orderItem = new OrderItem();

        $this->assertNull($orderItem->getProduct());
        $this->assertNull($orderItem->getOrder());
        $this->assertNull($orderItem->getItemCost());
        $this->assertNull($orderItem->getQuantity());
        $this->assertNull($orderItem->getSoldPrice());
        $this->assertNull($orderItem->getFeesDeductions());
        $this->assertNull($orderItem->getTotalSoldPrice());
        $this->assertNull($orderItem->getAdditionalNotes());
        $this->assertNull($orderItem->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'product' => ['setProduct', 'getProduct', new Product()],
            'order' => ['setOrder', 'getOrder', new Order()],
            'item cost' => ['setItemCost', 'getItemCost', '14.34'],
            'quantity' => ['setQuantity', 'getQuantity', 10],
            'sold price' => ['setSoldPrice', 'getSoldPrice', '16.34'],
            'fees deductions' => ['setFeesDeductions', 'getFeesDeductions', '17.34'],
            'total sold price' => ['setTotalSoldPrice', 'getTotalSoldPrice', '18.34'],
            'additional notes' => ['setAdditionalNotes', 'getAdditionalNotes', 'Sample additional notes text'],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-09-18 10:30:00')],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-01-10 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $orderItem = new OrderItem();

        $this->assertSame($orderItem, $orderItem->$setter($value));
        $this->assertSame($value, $orderItem->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'product' => ['setProduct', 'getProduct'],
            'order' => ['setOrder', 'getOrder'],
            'item cost' => ['setItemCost', 'getItemCost'],
            'quantity' => ['setQuantity', 'getQuantity'],
            'sold price' => ['setSoldPrice', 'getSoldPrice'],
            'fees deductions' => ['setFeesDeductions', 'getFeesDeductions'],
            'total sold price' => ['setTotalSoldPrice', 'getTotalSoldPrice'],
            'additional notes' => ['setAdditionalNotes', 'getAdditionalNotes'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $orderItem = new OrderItem();
        $orderItem->$setter(null);

        $this->assertNull($orderItem->$getter());
    }
}
