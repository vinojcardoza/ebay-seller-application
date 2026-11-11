<?php

declare(strict_types=1);

namespace Tests\Ebay;

use App\Ebay\EbayConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading eBay settings from the environment.
 */
#[CoversClass(EbayConfig::class)]
final class EbayConfigTest extends TestCase
{
    /**
     * All EBAY_* variables are read and trimmed.
     */
    public function testFromEnvironmentReadsAllSettings(): void
    {
        $config = EbayConfig::fromEnvironment([
            'EBAY_CLIENT_ID' => ' id ',
            'EBAY_CLIENT_SECRET' => 'secret',
            'EBAY_RU_NAME' => 'ru',
            'EBAY_ENVIRONMENT' => 'sandbox',
            'EBAY_MARKETPLACE_ID' => 'EBAY_US',
            'EBAY_SELLER_USERNAME' => 'seller',
        ]);

        $this->assertSame('id', $config->clientId);
        $this->assertSame('secret', $config->clientSecret);
        $this->assertSame('ru', $config->ruName);
        $this->assertSame(EbayConfig::SANDBOX, $config->environment);
        $this->assertSame('EBAY_US', $config->marketplaceId);
        $this->assertSame('seller', $config->sellerUsername);
        $this->assertTrue($config->isConfigured());
    }

    /**
     * Missing or blank variables fall back to production, EBAY_GB and empty credentials.
     */
    public function testFromEnvironmentDefaults(): void
    {
        $config = EbayConfig::fromEnvironment(['EBAY_ENVIRONMENT' => '  ', 'EBAY_CLIENT_ID' => ['not a string']]);

        $this->assertSame(EbayConfig::PRODUCTION, $config->environment);
        $this->assertSame('EBAY_GB', $config->marketplaceId);
        $this->assertSame('', $config->clientId);
        $this->assertFalse($config->isConfigured());
    }

    /**
     * Credential combinations that are incomplete.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function incompleteCredentialsProvider(): array
    {
        return [
            'no client id' => ['', 'secret', 'ru'],
            'no secret' => ['id', '', 'ru'],
            'no RuName' => ['id', 'secret', ''],
        ];
    }

    /**
     * The integration counts as configured only when ID, secret and RuName are all set.
     */
    #[DataProvider('incompleteCredentialsProvider')]
    public function testIsConfiguredNeedsAllCredentials(string $id, string $secret, string $ruName): void
    {
        $this->assertFalse((new EbayConfig($id, $secret, $ruName))->isConfigured());
    }

    /**
     * Production and sandbox use different API and sign-in hosts.
     */
    public function testBaseUrlsDependOnEnvironment(): void
    {
        $production = new EbayConfig('id', 'secret', 'ru');
        $sandbox = new EbayConfig('id', 'secret', 'ru', EbayConfig::SANDBOX);

        $this->assertSame('https://api.ebay.com', $production->apiBaseUrl());
        $this->assertSame('https://auth.ebay.com', $production->authBaseUrl());
        $this->assertSame('https://api.sandbox.ebay.com', $sandbox->apiBaseUrl());
        $this->assertSame('https://auth.sandbox.ebay.com', $sandbox->authBaseUrl());
    }

    /**
     * An unknown environment is rejected with a clear message.
     */
    public function testRejectsUnknownEnvironment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EBAY_ENVIRONMENT must be "production" or "sandbox", got "live".');

        new EbayConfig('id', 'secret', 'ru', 'live');
    }

    /**
     * EBAY_SCOPES values and the scopes they produce.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function scopesProvider(): array
    {
        $prefix = 'https://api.ebay.com/oauth/api_scope';

        return [
            'blank uses defaults' => ['  ', EbayConfig::SCOPES],
            'short names, spaces' => [
                'api_scope sell.fulfillment.readonly',
                [$prefix, $prefix . '/sell.fulfillment.readonly'],
            ],
            'commas and duplicates' => [
                'sell.fulfillment.readonly,  sell.fulfillment.readonly',
                [$prefix . '/sell.fulfillment.readonly'],
            ],
            'full URLs' => [$prefix . '/commerce.feedback.readonly', [$prefix . '/commerce.feedback.readonly']],
        ];
    }

    /**
     * EBAY_SCOPES accepts short names or URLs, separated by spaces or commas.
     *
     * @param list<string> $expected
     */
    #[DataProvider('scopesProvider')]
    public function testScopesFromEnvironment(string $value, array $expected): void
    {
        $config = EbayConfig::fromEnvironment(['EBAY_SCOPES' => $value]);

        $this->assertSame($expected, $config->scopes);
    }

    /**
     * hasScope() reports whether a scope is requested.
     */
    public function testHasScope(): void
    {
        $config = EbayConfig::fromEnvironment(['EBAY_SCOPES' => 'api_scope sell.fulfillment.readonly']);

        $this->assertTrue($config->hasScope(EbayConfig::FULFILLMENT_SCOPE));
        $this->assertFalse($config->hasScope(EbayConfig::FEEDBACK_SCOPE));
        $this->assertTrue((new EbayConfig('id', 'secret', 'ru'))->hasScope(EbayConfig::FEEDBACK_SCOPE));
    }

    /**
     * Scopes that are not eBay scope URLs, or an empty list, are rejected.
     */
    public function testRejectsInvalidScopes(): void
    {
        try {
            EbayConfig::fromEnvironment(['EBAY_SCOPES' => 'https://evil.example/scope']);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                'EBAY_SCOPES contains "https://evil.example/scope", which is not an eBay OAuth scope.',
                $e->getMessage()
            );
        }

        $this->expectExceptionMessage('EBAY_SCOPES must list at least one scope.');
        new EbayConfig('id', 'secret', 'ru', scopes: []);
    }

    /**
     * The defaults are the base scope, read-only fulfillment and the feedback scope user tokens need.
     */
    public function testDefaultScopes(): void
    {
        $this->assertSame([
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
            'https://api.ebay.com/oauth/api_scope/commerce.feedback',
        ], EbayConfig::SCOPES);
    }

    /**
     * The read-only feedback scope (client-credentials only) does not count as the feedback scope.
     */
    public function testReadOnlyFeedbackScopeIsNotEnough(): void
    {
        $config = EbayConfig::fromEnvironment(['EBAY_SCOPES' => 'api_scope commerce.feedback.readonly']);

        $this->assertFalse($config->hasScope(EbayConfig::FEEDBACK_SCOPE));
    }
}
