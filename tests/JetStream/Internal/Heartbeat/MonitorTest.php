<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Heartbeat;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Thesis\Time\TimeSpan;

#[CoversClass(Monitor::class)]
#[Group('timers')]
final class MonitorTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function testMissedHeartbeats(): void
    {
        $deferred = new DeferredFuture();

        $monitor = new Monitor(TimeSpan::fromSeconds(1));
        $monitor->monitor($deferred->complete(...));

        $deferred->getFuture()->await();

        $monitor->stop();
    }
}
