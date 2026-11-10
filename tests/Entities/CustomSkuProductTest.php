<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\Product;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CustomSkuProduct entity's defaults, accessors and fluent setters.
 */
#[CoversClass(CustomSkuProduct::class)]
final class CustomSkuProductTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $customSkuProduct = new CustomSkuProduct();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $customSkuProduct->getCreated());
        $this->assertGreaterThanOrEqual($before, $customSkuProduct->getCreated());
        $this->assertLessThanOrEqual($after, $customSkuProduct->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $customSkuProduct = new CustomSkuProduct();

        $this->assertNull($customSkuProduct->getCustomSku());
        $this->assertNull($customSkuProduct->getProduct());
        $this->assertSame(1, $customSkuProduct->getQuantity());
        $this->assertNull($customSkuProduct->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'custom SKU' => ['setCustomSku', 'getCustomSku', new CustomSku()],
            'product' => ['setProduct', 'getProduct', new Product()],
            'price' => ['setPrice', 'getPrice', '14.34'],
            'fees' => ['setFees', 'getFees', '15.34'],
            'quantity' => ['setQuantity', 'getQuantity', 4],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-06-15 10:30:00')],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-07-16 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $customSkuProduct = new CustomSkuProduct();

        $this->assertSame($customSkuProduct, $customSkuProduct->$setter($value));
        $this->assertSame($value, $customSkuProduct->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'custom SKU' => ['setCustomSku', 'getCustomSku'],
            'product' => ['setProduct', 'getProduct'],
            'quantity' => ['setQuantity', 'getQuantity'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $customSkuProduct = new CustomSkuProduct();
        $customSkuProduct->$setter(null);

        $this->assertNull($customSkuProduct->$getter());
    }
}
