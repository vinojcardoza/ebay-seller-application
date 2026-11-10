<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\User;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the User entity's defaults, accessors and fluent setters.
 */
#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $user = new User();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $user->getCreated());
        $this->assertGreaterThanOrEqual($before, $user->getCreated());
        $this->assertLessThanOrEqual($after, $user->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $user = new User();

        $this->assertSame(50, $user->getRole());
        $this->assertNull($user->getFullName());
        $this->assertNull($user->getLastLogin());
        $this->assertNull($user->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'role' => ['setRole', 'getRole', 53],
            'hash' => ['setHash', 'getHash', 'Sample hash'],
            'email' => ['setEmail', 'getEmail', 'jane@example.com'],
            'login' => ['setLogin', 'getLogin', 'Sample login'],
            'password hash' => ['setPassword', 'getPassword', 'Sample password hash'],
            'full name' => ['setFullName', 'getFullName', 'Sample full name'],
            'last login time' => ['setLastLogin', 'getLastLogin', new DateTime('2026-07-16 10:30:00')],
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
        $user = new User();

        $this->assertSame($user, $user->$setter($value));
        $this->assertSame($value, $user->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'full name' => ['setFullName', 'getFullName'],
            'last login time' => ['setLastLogin', 'getLastLogin'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $user = new User();
        $user->$setter(null);

        $this->assertNull($user->$getter());
    }
}
