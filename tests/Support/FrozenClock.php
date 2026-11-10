<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Framework\Clock\ClockInterface;
use DateTimeImmutable;

/**
 * Clock fixed at a given time, for tests.
 */
final class FrozenClock implements ClockInterface
{
    /**
     * Current (frozen) time.
     */
    private DateTimeImmutable $now;

    /**
     * Create the clock at the given time.
     */
    public function __construct(string $now = '2026-11-10 12:00:00 UTC')
    {
        $this->now = new DateTimeImmutable($now);
    }

    /**
     * Get the frozen time.
     */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Move the clock forward.
     */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
