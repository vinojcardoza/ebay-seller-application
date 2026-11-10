<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Product;
use App\Entities\Purchase;
use App\Entities\Supplier;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Purchase entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Purchase::class)]
final class PurchaseTest extends TestCase
{
    /**
     * The constructor sets the purchase date and creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $purchase = new Purchase();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $purchase->getPurchasedDate());
        $this->assertGreaterThanOrEqual($before, $purchase->getPurchasedDate());
        $this->assertLessThanOrEqual($after, $purchase->getPurchasedDate());

        $this->assertInstanceOf(DateTimeInterface::class, $purchase->getCreated());
        $this->assertGreaterThanOrEqual($before, $purchase->getCreated());
        $this->assertLessThanOrEqual($after, $purchase->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $purchase = new Purchase();

        $this->assertNull($purchase->getSupplier());
        $this->assertNull($purchase->getProduct());
        $this->assertNull($purchase->getReceivedDate());
        $this->assertNull($purchase->getUpdated());
    }

    /**
     * Status constants match the values the legacy app stores.
     */
    public function testStatusConstants(): void
    {
        $this->assertSame(0, Purchase::STATUS_PENDING);
        $this->assertSame(1, Purchase::STATUS_DELIVERED);
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'supplier' => ['setSupplier', 'getSupplier', new Supplier()],
            'product' => ['setProduct', 'getProduct', new Product()],
            'purchase date' => ['setPurchasedDate', 'getPurchasedDate', new DateTime('2026-03-12 10:30:00')],
            'quantity' => ['setQuantity', 'getQuantity', 10],
            'total price' => ['setTotalPrice', 'getTotalPrice', '16.34'],
            'status' => ['setStatus', 'getStatus', 12],
            'received date' => ['setReceivedDate', 'getReceivedDate', new DateTime('2026-07-16 10:30:00')],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-08-17 10:30:00')],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-09-18 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $purchase = new Purchase();

        $this->assertSame($purchase, $purchase->$setter($value));
        $this->assertSame($value, $purchase->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'supplier' => ['setSupplier', 'getSupplier'],
            'product' => ['setProduct', 'getProduct'],
            'received date' => ['setReceivedDate', 'getReceivedDate'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $purchase = new Purchase();
        $purchase->$setter(null);

        $this->assertNull($purchase->$getter());
    }
}
