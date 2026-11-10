<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Category;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Category entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsCreatedToNow(): void
    {
        $before = new DateTime();
        $category = new Category();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $category->getCreated());
        $this->assertGreaterThanOrEqual($before, $category->getCreated());
        $this->assertLessThanOrEqual($after, $category->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $category = new Category();

        $this->assertSame(0, $category->getParentId());
        $this->assertSame(0, $category->getMenuOrder());
        $this->assertNull($category->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'title' => ['setTitle', 'getTitle', 'Electronics'],
            'parent id' => ['setParentId', 'getParentId', 4],
            'menu order' => ['setMenuOrder', 'getMenuOrder', 7],
            'created' => ['setCreated', 'getCreated', new DateTime('2026-01-01 10:00:00')],
            'updated' => ['setUpdated', 'getUpdated', new DateTime('2026-02-01 12:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $category = new Category();

        $this->assertSame($category, $category->$setter($value));
        $this->assertSame($value, $category->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'parent id' => ['setParentId', 'getParentId'],
            'menu order' => ['setMenuOrder', 'getMenuOrder'],
            'updated' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $category = new Category();
        $category->$setter(null);

        $this->assertNull($category->$getter());
    }
}
