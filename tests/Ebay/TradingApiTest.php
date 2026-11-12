<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\TradingApi;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\EbayTestCase;

/**
 * Tests for the Trading API wrapper (delivery times from GetOrders).
 */
#[CoversClass(TradingApi::class)]
final class TradingApiTest extends EbayTestCase
{
    /**
     * API under test.
     */
    private TradingApi $api;

    /**
     * Build the API on the fake stack.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new TradingApi($this->client);
    }

    /**
     * Delivered orders map to their latest delivery time; undelivered orders are left out.
     */
    public function testDeliveryTimes(): void
    {
        $this->queueOrders(
            '<Order><OrderID>12-1</OrderID>'
            . '<ShippingServiceSelected><ShippingPackageInfo>'
            . '<ActualDeliveryTime>2026-11-09T10:00:00.000Z</ActualDeliveryTime>'
            . '</ShippingPackageInfo></ShippingServiceSelected>'
            . '<TransactionArray><Transaction><ShippingServiceSelected><ShippingPackageInfo>'
            . '<ActualDeliveryTime>2026-11-10T08:15:00.000Z</ActualDeliveryTime>'
            . '</ShippingPackageInfo></ShippingServiceSelected></Transaction></TransactionArray></Order>'
            . '<Order><OrderID>12-2</OrderID><ShippingServiceSelected><ShippingPackageInfo>'
            . '<EstimatedDeliveryTimeMax>2026-11-12T00:00:00.000Z</EstimatedDeliveryTimeMax>'
            . '</ShippingPackageInfo></ShippingServiceSelected></Order>'
        );

        $times = $this->api->deliveryTimes(['12-1', '12-2']);

        $this->assertSame(['12-1'], array_keys($times));
        $this->assertSame('2026-11-10T08:15:00+00:00', $times['12-1']->format(DATE_ATOM));
    }

    /**
     * Order IDs are sent escaped in one GetOrders call as the seller.
     */
    public function testRequestBody(): void
    {
        $this->queueOrders('');

        $this->assertSame([], $this->api->deliveryTimes(['12-1', '12-1', 'a&b']));

        $body = (string) $this->http->lastRequest()->getBody();
        $this->assertCount(1, $this->http->requests);
        $this->assertSame('GetOrders', $this->http->lastRequest()->getHeaderLine('X-EBAY-API-CALL-NAME'));
        $this->assertStringContainsString(
            '<OrderIDArray><OrderID>12-1</OrderID><OrderID>a&amp;b</OrderID></OrderIDArray>',
            $body
        );
        $this->assertStringContainsString('<OrderRole>Seller</OrderRole>', $body);
    }

    /**
     * More order IDs than one call allows are split across calls.
     */
    public function testBatchesOrderIds(): void
    {
        $this->queueOrders('')->queueOrders('');

        $this->api->deliveryTimes(array_map('strval', range(1, TradingApi::BATCH_SIZE + 1)));

        $this->assertCount(2, $this->http->requests);
        $this->assertSame(1, substr_count((string) $this->http->lastRequest()->getBody(), '<OrderID>'));
    }

    /**
     * The extended order ID maps to the delivery time too.
     */
    public function testExtendedOrderId(): void
    {
        $this->queueOrders(
            '<Order><OrderID>12-1</OrderID><ExtendedOrderID>12-1!999</ExtendedOrderID>'
            . '<ShippingServiceSelected><ShippingPackageInfo>'
            . '<ActualDeliveryTime>2026-11-09T10:00:00.000Z</ActualDeliveryTime>'
            . '</ShippingPackageInfo></ShippingServiceSelected></Order>'
        );

        $this->assertSame(['12-1', '12-1!999'], array_keys($this->api->deliveryTimes(['12-1'])));
    }

    /**
     * No call is made for an empty list.
     */
    public function testNoOrders(): void
    {
        $this->assertSame([], $this->api->deliveryTimes([]));
        $this->assertSame([], $this->http->requests);
    }

    /**
     * Queue a successful GetOrders response holding the given orders.
     */
    private function queueOrders(string $orders): self
    {
        $this->http->queueResponse(new Response(
            200,
            ['Content-Type' => 'text/xml'],
            '<?xml version="1.0" encoding="UTF-8"?><GetOrdersResponse xmlns="urn:ebay:apis:eBLBaseComponents">'
            . "<Ack>Success</Ack><OrderArray>$orders</OrderArray></GetOrdersResponse>"
        ));

        return $this;
    }
}
