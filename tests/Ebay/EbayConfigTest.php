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
     * Only read-only scopes are requested.
     */
    public function testRequestsOnlyReadOnlyScopes(): void
    {
        foreach (EbayConfig::SCOPES as $scope) {
            $this->assertTrue(
                $scope === 'https://api.ebay.com/oauth/api_scope' || str_ends_with($scope, '.readonly'),
                "Scope $scope should be read-only."
            );
        }
    }
}
