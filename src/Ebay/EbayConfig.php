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
     * Prefix shared by every eBay OAuth scope (the same in both environments).
     */
    public const SCOPE_PREFIX = 'https://api.ebay.com/oauth/api_scope';

    /**
     * Scope for reading orders and shipping fulfillments (Fulfillment API).
     */
    public const FULFILLMENT_SCOPE = self::SCOPE_PREFIX . '/sell.fulfillment.readonly';

    /**
     * Scope for reading the seller's own feedback (Feedback API) with a user
     * token. eBay pairs `commerce.feedback.readonly` with the client-credentials
     * flow only, so user tokens need this full scope; the app still only reads.
     * Not every keyset is granted it.
     */
    public const FEEDBACK_SCOPE = self::SCOPE_PREFIX . '/commerce.feedback';

    /**
     * Default OAuth scopes. The app only reads orders, shipping fulfillments
     * and feedback (read-only scopes except feedback; see FEEDBACK_SCOPE).
     * Override with EBAY_SCOPES when the keyset is not granted all of them
     * (eBay then answers "invalid_scope").
     */
    public const SCOPES = [self::SCOPE_PREFIX, self::FULFILLMENT_SCOPE, self::FEEDBACK_SCOPE];

    /**
     * Create the configuration.
     *
     * @param string $clientId App ID (Client ID) of the eBay application keyset.
     * @param string $clientSecret Cert ID (Client Secret) of the keyset.
     * @param string $ruName RuName (eBay redirect URL name) whose accepted URL points at /ebay/callback.
     * @param string $environment `production` or `sandbox`.
     * @param string $marketplaceId Marketplace for API calls, e.g. `EBAY_GB`.
     * @param string $sellerUsername eBay username of the seller, used to fetch feedback.
     * @param list<string> $scopes OAuth scopes to request (full scope URLs).
     *
     * @throws InvalidArgumentException When the environment is not `production` or `sandbox`,
     *     or a scope is not an eBay scope URL.
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $ruName,
        public string $environment = self::PRODUCTION,
        public string $marketplaceId = 'EBAY_GB',
        public string $sellerUsername = '',
        public array $scopes = self::SCOPES,
    ) {
        if (!in_array($environment, [self::PRODUCTION, self::SANDBOX], true)) {
            throw new InvalidArgumentException(sprintf(
                'EBAY_ENVIRONMENT must be "%s" or "%s", got "%s".',
                self::PRODUCTION,
                self::SANDBOX,
                $environment
            ));
        }

        if ($scopes === []) {
            throw new InvalidArgumentException('EBAY_SCOPES must list at least one scope.');
        }

        foreach ($scopes as $scope) {
            if ($scope !== self::SCOPE_PREFIX && !str_starts_with($scope, self::SCOPE_PREFIX . '/')) {
                throw new InvalidArgumentException(sprintf(
                    'EBAY_SCOPES contains "%s", which is not an eBay OAuth scope.',
                    $scope
                ));
            }
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
            self::parseScopes($value('EBAY_SCOPES')),
        );
    }

    /**
     * Parse EBAY_SCOPES: scopes separated by spaces or commas, either as full
     * URLs or short names such as `sell.fulfillment.readonly` (`api_scope` is
     * the base scope). Empty means the defaults.
     *
     * @return list<string>
     */
    public static function parseScopes(string $value): array
    {
        $names = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($names === []) {
            return self::SCOPES;
        }

        $scopes = array_map(
            static fn (string $name): string => match (true) {
                str_starts_with($name, 'https://') => $name,
                $name === 'api_scope' => self::SCOPE_PREFIX,
                default => self::SCOPE_PREFIX . '/' . $name,
            },
            $names
        );

        return array_values(array_unique($scopes));
    }

    /**
     * Whether a scope is among those requested.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
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
