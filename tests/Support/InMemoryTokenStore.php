<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ebay\Token\OAuthToken;
use App\Ebay\Token\TokenStoreInterface;

/**
 * Token store held in memory, for tests.
 */
final class InMemoryTokenStore implements TokenStoreInterface
{
    /**
     * Number of times save() was called.
     */
    public int $saves = 0;

    /**
     * Create the store, optionally holding a token.
     */
    public function __construct(public ?OAuthToken $token = null)
    {
    }

    /**
     * Return the held token.
     */
    public function load(): ?OAuthToken
    {
        return $this->token;
    }

    /**
     * Hold the token and count the save.
     */
    public function save(OAuthToken $token): void
    {
        $this->token = $token;
        $this->saves++;
    }

    /**
     * Drop the held token.
     */
    public function clear(): void
    {
        $this->token = null;
    }
}
