<?php

declare(strict_types=1);

namespace App\Ebay;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends authenticated requests to eBay's REST APIs and decodes JSON responses.
 */
class EbayClient
{
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
