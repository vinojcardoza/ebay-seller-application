<?php

declare(strict_types=1);

namespace App\Framework\Clock;

use DateTimeImmutable;

/**
 * Source of the current time, so time-dependent code can be tested with a fixed clock.
 */
interface ClockInterface
{
    /**
     * Get the current time.
     */
    public function now(): DateTimeImmutable;
}
