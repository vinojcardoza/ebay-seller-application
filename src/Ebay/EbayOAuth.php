<?php

declare(strict_types=1);

namespace App\Ebay;

use App\Ebay\Token\OAuthToken;
use App\Ebay\Token\TokenStoreInterface;
use App\Framework\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * eBay OAuth 2.0 authorization-code flow for a seller ("user token").
 *
 * 1. Send the seller to authorizationUrl() to sign in and grant consent.
 * 2. eBay redirects back with a code; exchangeCode() swaps it for tokens.
 * 3. accessToken() returns a valid access token, refreshing it with the
 *    stored refresh token when it has expired.
 */
class EbayOAuth
{
    /**
     * Create the OAuth helper.
     */
    public function __construct(
        private readonly EbayConfig $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly TokenStoreInterface $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * URL of eBay's consent page, where the seller signs in and grants access.
     *
     * @param string $state Random value echoed back to the callback to prevent CSRF.
     */
    public function authorizationUrl(string $state): string
    {
        return $this->config->authBaseUrl() . '/oauth2/authorize?' . http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->ruName,
            'response_type' => 'code',
            'scope' => implode(' ', $this->config->scopes),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code from the consent redirect for tokens, and store them.
     *
     * @throws EbayApiException When eBay rejects the code.
     */
    public function exchangeCode(string $code): OAuthToken
    {
        $data = $this->tokenRequest('Exchanging the eBay authorization code', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->ruName,
        ]);

        if (!is_string($data['refresh_token'] ?? null)) {
            throw new EbayApiException('eBay did not return a refresh token.');
        }

        $now = $this->clock->now();
        $token = new OAuthToken(
            $this->stringField($data, 'access_token'),
            $this->expiresAt($now, $data['expires_in'] ?? null),
            $data['refresh_token'],
            $this->expiresAt($now, $data['refresh_token_expires_in'] ?? null),
        );

        $this->tokens->save($token);

        return $token;
    }

    /**
     * Get a valid access token, refreshing and storing it when needed.
     *
     * @throws EbayNotConnectedException When there is no usable refresh token.
     * @throws EbayApiException When eBay refuses to refresh the token.
     */
    public function accessToken(): string
    {
        $token = $this->tokens->load();
        $now = $this->clock->now();

        if ($token === null || !$token->hasValidRefreshToken($now)) {
            throw new EbayNotConnectedException('eBay is not connected. Connect your eBay account first.');
        }

        if ($token->hasValidAccessToken($now)) {
            return $token->accessToken;
        }

        $data = $this->tokenRequest('Refreshing the eBay access token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refreshToken,
            'scope' => implode(' ', $this->config->scopes),
        ]);

        $token = $token->withAccessToken(
            $this->stringField($data, 'access_token'),
            $this->expiresAt($now, $data['expires_in'] ?? null)
        );
        $this->tokens->save($token);

        return $token->accessToken;
    }

    /**
     * Mark the stored access token as expired, so the next call refreshes it.
     * Used after eBay rejects a token that has not reached its expiry time.
     */
    public function invalidateAccessToken(): void
    {
        $token = $this->tokens->load();

        if ($token !== null) {
            $this->tokens->save($token->withExpiredAccessToken());
        }
    }

    /**
     * The stored token, or null when not connected (for showing connection status).
     */
    public function connection(): ?OAuthToken
    {
        $token = $this->tokens->load();

        return $token !== null && $token->hasValidRefreshToken($this->clock->now()) ? $token : null;
    }

    /**
     * Forget the stored tokens. The seller can also revoke access in their eBay account.
     */
    public function disconnect(): void
    {
        $this->tokens->clear();
    }

    /**
     * POST to eBay's token endpoint with the app's Basic credentials.
     *
     * @param array<string, string> $fields Form fields.
     *
     * @return array<string, mixed> Decoded JSON response.
     *
     * @throws EbayApiException On network errors or error responses.
     */
    private function tokenRequest(string $operation, array $fields): array
    {
        $request = $this->requests
            ->createRequest('POST', $this->config->apiBaseUrl() . '/identity/v1/oauth2/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withHeader(
                'Authorization',
                'Basic ' . base64_encode($this->config->clientId . ':' . $this->config->clientSecret)
            )
            ->withBody($this->streams->createStream(http_build_query($fields, '', '&', PHP_QUERY_RFC3986)));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new EbayApiException("$operation failed: " . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw EbayApiException::fromResponse($operation, $response->getStatusCode(), $body);
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new EbayApiException("$operation failed: eBay returned an invalid response.");
        }

        return $data;
    }

    /**
     * Read a required string field from a token response.
     *
     * @param array<string, mixed> $data
     *
     * @throws EbayApiException When the field is missing.
     */
    private function stringField(array $data, string $field): string
    {
        if (!is_string($data[$field] ?? null) || $data[$field] === '') {
            throw new EbayApiException("eBay's token response is missing \"$field\".");
        }

        return $data[$field];
    }

    /**
     * Expiry time from a lifetime in seconds.
     */
    private function expiresAt(DateTimeImmutable $now, mixed $seconds): DateTimeImmutable
    {
        return $now->modify('+' . max(0, (int) $seconds) . ' seconds');
    }
}
