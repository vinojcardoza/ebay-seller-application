<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Feedback;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Feedback entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Feedback::class)]
final class FeedbackTest extends TestCase
{
    /**
     * The constructor sets the creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $feedback = new Feedback();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $feedback->getCreated());
        $this->assertGreaterThanOrEqual($before, $feedback->getCreated());
        $this->assertLessThanOrEqual($after, $feedback->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $feedback = new Feedback();

        $this->assertNull($feedback->getEbayUserId());
        $this->assertNull($feedback->getCommentText());
        $this->assertNull($feedback->getCommentTime());
        $this->assertNull($feedback->getCommentType());
        $this->assertNull($feedback->getItemId());
        $this->assertNull($feedback->getItemTitle());
        $this->assertNull($feedback->getItemPrice());
        $this->assertNull($feedback->getFeedbackId());
        $this->assertNull($feedback->getTransactionId());
        $this->assertNull($feedback->getOrderLineItemId());
        $this->assertNull($feedback->getUpdated());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'eBay user ID' => ['setEbayUserId', 'getEbayUserId', 'Sample eBay user ID text'],
            'comment text' => ['setCommentText', 'getCommentText', 'Sample comment text text'],
            'comment time' => ['setCommentTime', 'getCommentTime', new DateTime('2026-03-12 10:30:00')],
            'comment type' => ['setCommentType', 'getCommentType', 'Sample comment type'],
            'item ID' => ['setItemId', 'getItemId', 'Sample item ID'],
            'item title' => ['setItemTitle', 'getItemTitle', 'Sample item title text'],
            'item price' => ['setItemPrice', 'getItemPrice', '18.34'],
            'eBay feedback ID' => ['setFeedbackId', 'getFeedbackId', 'Sample eBay feedback ID text'],
            'eBay transaction ID' => ['setTransactionId', 'getTransactionId', 'Sample eBay transaction ID text'],
            'order line item ID' => ['setOrderLineItemId', 'getOrderLineItemId', 'Sample order line item ID text'],
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
        $feedback = new Feedback();

        $this->assertSame($feedback, $feedback->$setter($value));
        $this->assertSame($value, $feedback->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'eBay user ID' => ['setEbayUserId', 'getEbayUserId'],
            'comment text' => ['setCommentText', 'getCommentText'],
            'comment time' => ['setCommentTime', 'getCommentTime'],
            'comment type' => ['setCommentType', 'getCommentType'],
            'item ID' => ['setItemId', 'getItemId'],
            'item title' => ['setItemTitle', 'getItemTitle'],
            'item price' => ['setItemPrice', 'getItemPrice'],
            'eBay feedback ID' => ['setFeedbackId', 'getFeedbackId'],
            'eBay transaction ID' => ['setTransactionId', 'getTransactionId'],
            'order line item ID' => ['setOrderLineItemId', 'getOrderLineItemId'],
            'last update timestamp' => ['setUpdated', 'getUpdated'],
        ];
    }

    /**
     * Each nullable property's setter accepts null.
     */
    #[DataProvider('nullablePropertyProvider')]
    public function testNullableSetterAcceptsNull(string $setter, string $getter): void
    {
        $feedback = new Feedback();
        $feedback->$setter(null);

        $this->assertNull($feedback->$getter());
    }
}
