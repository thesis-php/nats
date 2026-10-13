<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

#[CoversClass(Timer::class)]
final class TimerTest extends TestCase
{
    public function testDelayRunsOnce(): void
    {
        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $timer = Timer::delay(0.01, static function () use ($calls): void {
            $calls[] = true;
        });

        delay(0.05);

        self::assertCount(1, $calls);
        $timer->cancel(); // no-op once the callback has run
    }

    public function testCancelledDelayDoesNotRun(): void
    {
        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $timer = Timer::delay(0.01, static function () use ($calls): void {
            $calls[] = true;
        });

        $timer->cancel();
        delay(0.05);

        self::assertCount(0, $calls);
    }

    public function testUnreferencedTimerIsCancelled(): void
    {
        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $timer = Timer::delay(0.01, static function () use ($calls): void {
            $calls[] = true;
        });

        unset($timer);
        delay(0.05);

        self::assertCount(0, $calls);
    }

    public function testRepeatRunsUntilCancelled(): void
    {
        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $timer = Timer::repeat(0.01, static function () use ($calls): void {
            $calls[] = true;
        });

        delay(0.055);
        $timer->cancel();
        $count = \count($calls);
        delay(0.05);

        self::assertGreaterThanOrEqual(3, $count);
        self::assertCount($count, $calls);
    }
}
