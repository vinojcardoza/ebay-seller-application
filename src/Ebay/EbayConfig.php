<?php

declare(strict_types=1);

namespace App\Ebay;

use InvalidArgumentException;

/**
 * eBay REST API settings, read from environment variables.
 *
 * Credentials come from the eBay developer portal (Application Keys and User
 * Tokens → "Get a Token from eBay via Your Application"). They must never be
 * hardcoded in source.
 */
final readonly class EbayConfig
{
    /**
     * Environment value for the live eBay site.
     */
    public const PRODUCTION = 'production';

    /**
     * Environment value for the eBay sandbox.
     */
    public const SANDBOX = 'sandbox';

    /**
     * OAuth scopes requested from the seller. Read-only: the app only reads
     * orders, shipping fulfillments and feedback. Scope URLs are the same in
     * both environments.
     */
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
        'https://api.ebay.com/oauth/api_scope/commerce.feedback.readonly',
    ];

    /**
     * Create the configuration.
     *
     * @param string $clientId App ID (Client ID) of the eBay application keyset.
     * @param string $clientSecret Cert ID (Client Secret) of the keyset.
     * @param string $ruName RuName (eBay redirect URL name) whose accepted URL points at /ebay/callback.
     * @param string $environment `production` or `sandbox`.
     * @param string $marketplaceId Marketplace for API calls, e.g. `EBAY_GB`.
     * @param string $sellerUsername eBay username of the seller, used to fetch feedback.
     *
     * @throws InvalidArgumentException When the environment is not `production` or `sandbox`.
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $ruName,
        public string $environment = self::PRODUCTION,
        public string $marketplaceId = 'EBAY_GB',
        public string $sellerUsername = '',
    ) {
        if (!in_array($environment, [self::PRODUCTION, self::SANDBOX], true)) {
            throw new InvalidArgumentException(sprintf(
                'EBAY_ENVIRONMENT must be "%s" or "%s", got "%s".',
                self::PRODUCTION,
                self::SANDBOX,
                $environment
            ));
        }
    }

    /**
     * Build the configuration from environment variables (`EBAY_*`).
     *
     * @param array<string, mixed> $env Usually `$_ENV`.
     */
    public static function fromEnvironment(array $env): self
    {
        $value = static fn (string $key, string $default = ''): string => is_string($env[$key] ?? null)
            && trim($env[$key]) !== '' ? trim($env[$key]) : $default;

        return new self(
            $value('EBAY_CLIENT_ID'),
            $value('EBAY_CLIENT_SECRET'),
            $value('EBAY_RU_NAME'),
            $value('EBAY_ENVIRONMENT', self::PRODUCTION),
            $value('EBAY_MARKETPLACE_ID', 'EBAY_GB'),
            $value('EBAY_SELLER_USERNAME'),
        );
    }

    /**
     * Whether the credentials needed for OAuth are all set.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->ruName !== '';
    }

    /**
     * Base URL for REST API calls and the OAuth token endpoint.
     */
    public function apiBaseUrl(): string
    {
        return $this->environment === self::SANDBOX ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
    }

    /**
     * Base URL of the seller consent (sign-in) page.
     */
    public function authBaseUrl(): string
    {
        return $this->environment === self::SANDBOX ? 'https://auth.sandbox.ebay.com' : 'https://auth.ebay.com';
    }
}
