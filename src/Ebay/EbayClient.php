<?php

declare(strict_types=1);

namespace App\Ebay;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;

/**
 * Sends authenticated requests to eBay's REST APIs (JSON) and Trading API (XML).
 */
class EbayClient
{
    /**
     * Trading API schema version requested.
     */
    public const TRADING_COMPATIBILITY_LEVEL = '1349';

    /**
     * Trading API error codes meaning the token is invalid or expired
     * (931 "Auth token is invalid", 932 hard expired, 21916984 invalid IAF token).
     */
    private const TRADING_TOKEN_ERRORS = ['931', '932', '21916984'];

    /**
     * Create the client.
     */
    public function __construct(
        private readonly EbayConfig $config,
        private readonly EbayOAuth $oauth,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {
    }

    /**
     * GET a REST resource as the connected seller.
     *
     * If eBay rejects the access token (401), it is refreshed once and the call retried.
     *
     * @param string $path Path from the API host, e.g. `/sell/fulfillment/v1/order`.
     * @param array<string, scalar> $query Query parameters.
     *
     * @return array<string, mixed> Decoded JSON body (empty for an empty body).
     *
     * @throws EbayNotConnectedException When the seller has not connected eBay.
     * @throws EbayApiException On network errors or error responses.
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->config->apiBaseUrl() . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $response = $this->send($url);

        if ($response->getStatusCode() === 401) {
            $this->oauth->invalidateAccessToken();
            $response = $this->send($url);
        }

        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw EbayApiException::fromResponse("eBay request GET $path", $response->getStatusCode(), $body);
        }

        if (trim($body) === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new EbayApiException("eBay request GET $path returned invalid JSON.");
        }

        return $data;
    }

    /**
     * Call the XML Trading API as the connected seller (OAuth user token in `X-EBAY-API-IAF-TOKEN`).
     *
     * The Trading API reports a rejected token as an error in a 200 response;
     * the access token is then refreshed once and the call retried.
     *
     * @param string $callName Trading call, e.g. `GetOrders`.
     * @param string $innerXml Request fields inside `<{callName}Request>`.
     *
     * @throws EbayNotConnectedException When the seller has not connected eBay.
     * @throws EbayApiException On network errors, invalid XML or a failed call.
     */
    public function trading(string $callName, string $innerXml): SimpleXMLElement
    {
        $xml = $this->sendTrading($callName, $innerXml);

        if ($this->tradingTokenRejected($xml)) {
            $this->oauth->invalidateAccessToken();
            $xml = $this->sendTrading($callName, $innerXml);
        }

        if ((string) $xml->Ack === 'Failure') {
            $error = $xml->Errors[0] ?? null;
            $detail = $error !== null ? (string) ($error->LongMessage ?: $error->ShortMessage) : '';

            throw new EbayApiException(
                "eBay Trading call $callName failed" . ($detail !== '' ? ": $detail" : '.'),
                200,
                null,
                $error !== null ? (string) $error->ErrorCode : ''
            );
        }

        return $xml;
    }

    /**
     * Send one Trading API request and parse the XML response.
     *
     * @throws EbayApiException On network errors, HTTP errors or invalid XML.
     */
    private function sendTrading(string $callName, string $innerXml): SimpleXMLElement
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . "<{$callName}Request xmlns=\"urn:ebay:apis:eBLBaseComponents\">$innerXml</{$callName}Request>";

        $request = $this->requests->createRequest('POST', $this->config->apiBaseUrl() . '/ws/api.dll')
            ->withHeader('Content-Type', 'text/xml')
            ->withHeader('X-EBAY-API-CALL-NAME', $callName)
            ->withHeader('X-EBAY-API-SITEID', (string) $this->config->tradingSiteId())
            ->withHeader('X-EBAY-API-COMPATIBILITY-LEVEL', self::TRADING_COMPATIBILITY_LEVEL)
            ->withHeader('X-EBAY-API-IAF-TOKEN', $this->oauth->accessToken());
        $request->getBody()->write($body);

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new EbayApiException('Could not reach eBay: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw new EbayApiException(
                "eBay Trading call $callName failed (HTTP {$response->getStatusCode()}).",
                $response->getStatusCode()
            );
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new EbayApiException("eBay Trading call $callName returned invalid XML.");
        }

        return $xml;
    }

    /**
     * Whether a Trading response says the OAuth token is invalid or expired.
     */
    private function tradingTokenRejected(SimpleXMLElement $xml): bool
    {
        foreach ($xml->Errors as $error) {
            if (in_array((string) $error->ErrorCode, self::TRADING_TOKEN_ERRORS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send one GET request with the current access token.
     *
     * @throws EbayApiException On network errors.
     */
    private function send(string $url): ResponseInterface
    {
        $request = $this->requests->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->oauth->accessToken())
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-EBAY-C-MARKETPLACE-ID', $this->config->marketplaceId);

        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new EbayApiException('Could not reach eBay: ' . $e->getMessage(), 0, $e);
        }
    }
}
