<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\Authenticator;
use App\Entities\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use ReflectionProperty;

/**
 * For test cases: an Authenticator over an in-memory session, a frozen clock
 * (2026-11-10 12:00 UTC) and a stubbed entity manager holding one user,
 * "vinoj" (vinoj@example.com, "Vinoj Cardoza", ID 1) with password AUTH_PASSWORD.
 */
trait AuthFixture
{
    /**
     * The fixture user's password.
     */
    private const AUTH_PASSWORD = 'correct horse battery';

    /**
     * The fixture user.
     */
    private User $user;

    /**
     * Session the authenticator uses.
     */
    private ArraySession $session;

    /**
     * Clock the authenticator uses.
     */
    private FrozenClock $clock;

    /**
     * Number of flush() calls.
     */
    private int $flushes = 0;

    /**
     * Whether the user still exists (lookups return null when false).
     */
    private bool $userExists = true;

    /**
     * Stubbed entity manager holding the fixture user (counts flushes).
     */
    private EntityManagerInterface $authEm;

    /**
     * The authenticator.
     */
    private Authenticator $auth;

    /**
     * Build the fixture. The password hash uses a low bcrypt cost, so tests stay
     * fast and a successful login upgrades it.
     */
    private function setUpAuth(): void
    {
        $this->flushes = 0;
        $this->userExists = true;
        $this->user = (new User())
            ->setLogin('vinoj')
            ->setEmail('vinoj@example.com')
            ->setFullName('Vinoj Cardoza')
            ->setHash('legacy')
            ->setPassword(password_hash(self::AUTH_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        (new ReflectionProperty(User::class, 'id'))->setValue($this->user, 1);

        $this->session = new ArraySession();
        $this->clock = new FrozenClock('2026-11-10 12:00:00 UTC');

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?User => $this->storedUser(match (true) {
                isset($criteria['login']) => $criteria['login'] === $this->user->getLogin(),
                isset($criteria['email']) => $criteria['email'] === $this->user->getEmail(),
                default => false,
            })
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?User => $this->storedUser($id === 1)
        );
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });

        $this->authEm = $em;
        $this->auth = new Authenticator($em, $this->session, $this->clock);
    }

    /**
     * The fixture user when a lookup matches it and it still exists, otherwise null.
     */
    private function storedUser(bool $matches): ?User
    {
        return $matches && $this->userExists ? $this->user : null;
    }

    /**
     * Put the fixture user in the session as logged in, last seen at the given time.
     */
    private function logIn(string $lastSeen = '2026-11-10 11:00:00 UTC'): void
    {
        $this->session->data = [
            Authenticator::USER_KEY => 1,
            Authenticator::LAST_SEEN_KEY => (new DateTimeImmutable($lastSeen))->getTimestamp(),
        ];
    }
}
