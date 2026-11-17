<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Ordered;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Header;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Heartbeat\Watchdog;
use Thesis\Nats\JetStream\OrderedConsumerConfig;
use Thesis\Nats\Message;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;
use function Amp\async;
use function Amp\delay;

/**
 * Delivers the messages of a stream in order and without gaps, as the ordered consumer of nats.go: an ephemeral push consumer
 * without acks, which is replaced by a new one starting right after the last message delivered once a message is lost,
 * the heartbeats stop, the consumer is deleted or the connection is restored.
 *
 * Every consumer delivers to its own subject, attached to the subscription handed out (the server counts only a literal
 * subject as an interest, so no wildcard). A delivery carries the subject of the stored message, so each one is marked
 * with the consumer whose subject it came through: whatever a replaced consumer still sends, heartbeats included, is ignored.
 *
 * @internal
 */
final class OrderedMessageHandler
{
    private const float RESET_INITIAL_INTERVAL = 1.0;
    private const float RESET_MAX_INTERVAL = 10.0;
    private const int REQUEST_TIMEOUT = 10;

    /** @var ?non-empty-string */
    private ?string $consumer = null;

    /** @var ?non-empty-string */
    private ?string $deliverSubject = null;

    /** @var ?\Closure(?Cancellation=): void unsubscribes the deliver subject of the current consumer */
    private ?\Closure $detach = null;

    /** @var ?Future<void> the consumer being replaced */
    private ?Future $reset = null;

    private ?Subscription $subscription = null;

    /** @var \WeakMap<NatsDelivery, non-negative-int> the consumer generation each delivery came from */
    private readonly \WeakMap $generations;

    private readonly Watchdog $watchdog;

    /** Cancelled once the subscription completes: a reset waiting to retry gives up. */
    private readonly DeferredCancellation $stopped;

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @param non-empty-string $stream
     * @param non-empty-string $namePrefix
     * @param non-empty-string $inbox the deliver subjects are "<inbox>.<generation>"
     */
    public function __construct(
        private readonly mixed $handler,
        private readonly Client $nc,
        private readonly JetStream $js,
        private readonly OrderedConsumerConfig $config,
        private readonly Cursor $cursor,
        private readonly string $stream,
        private readonly string $namePrefix,
        private readonly string $inbox,
    ) {
        $this->stopped = new DeferredCancellation();
        $this->generations = new \WeakMap();

        // Two heartbeats missed in a row: the consumer is gone or the server does not deliver anymore.
        $this->watchdog = new Watchdog($config->heartbeat->mul(2));
        $this->watchdog->stop();
        $this->watchdog->subscribe(fn() => $this->reset());
    }

    /**
     * Creates the first consumer, its failure is thrown to the caller: the deliver subject is unsubscribed before that,
     * so the client neither keeps it nor subscribes it again after a reconnect.
     */
    public function start(Subscription $subscription, ?Cancellation $cancellation = null): void
    {
        $this->subscription = $subscription;

        $config = $this->nextConsumer();
        $this->attach($cancellation);

        try {
            $this->js->createOrUpdateConsumer($this->stream, $config, $cancellation);
        } catch (\Throwable $e) {
            [$detach, $this->detach] = [$this->detach, null];

            try {
                $detach?->__invoke(new TimeoutCancellation(self::REQUEST_TIMEOUT));
            } catch (\Throwable) {
                // The connection is gone, the server-side subscription with it.
            }

            throw $e;
        }

        $this->watchdog->reset();
    }

    public function __invoke(NatsDelivery $delivery, Subscription $subscription): void
    {
        $this->watchdog->stop();

        $this->handle($delivery, $subscription);

        if (!$subscription->completed() && $this->reset === null) {
            $this->watchdog->reset();
        }
    }

    public function disconnected(): void
    {
        $this->watchdog->stop();
    }

    /**
     * The flow control replies may have been lost with the connection, and the server would wait for them forever.
     */
    public function reconnected(): void
    {
        $this->reset();
    }

    /**
     * Called once the subscription completes: the consumer and its deliver subject are not needed anymore.
     */
    public function stop(): void
    {
        $this->stopped->cancel();
        $this->watchdog->stop();
        $this->detach();
        $this->deleteConsumer($this->consumer);
    }

    private function handle(NatsDelivery $delivery, Subscription $subscription): void
    {
        // Sent by a consumer already replaced.
        if (($this->generations[$delivery] ?? null) !== $this->cursor->generation) {
            return;
        }

        $headers = $delivery->message->headers;

        if (($headers?->statusCode() ?? Status::OK) !== Status::OK) {
            $description = $headers?->statusDescription();

            if ($description?->is(Description::FlowControl) ?? false) {
                $delivery->reply(new Message());
            } elseif ($description?->is(Description::IdleHeartbeat) ?? false) {
                $this->heartbeat($delivery);
            } elseif ($description?->is(Description::ConsumerDeleted) ?? false) {
                $this->reset();
            }

            return;
        }

        $jsDelivery = $this->nc->toJetStreamDelivery($delivery);
        $metadata = $jsDelivery->metadata;

        if ($metadata === null) {
            return;
        }

        if (!$this->cursor->follows($metadata)) {
            $this->reset();

            return;
        }

        $this->cursor->advance($metadata);

        ($this->handler)($jsDelivery, $subscription);
    }

    private function heartbeat(NatsDelivery $heartbeat): void
    {
        $headers = $heartbeat->message->headers;

        // The server still waits for the reply to a flow control request: it may have been lost, reply again as nats.go does.
        $stalled = $headers?->get(Header\ConsumerStalled::header());
        if ($stalled !== null && $stalled !== '') {
            $this->nc->publish($stalled, new Message());
        }

        $delivered = $headers?->get(Header\LastConsumer::header());
        if ($delivered !== null && $delivered !== $this->cursor->consumerSequence) {
            $this->reset();
        }
    }

    /**
     * Replaces the consumer in the background: messages keep coming meanwhile, the ones of the replaced consumer are ignored.
     */
    private function reset(): void
    {
        if ($this->reset !== null || ($this->subscription?->completed() ?? true)) {
            return;
        }

        $this->watchdog->stop();

        $this->reset = async(function (): void {
            try {
                $this->recreate();
            } catch (CancelledException) {
                // The subscription has completed while waiting to retry.
            } catch (\Throwable $e) {
                $subscription = $this->subscription;
                if ($subscription !== null && !$subscription->completed()) {
                    $subscription->error($e);
                }
            } finally {
                $this->reset = null;

                if (!($this->subscription?->completed() ?? true)) {
                    $this->watchdog->reset();
                }
            }
        });
        $this->reset->ignore();
    }

    /**
     * @throws CancelledException
     * @throws \Throwable
     */
    private function recreate(): void
    {
        $this->detach();
        $this->deleteConsumer($this->consumer);

        $config = $this->nextConsumer();
        $created = $this->consumer;

        $attempts = 0;
        $interval = self::RESET_INITIAL_INTERVAL;

        while (!($this->subscription?->completed() ?? true)) {
            try {
                $this->attach(new TimeoutCancellation(self::REQUEST_TIMEOUT));
                $this->js->createOrUpdateConsumer($this->stream, $config, new TimeoutCancellation(self::REQUEST_TIMEOUT));
            } catch (StreamNotFound $e) {
                // Nothing to read anymore: retrying would never end.
                throw $e;
            } catch (\Throwable $e) {
                if ($this->config->maxResetAttempts !== null && ++$attempts >= $this->config->maxResetAttempts) {
                    throw $e;
                }

                $this->nc->reportError($e);

                delay($interval, cancellation: $this->stopped->getCancellation());
                $interval = min($interval * 2, self::RESET_MAX_INTERVAL);

                continue;
            }

            // Completed while the consumer was being created: stop() could not delete it yet.
            if ($this->subscription?->completed() ?? true) {
                $this->detach();
                $this->deleteConsumer($created);
            }

            return;
        }
    }

    /**
     * Switches to the next consumer before it is created: the server may deliver to it before the creation is confirmed.
     */
    private function nextConsumer(): ConsumerConfig
    {
        $generation = $this->cursor->nextGeneration();
        $this->consumer = "{$this->namePrefix}_{$generation}";
        $this->deliverSubject = "{$this->inbox}.{$generation}";

        $config = $this->config;
        $filterSubjects = $config->filterSubjects;

        // Nothing delivered yet: start where asked, otherwise right after the last message.
        if ($this->cursor->streamSequence === 0) {
            $deliverPolicy = $config->deliverPolicy;
            $startSeq = $deliverPolicy === DeliverPolicy::ByStartSequence ? $config->startSeq : null;
            $startTime = $deliverPolicy === DeliverPolicy::ByStartTime ? $config->startTime : null;

            if ($deliverPolicy === DeliverPolicy::LastPerSubject && $filterSubjects === []) {
                $filterSubjects = ['>'];
            }
        } else {
            $deliverPolicy = DeliverPolicy::ByStartSequence;
            $startSeq = $this->cursor->streamSequence + 1;
            $startTime = null;
        }

        return new ConsumerConfig(
            name: $this->consumer,
            description: 'ordered consumer',
            deliverPolicy: $deliverPolicy,
            deliverSubject: $this->deliverSubject,
            startSeq: $startSeq,
            startTime: $startTime,
            ackPolicy: AckPolicy::None,
            maxDeliver: 1,
            filterSubject: \count($filterSubjects) === 1 ? $filterSubjects[0] : null,
            flowControl: true,
            idleHeartbeat: $config->heartbeat,
            replayPolicy: $config->replayPolicy,
            headersOnly: $config->headersOnly ? true : null,
            inactiveThreshold: $config->inactiveThreshold,
            replicas: 1,
            memoryStorage: true,
            filterSubjects: \count($filterSubjects) > 1 ? $filterSubjects : null,
            metadata: $config->metadata,
        );
    }

    /**
     * Subscribes the deliver subject of the current consumer, once: the SUB goes before the consumer is created,
     * so the server sees the interest at once.
     */
    private function attach(?Cancellation $cancellation = null): void
    {
        if ($this->detach !== null || $this->subscription === null || $this->deliverSubject === null) {
            return;
        }

        $generations = $this->generations;
        $generation = $this->cursor->generation;

        $this->detach = $this->nc->attach(
            $this->subscription,
            $this->deliverSubject,
            static function (NatsDelivery $delivery) use (
                $generations,
                $generation,
            ): void {
                $generations[$delivery] = $generation;
            },
            $cancellation,
        );
    }

    private function detach(): void
    {
        [$detach, $this->detach] = [$this->detach, null];

        if ($detach === null) {
            return;
        }

        $future = async(static function () use ($detach): void {
            try {
                $detach(new TimeoutCancellation(self::REQUEST_TIMEOUT));
            } catch (\Throwable) {
                // The connection is gone, the server-side subscription with it.
            }
        });
        $future->ignore();
    }

    /**
     * @param ?non-empty-string $consumer
     */
    private function deleteConsumer(?string $consumer): void
    {
        if ($consumer === null) {
            return;
        }

        $js = $this->js;
        $stream = $this->stream;

        // Best effort: a consumer left behind is removed by the server after the inactive threshold.
        $future = async(static function () use (
            $js,
            $stream,
            $consumer,
        ): void {
            try {
                $js->deleteConsumer($stream, $consumer, new TimeoutCancellation(self::REQUEST_TIMEOUT));
            } catch (\Throwable) {
            }
        });
        $future->ignore();
    }
}
