<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Category;
use App\Entities\CustomSku;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CustomSku entity's defaults, accessors and fluent setters.
 */
#[CoversClass(CustomSku::class)]
final class CustomSkuTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $customSku = new CustomSku();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $customSku->getCreated());
        $this->assertGreaterThanOrEqual($before, $customSku->getCreated());
        $this->assertLessThanOrEqual($after, $customSku->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $customSku = new CustomSku();

        $this->assertNull($customSku->getCategory());
        $this->assertNull($customSku->getShippingService());
        $this->assertSame(1, $customSku->getStatus());
        $this->assertNull($customSku->getUpdated());
    }

    /**
     * Related-record collections start empty.
     */
    public function testCollectionsStartEmpty(): void
    {
        $customSku = new CustomSku();

        $this->assertCount(0, $customSku->getCustomSkuProducts());
    }

    /**
     * Status constants match the values the legacy app stores.
     */
    public function testStatusConstants(): void
    {
        $this->assertSame(1, CustomSku::STATUS_ACTIVE);
        $this->assertSame(2, CustomSku::STATUS_INACTIVE);
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'category' => ['setCategory', 'getCategory', new Category()],
            'SKU' => ['setSku', 'getSku', 'Sample SKU'],
            'shipping service' => ['setShippingService', 'getShippingService', 'Sample shipping service'],
            'postage cost' => ['setPostageCost', 'getPostageCost', '15.34'],
            'packaging cost' => ['setPackageCost', 'getPackageCost', '16.34'],
            'status' => ['setStatus', 'getStatus', 4],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-07-16 10:30:00')],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-08-17 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $customSku = new CustomSku();

        $this->assertSame($customSku, $customSku->$setter($value));
        $this->assertSame($value, $customSku->$getter());
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
            'shipping service' => ['setShippingService', 'getShippingService'],
            'status' => ['setStatus', 'getStatus'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $customSku = new CustomSku();
        $customSku->$setter(null);

        $this->assertNull($customSku->$getter());
    }
}
