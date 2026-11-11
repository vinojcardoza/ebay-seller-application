<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Session backed by PHP's native session handling, with a hardened cookie:
 * HttpOnly, SameSite=Lax (so other sites cannot submit forms with it), Secure
 * over HTTPS, and strict mode (unknown session IDs are rejected).
 *
 * The session is only started when a value is stored or a session cookie is
 * already present, so anonymous page views do not create sessions.
 */
final class NativeSession implements SessionInterface
{
    /**
     * Session cookie name.
     */
    public const NAME = 'business_session';

    /**
     * Create the session handler.
     *
     * @param bool $secure Whether to send the cookie over HTTPS only.
     */
    public function __construct(private readonly bool $secure = false)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        return $this->resume() ? $_SESSION[$key] ?? null : null;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        if ($this->resume()) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): void
    {
        if (!$this->resume()) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(self::NAME, '', [
            'expires' => 1,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();
    }

    /**
     * Start the session if a session cookie was sent; false when there is none.
     */
    private function resume(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (!isset($_COOKIE[self::NAME])) {
            return false;
        }

        $this->start();

        return true;
    }

    /**
     * Start the session with the hardened cookie settings, if not already started.
     */
    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
        ]);
    }
}
