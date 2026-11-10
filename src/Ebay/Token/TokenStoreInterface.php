<?php

declare(strict_types=1);

namespace App\Ebay\Token;

/**
 * Persists the seller's eBay OAuth tokens between requests.
 */
interface TokenStoreInterface
{
    /**
     * Load the saved token, or null when there is none (or it is unreadable).
     */
    public function load(): ?OAuthToken;

    /**
     * Save the token, replacing any previous one.
     */
    public function save(OAuthToken $token): void;

    /**
     * Delete the saved token.
     */
    public function clear(): void;
}
