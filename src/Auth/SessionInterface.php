<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Per-visitor session storage used for login state.
 */
interface SessionInterface
{
    /**
     * A stored value, or null when unset (or no session exists).
     */
    public function get(string $key): mixed;

    /**
     * Store a value, starting the session if needed.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove a value.
     */
    public function remove(string $key): void;

    /**
     * Give the session a new ID, keeping its data (prevents session fixation at login).
     */
    public function regenerate(): void;

    /**
     * Clear all data and end the session.
     */
    public function destroy(): void;
}
