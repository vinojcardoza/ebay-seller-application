<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\EbayApiException;
use App\Ebay\EbayClient;
use App\Ebay\EbayNotConnectedException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\EbayTestCase;

/**
 * Tests for authenticated REST requests to eBay.
 */
#[CoversClass(EbayClient::class)]
#[CoversClass(EbayApiException::class)]
final class EbayClientTest extends EbayTestCase
{
    /**
     * GET requests carry the bearer token, marketplace and query, and JSON is decoded.
     */
    public function testGetSendsAuthenticatedRequest(): void
    {
        $this->http->queueJson(['orders' => [], 'total' => 0]);

        $data = $this->client->get('/sell/fulfillment/v1/order', [
            'filter' => 'creationdate:[2026-11-07T00:00:00.000Z..]',
            'limit' => 200,
        ]);

        $request = $this->http->lastRequest();
        $this->assertSame(['orders' => [], 'total' => 0], $data);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.ebay.com/sell/fulfillment/v1/order', strtok((string) $request->getUri(), '?'));
        $this->assertSame(
            ['filter' => 'creationdate:[2026-11-07T00:00:00.000Z..]', 'limit' => '200'],
            $this->http->query()
        );
        $this->assertSame('Bearer access-1', $request->getHeaderLine('Authorization'));
        $this->assertSame('EBAY_GB', $request->getHeaderLine('X-EBAY-C-MARKETPLACE-ID'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    /**
     * A 401 refreshes the access token once and retries the call.
     */
    public function testRetriesOnceAfterRefreshingOn401(): void
    {
        $this->http
            ->queueJson(['errors' => [['message' => 'Invalid access token']]], 401)
            ->queueJson(['access_token' => 'fresh', 'expires_in' => 7200])
            ->queueJson(['ok' => true]);

        $this->assertSame(['ok' => true], $this->client->get('/x'));
        $this->assertCount(3, $this->http->requests);
        $this->assertSame('Bearer fresh', $this->http->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * eBay error responses become exceptions with eBay's message and the status code.
     */
    public function testErrorResponseThrows(): void
    {
        $this->http->queueJson([
            'errors' => [[
                'errorId' => 32100,
                'message' => 'Invalid order ID',
                'longMessage' => 'Order 1 was not found.',
            ]],
        ], 404);

        try {
            $this->client->get('/sell/fulfillment/v1/order/1');
            $this->fail('Expected an exception.');
        } catch (EbayApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('32100', $e->getErrorCode());
            $this->assertSame(
                'eBay request GET /sell/fulfillment/v1/order/1 failed (HTTP 404): Order 1 was not found.',
                $e->getMessage()
            );
        }
    }

    /**
     * An error with no JSON body still produces a readable message.
     */
    public function testErrorWithoutBody(): void
    {
        $this->http->queueResponse(new Response(503, [], 'Service Unavailable'));

        $this->expectException(EbayApiException::class);
        $this->expectExceptionMessage('eBay request GET /x failed (HTTP 503).');

        $this->client->get('/x');
    }

    /**
     * An empty body decodes to an empty array; invalid JSON is an error.
     */
    public function testEmptyAndInvalidBodies(): void
    {
        $this->http->queueResponse(new Response(204))->queueResponse(new Response(200, [], '<html>'));

        $this->assertSame([], $this->client->get('/empty'));

        $this->expectException(EbayApiException::class);
        $this->expectExceptionMessage('returned invalid JSON');
        $this->client->get('/html');
    }

    /**
     * Network errors become EbayApiException.
     */
    public function testNetworkError(): void
    {
        $this->http->queueNetworkError('Could not resolve host');

        $this->expectException(EbayApiException::class);
        $this->expectExceptionMessage('Could not reach eBay: Could not resolve host');

        $this->client->get('/x');
    }

    /**
     * Without a connection no request is sent.
     */
    public function testNotConnected(): void
    {
        $this->tokens->token = null;

        try {
            $this->client->get('/x');
            $this->fail('Expected an exception.');
        } catch (EbayNotConnectedException) {
            $this->assertSame([], $this->http->requests);
        }
    }
    /**
     * Trading calls POST XML to api.dll with the call name, UK site ID and the token as IAF token.
     */
    public function testTradingSendsAuthenticatedXml(): void
    {
        $this->queueXml('<Ack>Success</Ack><Timestamp>2026-11-10T12:00:00.000Z</Timestamp>');

        $xml = $this->client->trading('GetOrders', '<OrderRole>Seller</OrderRole>');

        $request = $this->http->lastRequest();
        $this->assertSame('Success', (string) $xml->Ack);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.ebay.com/ws/api.dll', (string) $request->getUri());
        $this->assertSame('GetOrders', $request->getHeaderLine('X-EBAY-API-CALL-NAME'));
        $this->assertSame('3', $request->getHeaderLine('X-EBAY-API-SITEID'));
        $this->assertSame(
            EbayClient::TRADING_COMPATIBILITY_LEVEL,
            $request->getHeaderLine('X-EBAY-API-COMPATIBILITY-LEVEL')
        );
        $this->assertSame('access-1', $request->getHeaderLine('X-EBAY-API-IAF-TOKEN'));
        $this->assertSame('text/xml', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            '<?xml version="1.0" encoding="utf-8"?><GetOrdersRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<OrderRole>Seller</OrderRole></GetOrdersRequest>',
            (string) $request->getBody()
        );
    }

    /**
     * A Trading response rejecting the token refreshes it once and retries the call.
     */
    public function testTradingRetriesOnceAfterTokenError(): void
    {
        $this->queueXml('<Ack>Failure</Ack><Errors><ErrorCode>21916984</ErrorCode></Errors>');
        $this->http->queueJson(['access_token' => 'fresh', 'expires_in' => 7200]);
        $this->queueXml('<Ack>Success</Ack>');

        $this->assertSame('Success', (string) $this->client->trading('GetOrders', '')->Ack);
        $this->assertCount(3, $this->http->requests);
        $this->assertSame('fresh', $this->http->lastRequest()->getHeaderLine('X-EBAY-API-IAF-TOKEN'));
    }

    /**
     * A failed Trading call throws with eBay's message and error code.
     */
    public function testTradingFailureThrows(): void
    {
        $this->queueXml(
            '<Ack>Failure</Ack><Errors><ShortMessage>Bad</ShortMessage>'
            . '<LongMessage>Invalid order ID.</LongMessage><ErrorCode>10007</ErrorCode></Errors>'
        );

        try {
            $this->client->trading('GetOrders', '');
            $this->fail('Expected an exception.');
        } catch (EbayApiException $e) {
            $this->assertSame('eBay Trading call GetOrders failed: Invalid order ID.', $e->getMessage());
            $this->assertSame('10007', $e->getErrorCode());
            $this->assertCount(1, $this->http->requests);
        }
    }

    /**
     * Warnings do not fail a Trading call.
     */
    public function testTradingWarningSucceeds(): void
    {
        $this->queueXml('<Ack>Warning</Ack><Errors><ErrorCode>21917091</ErrorCode></Errors>');

        $this->assertSame('Warning', (string) $this->client->trading('GetOrders', '')->Ack);
    }

    /**
     * HTTP errors, invalid XML and network errors from the Trading API throw.
     */
    public function testTradingTransportErrors(): void
    {
        $this->http
            ->queueResponse(new Response(500, [], 'oops'))
            ->queueResponse(new Response(200, [], 'not xml'))
            ->queueNetworkError('Timeout');

        foreach (['failed (HTTP 500)', 'returned invalid XML', 'Could not reach eBay: Timeout'] as $message) {
            try {
                $this->client->trading('GetOrders', '');
                $this->fail('Expected an exception.');
            } catch (EbayApiException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * Queue a Trading API response with the given body fields.
     */
    private function queueXml(string $fields): void
    {
        $this->http->queueResponse(new Response(
            200,
            ['Content-Type' => 'text/xml'],
            '<?xml version="1.0" encoding="UTF-8"?>'
            . "<GetOrdersResponse xmlns=\"urn:ebay:apis:eBLBaseComponents\">$fields</GetOrdersResponse>"
        ));
    }
}
