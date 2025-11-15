<?php

declare(strict_types=1);

namespace JetStream\Internal\Heartbeat;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\NoHeartbeatsReceived;
use Thesis\Nats\JetStream\Internal\Heartbeat\Watchdog;
use Thesis\Time\TimeSpan;

#[CoversClass(Watchdog::class)]
#[Group('timers')]
final class WatchdogTest extends TestCase
{
    public function testMissedHeartbeats(): void
    {
        /** @var DeferredFuture<never> $marker */
        $marker = new DeferredFuture();

        $watchdog = new Watchdog(
            TimeSpan::fromMilliseconds(100),
            2,
        );

        $watchdog->subscribe($marker->error(...));

        self::expectException(NoHeartbeatsReceived::class);
        $marker->getFuture()->await();
    }
}
