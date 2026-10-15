<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue\Internal;

use Thesis\Nats\Header;
use Thesis\Nats\JetStream\Delivery;
use Thesis\Nats\JetStream\Internal\IdleListener;
use Thesis\Nats\JetStream\KeyValue\Entry;
use Thesis\Nats\JetStream\KeyValue\EntryState;
use Thesis\Nats\Subscription;

/**
 * Turns the messages of a watch consumer into entries and counts the initial values.
 *
 * @internal
 */
final readonly class WatchHandler implements IdleListener
{
    /**
     * @param callable(Entry, Subscription): void $handler
     * @param non-empty-string $bucket
     * @param non-empty-string $prefix
     * @param bool $initialValuesOnly drop the updates that come after the initial values, for the reads that stop the watch then
     */
    public function __construct(
        private mixed $handler,
        private string $bucket,
        private string $prefix,
        private bool $ignoreDeletes,
        private InitialValues $initialValues,
        private bool $initialValuesOnly = false,
    ) {}

    public function __invoke(Delivery $delivery, Subscription $subscription): void
    {
        $metadata = $delivery->metadata;
        if ($metadata === null || ($this->initialValuesOnly && $this->initialValues->delivered())) {
            return;
        }

        $key = substr($delivery->subject, \strlen($this->prefix));
        if ($key === '') {
            return;
        }

        $pending = max($metadata->pending, 0);
        $state = EntryState::fromHeaders($delivery->message->headers);

        if (!$this->ignoreDeletes || !$state->isMarker()) {
            $payload = $delivery->message->payload;

            ($this->handler)(
                new Entry(
                    bucket: $this->bucket,
                    key: $key,
                    created: $metadata->timestamp,
                    revision: max($metadata->streamSequence, 0),
                    size: $payload !== null
                        ? \strlen($payload)
                        : $delivery->message->headers?->get(Header\MsgSize::header()) ?? 0,
                    value: $payload,
                    delta: $pending,
                    state: $state,
                ),
                $subscription,
            );
        }

        // The entry is handled first, so that the initial values are complete once they are reported to be.
        $this->initialValues->receive($pending);
    }

    /**
     * The server has nothing more to deliver, so the initial values are all here even if fewer came than were pending,
     * e.g. when the bucket was purged after the watch had started.
     */
    public function idle(): void
    {
        $this->initialValues->complete();
    }
}
