<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\FulfillmentApi;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\EbayTestCase;

/**
 * Tests for the Sell Fulfillment API wrapper.
 */
#[CoversClass(FulfillmentApi::class)]
final class FulfillmentApiTest extends EbayTestCase
{
    /**
     * API under test.
     */
    private FulfillmentApi $api;

    /**
     * Build the API on the fake stack.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new FulfillmentApi($this->client);
    }

    /**
     * Orders are filtered by creation date in UTC and fetched page by page until the total is reached.
     */
    public function testOrdersCreatedSincePaginates(): void
    {
        $page = static fn (int $from, int $count): array => array_map(
            static fn (int $i): array => ['orderId' => "order-$i"],
            range($from, $from + $count - 1)
        );

        $this->http
            ->queueJson(['orders' => $page(1, 200), 'total' => 250])
            ->queueJson(['orders' => $page(201, 50), 'total' => 250]);

        $orders = $this->api->ordersCreatedSince(new DateTimeImmutable('2026-11-07 09:30:00+01:00'));

        $this->assertCount(250, $orders);
        $this->assertSame('order-250', $orders[249]['orderId']);
        $this->assertSame(
            ['filter' => 'creationdate:[2026-11-07T08:30:00.000Z..]', 'limit' => '200', 'offset' => '0'],
            $this->http->query(0)
        );
        $this->assertSame('200', $this->http->query(1)['offset']);
        $this->assertStringEndsWith('/sell/fulfillment/v1/order', $this->http->lastRequest()->getUri()->getPath());
    }

    /**
     * An empty result makes a single call.
     */
    public function testOrdersCreatedSinceWithNoOrders(): void
    {
        $this->http->queueJson(['total' => 0]);

        $this->assertSame([], $this->api->ordersCreatedSince(new DateTimeImmutable()));
        $this->assertCount(1, $this->http->requests);
    }

    /**
     * A single order is fetched by ID, with the ID URL-encoded.
     */
    public function testOrder(): void
    {
        $this->http->queueJson(['orderId' => '12-34567-89012']);

        $this->assertSame(['orderId' => '12-34567-89012'], $this->api->order('12-34567-89012'));
        $this->assertSame('/sell/fulfillment/v1/order/12-34567-89012', $this->http->lastRequest()->getUri()->getPath());

        $this->http->queueJson([]);
        $this->api->order('a/b');
        $this->assertSame('/sell/fulfillment/v1/order/a%2Fb', $this->http->lastRequest()->getUri()->getPath());
    }

    /**
     * Shipping fulfillments are returned as a list, ignoring malformed entries.
     */
    public function testShippingFulfillments(): void
    {
        $this->http->queueJson(['fulfillments' => [['shipmentTrackingNumber' => 'AB123'], 'junk'], 'total' => 1]);

        $this->assertSame([['shipmentTrackingNumber' => 'AB123']], $this->api->shippingFulfillments('12-1'));
        $this->assertSame(
            '/sell/fulfillment/v1/order/12-1/shipping_fulfillment',
            $this->http->lastRequest()->getUri()->getPath()
        );
    }
}
