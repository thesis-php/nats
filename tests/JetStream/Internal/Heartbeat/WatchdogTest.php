<?php

declare(strict_types=1);

namespace JetStream\Internal\Heartbeat;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\NoHeartbeatsReceived;
use Thesis\Nats\Internal\Subscription\Error;
use Thesis\Nats\Internal\Subscription\Operation;
use Thesis\Nats\JetStream\Internal\Heartbeat\Watchdog;
use Thesis\Nats\Subscription;
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
            new Subscription($marker->getFuture(), static function (Operation $op) use ($marker): void {
                if (!$op instanceof Error) {
                    self::fail('Operation must be Error.');
                }

                $marker->error($op->exception);
            }),
            2,
        );

        $watchdog->reset();

        self::expectException(NoHeartbeatsReceived::class);
        $marker->getFuture()->await();
    }
}
