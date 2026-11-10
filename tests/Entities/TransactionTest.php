<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Order;
use App\Entities\Transaction;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Transaction entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Transaction::class)]
final class TransactionTest extends TestCase
{
    /**
     * The constructor sets the transaction date and creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $transaction = new Transaction();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $transaction->getTransactionDatetime());
        $this->assertGreaterThanOrEqual($before, $transaction->getTransactionDatetime());
        $this->assertLessThanOrEqual($after, $transaction->getTransactionDatetime());

        $this->assertInstanceOf(DateTimeInterface::class, $transaction->getCreated());
        $this->assertGreaterThanOrEqual($before, $transaction->getCreated());
        $this->assertLessThanOrEqual($after, $transaction->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $transaction = new Transaction();

        $this->assertNull($transaction->getOrder());
        $this->assertNull($transaction->getTransactionTitle());
        $this->assertNull($transaction->getTransactionId());
        $this->assertNull($transaction->getNotes());
        $this->assertNull($transaction->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'order' => ['setOrder', 'getOrder', new Order()],
            'transaction date' => [
                'setTransactionDatetime',
                'getTransactionDatetime',
                new DateTime('2026-02-11 10:30:00'),
            ],
            'transaction title' => ['setTransactionTitle', 'getTransactionTitle', 'Sample transaction title'],
            'external transaction ID' => ['setTransactionId', 'getTransactionId', 'Sample external transaction ID'],
            'expense' => ['setExpense', 'getExpense', '16.34'],
            'income' => ['setIncome', 'getIncome', '17.34'],
            'notes' => ['setNotes', 'getNotes', 'Sample notes text'],
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
        $transaction = new Transaction();

        $this->assertSame($transaction, $transaction->$setter($value));
        $this->assertSame($value, $transaction->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'order' => ['setOrder', 'getOrder'],
            'transaction title' => ['setTransactionTitle', 'getTransactionTitle'],
            'external transaction ID' => ['setTransactionId', 'getTransactionId'],
            'notes' => ['setNotes', 'getNotes'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $transaction = new Transaction();
        $transaction->$setter(null);

        $this->assertNull($transaction->$getter());
    }
}
