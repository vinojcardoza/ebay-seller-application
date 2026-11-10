<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Product;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Product entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsCreatedToNow(): void
    {
        $before = new DateTime();
        $product = new Product();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $product->getCreated());
        $this->assertGreaterThanOrEqual($before, $product->getCreated());
        $this->assertLessThanOrEqual($after, $product->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $product = new Product();

        $this->assertNull($product->getCategory());
        $this->assertSame(10, $product->getLowStock());
        $this->assertNull($product->getShippingService());
        $this->assertSame('7.50', $product->getPromoPercentage());
        $this->assertSame(2, $product->getMultiBuy2());
        $this->assertSame(5, $product->getMultiBuy3());
        $this->assertSame(10, $product->getMultiBuy4());
        $this->assertNull($product->getImage());
        $this->assertNull($product->getUpdated());
        $this->assertSame(1, $product->getStatus());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'category' => ['setCategory', 'getCategory', 3],
            'sku' => ['setSku', 'getSku', 'SKU-001'],
            'barcode upc' => ['setBarcodeUpc', 'getBarcodeUpc', '012345678905'],
            'code' => ['setCode', 'getCode', 'C-100'],
            'title' => ['setTitle', 'getTitle', 'Test Product'],
            'low stock' => ['setLowStock', 'getLowStock', 25],
            'price' => ['setPrice', 'getPrice', '19.99'],
            'fees' => ['setFees', 'getFees', '2.45'],
            'shipping service' => ['setShippingService', 'getShippingService', 'Royal Mail 2nd Class'],
            'package cost' => ['setPackageCost', 'getPackageCost', '0.35'],
            'postage cost' => ['setPostageCost', 'getPostageCost', '1.55'],
            'promo percentage' => ['setPromoPercentage', 'getPromoPercentage', '12.00'],
            'multi buy 2' => ['setMultiBuy2', 'getMultiBuy2', 3],
            'multi buy 3' => ['setMultiBuy3', 'getMultiBuy3', 6],
            'multi buy 4' => ['setMultiBuy4', 'getMultiBuy4', 12],
            'image' => ['setImage', 'getImage', 'images/product.jpg'],
            'created' => ['setCreated', 'getCreated', new DateTime('2026-01-01 10:00:00')],
            'updated' => ['setUpdated', 'getUpdated', new DateTime('2026-02-01 12:30:00')],
            'status' => ['setStatus', 'getStatus', 0],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $product = new Product();

        $this->assertSame($product, $product->$setter($value));
        $this->assertSame($value, $product->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'category' => ['setCategory', 'getCategory'],
            'low stock' => ['setLowStock', 'getLowStock'],
            'shipping service' => ['setShippingService', 'getShippingService'],
            'multi buy 2' => ['setMultiBuy2', 'getMultiBuy2'],
            'multi buy 3' => ['setMultiBuy3', 'getMultiBuy3'],
            'multi buy 4' => ['setMultiBuy4', 'getMultiBuy4'],
            'image' => ['setImage', 'getImage'],
            'updated' => ['setUpdated', 'getUpdated'],
            'status' => ['setStatus', 'getStatus'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $product = new Product();
        $product->$setter(null);

        $this->assertNull($product->$getter());
    }
}
