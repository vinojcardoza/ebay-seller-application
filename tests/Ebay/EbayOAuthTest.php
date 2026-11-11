<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\EbayApiException;
use App\Ebay\EbayConfig;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\EbayOAuth;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\EbayTestCase;

/**
 * Tests for the eBay OAuth authorization-code and refresh flows.
 */
#[CoversClass(EbayOAuth::class)]
#[CoversClass(EbayApiException::class)]
final class EbayOAuthTest extends EbayTestCase
{
    /**
     * The consent URL carries the client ID, RuName, default scopes and state.
     */
    public function testAuthorizationUrl(): void
    {
        $url = $this->oauth->authorizationUrl('state-123');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://auth.ebay.com/oauth2/authorize?', $url);
        $this->assertSame([
            'client_id' => 'client-id',
            'redirect_uri' => 'Ru-Name',
            'response_type' => 'code',
            'scope' => implode(' ', EbayConfig::SCOPES),
            'state' => 'state-123',
        ], $query);
    }

    /**
     * The consent URL and refresh requests use the configured scopes, not the defaults.
     */
    public function testUsesConfiguredScopes(): void
    {
        $config = new EbayConfig(
            'client-id',
            'client-secret',
            'Ru-Name',
            scopes: [EbayConfig::SCOPE_PREFIX, EbayConfig::FULFILLMENT_SCOPE]
        );
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $oauth = new EbayOAuth($config, $this->http, $factory, $factory, $this->tokens, $this->clock);
        $expected = EbayConfig::SCOPE_PREFIX . ' ' . EbayConfig::FULFILLMENT_SCOPE;

        parse_str((string) parse_url($oauth->authorizationUrl('s'), PHP_URL_QUERY), $query);
        $this->assertSame($expected, $query['scope']);

        $this->tokens->token = $this->token('stale', '-1 minute');
        $this->http->queueJson(['access_token' => 'fresh', 'expires_in' => 7200]);
        $oauth->accessToken();

        parse_str((string) $this->http->lastRequest()->getBody(), $form);
        $this->assertSame($expected, $form['scope']);
    }

    /**
     * Exchanging a code posts it with Basic auth and stores both tokens with their expiries.
     */
    public function testExchangeCodeStoresTokens(): void
    {
        $this->tokens->token = null;
        $this->http->queueJson([
            'access_token' => 'new-access',
            'expires_in' => 7200,
            'refresh_token' => 'new-refresh',
            'refresh_token_expires_in' => 47304000,
            'token_type' => 'User Access Token',
        ]);

        $token = $this->oauth->exchangeCode('v^1.1#code');

        $request = $this->http->lastRequest();
        parse_str((string) $request->getBody(), $form);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.ebay.com/identity/v1/oauth2/token', (string) $request->getUri());
        $this->assertSame(
            'Basic ' . base64_encode('client-id:client-secret'),
            $request->getHeaderLine('Authorization')
        );
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['grant_type' => 'authorization_code', 'code' => 'v^1.1#code', 'redirect_uri' => 'Ru-Name'],
            $form
        );

        $this->assertSame($token, $this->tokens->token);
        $this->assertSame('new-access', $token->accessToken);
        $this->assertSame('new-refresh', $token->refreshToken);
        $this->assertEquals(new DateTimeImmutable('2026-11-10 14:00:00 UTC'), $token->accessTokenExpiresAt);
        $this->assertEquals(new DateTimeImmutable('2028-05-11 00:00:00 UTC'), $token->refreshTokenExpiresAt);
    }

    /**
     * An eBay error during the exchange becomes an EbayApiException with eBay's description.
     */
    public function testExchangeCodeFailure(): void
    {
        $this->http->queueJson([
            'error' => 'invalid_grant',
            'error_description' => 'the provided authorization grant code is invalid',
        ], 400);

        try {
            $this->oauth->exchangeCode('bad');
            $this->fail('Expected an exception.');
        } catch (EbayApiException $e) {
            $this->assertSame(
                'Exchanging the eBay authorization code failed (HTTP 400): '
                . 'the provided authorization grant code is invalid',
                $e->getMessage()
            );
            $this->assertSame('invalid_grant', $e->getErrorCode());
        }
    }

    /**
     * A token response without a refresh token is rejected and nothing is stored.
     */
    public function testExchangeCodeRequiresRefreshToken(): void
    {
        $this->tokens->token = null;
        $this->http->queueJson(['access_token' => 'a', 'expires_in' => 7200]);

        try {
            $this->oauth->exchangeCode('code');
            $this->fail('Expected an exception.');
        } catch (EbayApiException $e) {
            $this->assertSame('eBay did not return a refresh token.', $e->getMessage());
        }

        $this->assertNull($this->tokens->token);
    }

    /**
     * A network failure is reported as an EbayApiException with status 0.
     */
    public function testExchangeCodeNetworkError(): void
    {
        $this->http->queueNetworkError('timed out');

        try {
            $this->oauth->exchangeCode('code');
            $this->fail('Expected an exception.');
        } catch (EbayApiException $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    /**
     * A still-valid access token is returned without calling eBay.
     */
    public function testAccessTokenUsesStoredToken(): void
    {
        $this->assertSame('access-1', $this->oauth->accessToken());
        $this->assertSame([], $this->http->requests);
    }

    /**
     * An expired access token is refreshed with the refresh token, and the result stored.
     */
    public function testAccessTokenRefreshesWhenExpired(): void
    {
        $this->tokens->token = $this->token('stale', '-1 minute');
        $this->http->queueJson(['access_token' => 'fresh', 'expires_in' => 7200]);

        $this->assertSame('fresh', $this->oauth->accessToken());

        parse_str((string) $this->http->lastRequest()->getBody(), $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('refresh-1', $form['refresh_token']);
        $this->assertSame(implode(' ', EbayConfig::SCOPES), $form['scope']);
        $this->assertNotNull($this->tokens->token);
        $this->assertSame('fresh', $this->tokens->token->accessToken);
        $this->assertSame('refresh-1', $this->tokens->token->refreshToken);
    }

    /**
     * Without a stored token, or with an expired refresh token, the seller must reconnect.
     */
    public function testAccessTokenRequiresConnection(): void
    {
        $this->tokens->token = null;

        try {
            $this->oauth->accessToken();
            $this->fail('Expected an exception.');
        } catch (EbayNotConnectedException) {
            $this->assertNull($this->oauth->connection());
        }

        $this->clock->advance('+2 years');
        $this->tokens->token = $this->token();

        $this->expectException(EbayNotConnectedException::class);
        $this->oauth->accessToken();
    }

    /**
     * invalidateAccessToken() forces the next accessToken() call to refresh.
     */
    public function testInvalidateAccessTokenForcesRefresh(): void
    {
        $this->oauth->invalidateAccessToken();
        $this->http->queueJson(['access_token' => 'fresh', 'expires_in' => 7200]);

        $this->assertSame('fresh', $this->oauth->accessToken());
        $this->assertCount(1, $this->http->requests);
    }

    /**
     * connection() reports the stored token; disconnect() forgets it.
     */
    public function testConnectionAndDisconnect(): void
    {
        $this->assertNotNull($this->oauth->connection());

        $this->oauth->disconnect();

        $this->assertNull($this->oauth->connection());
        $this->assertNull($this->tokens->token);
    }
}
