<?php

declare(strict_types=1);

namespace Tests\Framework\Clock;

use App\Framework\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the real-time clock.
 */
#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    /**
     * now() returns the current time.
     */
    public function testNowIsCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }
}
