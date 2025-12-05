<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Pipeline\Queue;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\FetchConfig;
use Thesis\Nats\JetStream\Internal\Heartbeat\Watchdog;
use Thesis\Nats\Subscription;

/**
 * @internal
 */
final class FetchMessageHandler
{
    /** @var non-negative-int */
    private int $messages = 0;

    /** @var non-negative-int */
    private int $bytes = 0;

    /**
     * @param Queue<JetStreamDelivery> $queue
     */
    public function __construct(
        private readonly FetchConfig $config,
        private readonly Client $nc,
        private readonly Queue $queue,
        private readonly Watchdog $watchdog,
    ) {}

    public function __invoke(NatsDelivery $delivery, Subscription $subscription): void
    {
        $this->watchdog->reset();

        if (!($delivery->message->headers?->ok() ?? true)) {
            $complete = $delivery->message->headers
                ?->statusDescription()
                ->is(
                    Description::MaxBytesExceeded,
                    Description::BatchCompleted,
                    Description::ConsumerDeleted,
                    Description::LeadershipChange,
                    Description::ServerShutdown,
                    Description::NoMessages,
                    Description::RequestTimeout,
                )
                ?? false;

            if ($complete) {
                $subscription->stop();
            }

            return;
        }

        $this->queue->push($this->nc->toJetStreamDelivery($delivery));

        if ($this->quotaExhausted($delivery)) {
            $subscription->stop();
        }
    }

    private function quotaExhausted(NatsDelivery $delivery): bool
    {
        if ($this->config->batch === ++$this->messages) {
            return true;
        }

        if ($this->config->maxBytes !== null) {
            $this->bytes += $delivery->size();

            if ($this->bytes >= $this->config->maxBytes) {
                return true;
            }
        }

        return false;
    }
}
