<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ebay\EbayClient;
use App\Ebay\EbayConfig;
use App\Ebay\EbayOAuth;
use App\Ebay\Token\OAuthToken;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Base for eBay tests: a real OAuth/API client stack over a fake HTTP client,
 * a frozen clock and an in-memory token store.
 */
abstract class EbayTestCase extends TestCase
{
    /**
     * Fake HTTP client; queue responses on it and inspect sent requests.
     */
    protected FakeHttpClient $http;

    /**
     * Clock frozen at 2026-11-10 12:00 UTC.
     */
    protected FrozenClock $clock;

    /**
     * Token store, holding a valid token by default.
     */
    protected InMemoryTokenStore $tokens;

    /**
     * Test configuration (production, EBAY_GB, seller "test-seller").
     */
    protected EbayConfig $config;

    /**
     * OAuth helper wired to the fakes.
     */
    protected EbayOAuth $oauth;

    /**
     * API client wired to the fakes.
     */
    protected EbayClient $client;

    /**
     * Build the stack with a connected seller.
     */
    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock();
        $this->tokens = new InMemoryTokenStore($this->token());
        $this->config = new EbayConfig(
            'client-id',
            'client-secret',
            'Ru-Name',
            EbayConfig::PRODUCTION,
            'EBAY_GB',
            'test-seller'
        );

        $factory = new Psr17Factory();
        $this->oauth = new EbayOAuth($this->config, $this->http, $factory, $factory, $this->tokens, $this->clock);
        $this->client = new EbayClient($this->config, $this->oauth, $this->http, $factory);
    }

    /**
     * A token whose access token expires in an hour and refresh token in a year.
     */
    protected function token(string $access = 'access-1', string $accessExpiry = '+1 hour'): OAuthToken
    {
        $now = new \DateTimeImmutable('2026-11-10 12:00:00 UTC');

        return new OAuthToken($access, $now->modify($accessExpiry), 'refresh-1', $now->modify('+1 year'));
    }
}
