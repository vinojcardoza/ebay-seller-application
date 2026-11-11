<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entities\User;
use App\Framework\Clock\ClockInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Logs users in and out against the `users` table and tracks the logged-in
 * user in the session. Sessions end after IDLE_TIMEOUT seconds without a request.
 */
final class Authenticator
{
    /**
     * Session key holding the logged-in user's ID.
     */
    public const USER_KEY = 'auth.user_id';

    /**
     * Session key holding the Unix time of the user's last request.
     */
    public const LAST_SEEN_KEY = 'auth.last_seen';

    /**
     * Seconds of inactivity after which the user must log in again (8 hours).
     */
    public const IDLE_TIMEOUT = 8 * 3600;

    /**
     * Shortest password accepted by setPassword().
     */
    public const MIN_PASSWORD_LENGTH = 10;

    /**
     * Hash of a random password, checked when the login is unknown so a
     * failed login takes the same time whether or not the user exists.
     */
    private const DUMMY_HASH = '$2y$12$K/nt0f6alplAf47B8EnYlO6NJNg8V8jv6c5LSN4/faivlCJiqlyVS';

    /**
     * The logged-in user, once looked up for this request.
     */
    private ?User $user = null;

    /**
     * Whether the session has been checked for a user during this request.
     */
    private bool $resolved = false;

    /**
     * Create the authenticator.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionInterface $session,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Log in with a login name or email and password. On success the session
     * gets a new ID, the last login time is recorded and an outdated password
     * hash is upgraded; returns the user, or null when the credentials are wrong.
     */
    public function attempt(string $identifier, string $password): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return null;
        }

        $users = $this->em->getRepository(User::class);
        $user = $users->findOneBy(['login' => $identifier]) ?? $users->findOneBy(['email' => $identifier]);

        if (!password_verify($password, $user?->getPassword() ?? self::DUMMY_HASH) || $user === null) {
            return null;
        }

        if (password_needs_rehash($user->getPassword(), PASSWORD_DEFAULT)) {
            $user->setPassword(password_hash($password, PASSWORD_DEFAULT));
        }

        $user->setLastLogin(DateTime::createFromImmutable($this->clock->now()));
        $this->em->flush();

        $this->session->regenerate();
        $this->session->set(self::USER_KEY, $user->getId());
        $this->session->set(self::LAST_SEEN_KEY, $this->clock->now()->getTimestamp());
        $this->user = $user;
        $this->resolved = true;

        return $user;
    }

    /**
     * The logged-in user, or null. Ends the session when it has been idle too
     * long or its user no longer exists; otherwise records this request's time.
     */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $id = $this->session->get(self::USER_KEY);

        if (!is_int($id)) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        $lastSeen = $this->session->get(self::LAST_SEEN_KEY);

        if (!is_int($lastSeen) || $now - $lastSeen > self::IDLE_TIMEOUT) {
            $this->logout();

            return null;
        }

        $user = $this->em->find(User::class, $id);

        if ($user === null) {
            $this->logout();

            return null;
        }

        $this->session->set(self::LAST_SEEN_KEY, $now);

        return $this->user = $user;
    }

    /**
     * Log out: clear and end the session.
     */
    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Whether the password is the user's current password.
     */
    public function verifyPassword(User $user, string $password): bool
    {
        return $password !== '' && password_verify($password, $user->getPassword());
    }

    /**
     * Give the logged-in session a new ID, e.g. after a password change.
     */
    public function regenerateSession(): void
    {
        $this->session->regenerate();
    }

    /**
     * Set a user's password (hashed with password_hash()), without saving.
     *
     * @throws InvalidArgumentException When the password is shorter than MIN_PASSWORD_LENGTH.
     */
    public function setPassword(User $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('The password must be at least %d characters.', self::MIN_PASSWORD_LENGTH)
            );
        }

        $user->setPassword(password_hash($password, PASSWORD_DEFAULT))->setUpdated(new DateTime());
    }
}
