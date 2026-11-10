<?php

declare(strict_types=1);

namespace Tests\Entities;

use App\Entities\Order;
use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Order entity's defaults, accessors and fluent setters.
 */
#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    /**
     * The constructor sets the order date and creation timestamp to the current time.
     */
    public function testConstructorSetsTimestampsToNow(): void
    {
        $before = new DateTime();
        $order = new Order();
        $after = new DateTime();

        $this->assertInstanceOf(DateTimeInterface::class, $order->getOrderDate());
        $this->assertGreaterThanOrEqual($before, $order->getOrderDate());
        $this->assertLessThanOrEqual($after, $order->getOrderDate());

        $this->assertInstanceOf(DateTimeInterface::class, $order->getCreated());
        $this->assertGreaterThanOrEqual($before, $order->getCreated());
        $this->assertLessThanOrEqual($after, $order->getCreated());
    }

    /**
     * A new entity starts with the same values as the table's column defaults.
     */
    public function testDefaultsMatchDatabaseDefaults(): void
    {
        $order = new Order();

        $this->assertSame('Purchase', $order->getOrderType());
        $this->assertNull($order->getShippingCompany());
        $this->assertNull($order->getShippingService());
        $this->assertNull($order->getTrackingCode());
        $this->assertSame(0, $order->getShippingStatus());
        $this->assertNull($order->getShippingDatetime());
        $this->assertNull($order->getShippingDeliveryDatetime());
        $this->assertNull($order->getEbayUserId());
        $this->assertNull($order->getFullName());
        $this->assertNull($order->getEmail());
        $this->assertNull($order->getPhone());
        $this->assertNull($order->getStreet1());
        $this->assertNull($order->getStreet2());
        $this->assertNull($order->getCity());
        $this->assertNull($order->getState());
        $this->assertNull($order->getPostcode());
        $this->assertNull($order->getCountry());
        $this->assertNull($order->getCountryName());
        $this->assertSame(1, $order->getStatus());
        $this->assertNull($order->getAdditionalNotes());
        $this->assertNull($order->getUpdated());
    }

    /**
     * Related-record collections start empty.
     */
    public function testCollectionsStartEmpty(): void
    {
        $order = new Order();

        $this->assertCount(0, $order->getItems());
        $this->assertCount(0, $order->getTransactions());
    }

    /**
     * Provides setter name, getter name and a sample value for each property.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function propertyProvider(): array
    {
        return [
            'platform' => ['setPlatform', 'getPlatform', 'Sample platform'],
            'order type' => ['setOrderType', 'getOrderType', 'Sample order type'],
            'order reference' => ['setOrderReference', 'getOrderReference', 'Sample order reference'],
            'order date' => ['setOrderDate', 'getOrderDate', new DateTime('2026-04-13 10:30:00')],
            'packaging cost' => ['setPackagingCost', 'getPackagingCost', '16.34'],
            'shipping company' => ['setShippingCompany', 'getShippingCompany', 'Sample shipping company'],
            'shipping service' => ['setShippingService', 'getShippingService', 'Sample shipping service'],
            'tracking code' => ['setTrackingCode', 'getTrackingCode', 'Sample tracking code'],
            'shipping status' => ['setShippingStatus', 'getShippingStatus', 3],
            'shipping date' => ['setShippingDatetime', 'getShippingDatetime', new DateTime('2026-01-10 10:30:00')],
            'delivery date' => [
                'setShippingDeliveryDatetime',
                'getShippingDeliveryDatetime',
                new DateTime('2026-02-11 10:30:00'),
            ],
            'postage cost' => ['setPostageCost', 'getPostageCost', '23.34'],
            'eBay user ID' => ['setEbayUserId', 'getEbayUserId', 'Sample eBay user ID text'],
            'full name' => ['setFullName', 'getFullName', 'Sample full name text'],
            'email' => ['setEmail', 'getEmail', 'Sample email text'],
            'phone' => ['setPhone', 'getPhone', 'Sample phone text'],
            'street line 1' => ['setStreet1', 'getStreet1', 'Sample street line 1 text'],
            'street line 2' => ['setStreet2', 'getStreet2', 'Sample street line 2 text'],
            'city' => ['setCity', 'getCity', 'Sample city text'],
            'state' => ['setState', 'getState', 'Sample state text'],
            'postcode' => ['setPostcode', 'getPostcode', 'Sample postcode text'],
            'country code' => ['setCountry', 'getCountry', 'Sample country code text'],
            'country name' => ['setCountryName', 'getCountryName', 'Sample country name text'],
            'status' => ['setStatus', 'getStatus', 4],
            'creation timestamp' => ['setCreated', 'getCreated', new DateTime('2026-07-16 10:30:00')],
            'additional notes' => ['setAdditionalNotes', 'getAdditionalNotes', 'Sample additional notes text'],
            'last update timestamp' => ['setUpdated', 'getUpdated', new DateTime('2026-09-18 10:30:00')],
        ];
    }

    /**
     * Each setter stores the given value and returns the same instance.
     */
    #[DataProvider('propertyProvider')]
    public function testSetterStoresValueAndIsFluent(string $setter, string $getter, mixed $value): void
    {
        $order = new Order();

        $this->assertSame($order, $order->$setter($value));
        $this->assertSame($value, $order->$getter());
    }

    /**
     * Provides setter and getter names for each nullable property.
     *
     * @return array<string, array{string, string}>
     */
    public static function nullablePropertyProvider(): array
    {
        return [
            'shipping company' => ['setShippingCompany', 'getShippingCompany'],
            'shipping service' => ['setShippingService', 'getShippingService'],
            'tracking code' => ['setTrackingCode', 'getTrackingCode'],
            'shipping status' => ['setShippingStatus', 'getShippingStatus'],
            'shipping date' => ['setShippingDatetime', 'getShippingDatetime'],
            'delivery date' => ['setShippingDeliveryDatetime', 'getShippingDeliveryDatetime'],
            'eBay user ID' => ['setEbayUserId', 'getEbayUserId'],
            'full name' => ['setFullName', 'getFullName'],
            'email' => ['setEmail', 'getEmail'],
            'phone' => ['setPhone', 'getPhone'],
            'street line 1' => ['setStreet1', 'getStreet1'],
            'street line 2' => ['setStreet2', 'getStreet2'],
            'city' => ['setCity', 'getCity'],
            'state' => ['setState', 'getState'],
            'postcode' => ['setPostcode', 'getPostcode'],
            'country code' => ['setCountry', 'getCountry'],
            'country name' => ['setCountryName', 'getCountryName'],
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
        $order = new Order();
        $order->$setter(null);

        $this->assertNull($order->$getter());
    }
}
