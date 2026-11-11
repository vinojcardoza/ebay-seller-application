<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Authenticator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthFixture;

/**
 * Tests for logging in and out, session expiry and setting passwords.
 */
#[CoversClass(Authenticator::class)]
final class AuthenticatorTest extends TestCase
{
    use AuthFixture;

    /**
     * Build the fixture user, session and authenticator.
     */
    protected function setUp(): void
    {
        $this->setUpAuth();
    }

    /**
     * Identifiers that log the user in.
     *
     * @return array<string, array{string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'login' => ['vinoj'],
            'email' => ['vinoj@example.com'],
            'surrounding spaces' => ['  vinoj  '],
        ];
    }

    /**
     * A correct login or email and password logs in: new session ID, user and time stored, last login recorded.
     */
    #[DataProvider('identifierProvider')]
    public function testAttemptLogsIn(string $identifier): void
    {
        $user = $this->auth->attempt($identifier, self::AUTH_PASSWORD);

        $this->assertSame($this->user, $user);
        $this->assertSame(1, $this->session->regenerated);
        $this->assertSame(1, $this->session->data[Authenticator::USER_KEY]);
        $this->assertSame($this->clock->now()->getTimestamp(), $this->session->data[Authenticator::LAST_SEEN_KEY]);
        $this->assertSame('2026-11-10 12:00:00', $this->user->getLastLogin()?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->flushes);
        $this->assertSame($this->user, $this->auth->user());
    }

    /**
     * An outdated password hash is upgraded to the current default on login.
     */
    public function testAttemptRehashesOutdatedPassword(): void
    {
        $this->auth->attempt('vinoj', self::AUTH_PASSWORD);

        $hash = $this->user->getPassword();
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
        $this->assertTrue(password_verify(self::AUTH_PASSWORD, $hash));
    }

    /**
     * Failed login attempts.
     *
     * @return array<string, array{string, string}>
     */
    public static function failedAttemptProvider(): array
    {
        return [
            'wrong password' => ['vinoj', 'wrong password'],
            'password case' => ['vinoj', strtoupper(self::AUTH_PASSWORD)],
            'unknown login' => ['someone', self::AUTH_PASSWORD],
            'empty login' => ['', self::AUTH_PASSWORD],
            'empty password' => ['vinoj', ''],
        ];
    }

    /**
     * Wrong or missing credentials log nobody in and change nothing.
     */
    #[DataProvider('failedAttemptProvider')]
    public function testAttemptFails(string $identifier, string $password): void
    {
        $this->assertNull($this->auth->attempt($identifier, $password));
        $this->assertSame([], $this->session->data);
        $this->assertSame(0, $this->session->regenerated);
        $this->assertSame(0, $this->flushes);
        $this->assertNull($this->user->getLastLogin());
        $this->assertNull($this->auth->user());
    }

    /**
     * A logged-in session returns its user and records the request time.
     */
    public function testUserFromSession(): void
    {
        $this->logIn('2026-11-10 09:00:00 UTC');

        $this->assertSame($this->user, $this->auth->user());
        $this->assertSame($this->clock->now()->getTimestamp(), $this->session->data[Authenticator::LAST_SEEN_KEY]);
    }

    /**
     * Without a session there is no user and nothing is stored.
     */
    public function testNoUserWithoutSession(): void
    {
        $this->assertNull($this->auth->user());
        $this->assertSame([], $this->session->data);
        $this->assertSame(0, $this->session->destroyed);
    }

    /**
     * Sessions idle up to 8 hours stay; longer, or with no last-seen time, end.
     *
     * @return array<string, array{string|null, bool}>
     */
    public static function idleProvider(): array
    {
        return [
            'exactly 8 hours' => ['2026-11-10 04:00:00 UTC', true],
            'over 8 hours' => ['2026-11-10 03:59:59 UTC', false],
            'no last seen' => [null, false],
        ];
    }

    /**
     * Sessions idle for longer than the timeout are ended.
     */
    #[DataProvider('idleProvider')]
    public function testIdleTimeout(?string $lastSeen, bool $loggedIn): void
    {
        $this->logIn($lastSeen ?? 'now');

        if ($lastSeen === null) {
            unset($this->session->data[Authenticator::LAST_SEEN_KEY]);
        }

        $this->assertSame($loggedIn ? $this->user : null, $this->auth->user());
        $this->assertSame($loggedIn ? 0 : 1, $this->session->destroyed);
    }

    /**
     * A session whose user was deleted is ended.
     */
    public function testDeletedUserEndsSession(): void
    {
        $this->logIn();
        $this->userExists = false;

        $this->assertNull($this->auth->user());
        $this->assertSame(1, $this->session->destroyed);
    }

    /**
     * A session holding a non-integer user ID is ignored.
     */
    public function testMalformedSessionIgnored(): void
    {
        $this->session->data[Authenticator::USER_KEY] = '1';

        $this->assertNull($this->auth->user());
    }

    /**
     * Logging out ends the session and forgets the user.
     */
    public function testLogout(): void
    {
        $this->logIn();
        $this->assertNotNull($this->auth->user());

        $this->auth->logout();

        $this->assertNull($this->auth->user());
        $this->assertSame([], $this->session->data);
        $this->assertSame(1, $this->session->destroyed);
    }

    /**
     * verifyPassword() accepts only the current password.
     */
    public function testVerifyPassword(): void
    {
        $this->assertTrue($this->auth->verifyPassword($this->user, self::AUTH_PASSWORD));
        $this->assertFalse($this->auth->verifyPassword($this->user, 'wrong'));
        $this->assertFalse($this->auth->verifyPassword($this->user, ''));
    }

    /**
     * regenerateSession() gives the session a new ID and keeps its data.
     */
    public function testRegenerateSession(): void
    {
        $this->logIn();

        $this->auth->regenerateSession();

        $this->assertSame(1, $this->session->regenerated);
        $this->assertSame(1, $this->session->data[Authenticator::USER_KEY]);
    }

    /**
     * setPassword() stores a verifiable hash, never the plain password, and marks the user updated.
     */
    public function testSetPassword(): void
    {
        $this->auth->setPassword($this->user, 'a new long password');

        $this->assertNotSame('a new long password', $this->user->getPassword());
        $this->assertTrue(password_verify('a new long password', $this->user->getPassword()));
        $this->assertNotNull($this->user->getUpdated());
    }

    /**
     * Passwords shorter than 10 characters are rejected.
     */
    public function testSetPasswordRejectsShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The password must be at least 10 characters.');

        $this->auth->setPassword($this->user, 'short');
    }
}
