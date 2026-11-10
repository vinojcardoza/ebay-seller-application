<?php

declare(strict_types=1);

namespace App\Framework\Clock;

use DateTimeImmutable;

/**
 * Clock that returns the real current time.
 */
final class SystemClock implements ClockInterface
{
    /**
     * Get the current system time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
