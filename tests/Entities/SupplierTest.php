<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Supplier;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Supplier entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Supplier::class)]
final class SupplierTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $supplier = new Supplier();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $supplier->getCreated());
        $this->assertGreaterThanOrEqual($before, $supplier->getCreated());
        $this->assertLessThanOrEqual($after, $supplier->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $supplier = new Supplier();

        $this->assertNull($supplier->getUpdated());
    }

    /**
     * Related-record collections start empty.
     */
    public function testCollectionsStartEmpty(): void
    {
        $supplier = new Supplier();

        $this->assertCount(0, $supplier->getPurchases());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'supplier name' => ['setSupplierName', 'getSupplierName', 'Sample supplier name'],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-02-11 10:30:00')],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-03-12 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $supplier = new Supplier();

        $this->assertSame($supplier, $supplier->$setter($value));
        $this->assertSame($value, $supplier->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $supplier = new Supplier();
        $supplier->$setter(null);

        $this->assertNull($supplier->$getter());
    }
}
