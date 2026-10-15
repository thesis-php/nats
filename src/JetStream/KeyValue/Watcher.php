<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\JetStream\Consumer;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;

/**
 * A running {@see Bucket::watch()}: the subscription that passes entries to the handler and the consumer behind it.
 *
 * @api
 */
final readonly class Watcher
{
    /**
     * @internal
     * @param Future<void> $initialValues
     * @param Future<void> $completion
     */
    public function __construct(
        private Subscription $subscription,
        private Consumer $consumer,
        private Future $initialValues,
        private Future $completion,
    ) {}

    /**
     * Waits until the handler has been called for every value the bucket had when the watch started
     * (for every stored revision with {@see WatchConfig::$includeHistory}, from the revision of {@see WatchConfig::$resumeFromRevision}).
     * Returns at once if there were none or with {@see WatchConfig::$updatesOnly}, and when the watch completes before that;
     * if the watch fails, e.g. the handler throws, the error is rethrown.
     */
    public function awaitInitialValues(?Cancellation $cancellation = null): void
    {
        Future\awaitFirst([$this->initialValues, $this->completion], $cancellation);
    }

    /**
     * Whether the handler has been called for every initial value, see {@see awaitInitialValues()}.
     */
    public function initialValuesDelivered(): bool
    {
        return $this->initialValues->isComplete();
    }

    /**
     * Stops the subscription, the received entries that are not handled yet are dropped, and deletes the consumer.
     *
     * @throws CancelledException
     * @throws NatsException
     */
    public function stop(?Cancellation $cancellation = null): void
    {
        $this->subscription->stop($cancellation);
        $this->deleteConsumer($cancellation);
    }

    /**
     * Stops the subscription after the received entries are handled and deletes the consumer.
     *
     * @throws NatsException
     */
    public function drain(?Cancellation $cancellation = null): void
    {
        $this->subscription->drain($cancellation);
        $this->deleteConsumer($cancellation);
    }

    public function awaitCompletion(?Cancellation $cancellation = null): void
    {
        $this->subscription->awaitCompletion($cancellation);
    }

    public function completed(): bool
    {
        return $this->subscription->completed();
    }

    /**
     * @throws NatsException
     */
    private function deleteConsumer(?Cancellation $cancellation): void
    {
        try {
            $this->consumer->delete($cancellation);
        } catch (ConsumerNotFound|StreamNotFound) {
            // Already gone, e.g. with the bucket.
        }
    }
}
