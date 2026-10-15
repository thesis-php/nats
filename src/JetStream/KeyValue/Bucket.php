<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Amp\Cancellation;
use Amp\CancelledException;
use Thesis\Nats\Exception\InvalidKey;
use Thesis\Nats\Exception\WrongLastSequence;
use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\PublishOptions;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;

/**
 * @api
 */
final readonly class Bucket
{
    private const string ALL_KEYS = '>';

    /** How often the server sends heartbeats to an idle watch, as nats.go does for ordered consumers. */
    private const int WATCH_HEARTBEAT_SECONDS = 5;

    /** The age of the delete and purge markers {@see purgeDeletes()} removes by default, as in nats.go. */
    private const int PURGE_DELETES_OLDER_THAN_MINUTES = 30;

    /**
     * @internal
     * @param non-empty-string $name
     * @param non-empty-string $prefix the subject prefix of the stored entries: of the origin bucket for a mirror
     * @param non-empty-string $putPrefix the subject prefix to publish entries to, with the JetStream API prefix of a domain if any
     */
    public function __construct(
        public string $name,
        private JetStream $js,
        private JetStream\Stream $stream,
        private string $prefix,
        private string $putPrefix,
    ) {}

    /**
     * Returns the entry of the latest revision of the key or of the given one, null if there is none.
     * A deleted or purged key has an entry too, with {@see Entry::$state} telling so and no value.
     *
     * @param non-empty-string $key
     * @param non-negative-int $revision
     * @throws InvalidKey
     */
    public function get(string $key, int $revision = 0): ?Entry
    {
        Validate::key($key);

        $subject = "{$this->prefix}{$key}";

        $message = match ($revision) {
            0 => $this->stream->getLastMessageForSubject($subject),
            default => $this->stream->getMessage($revision),
        };

        if ($message === null || $message->headers?->get(Header\Subject::header()) !== $subject) {
            return null;
        }

        return new Entry(
            bucket: $this->name,
            key: $key,
            created: $message->headers->get(Header\Timestamp::Header) ?? new \DateTimeImmutable(),
            revision: $message->headers->get(Header\Sequence::header()) ?? 1,
            size: \strlen($message->payload ?? ''),
            value: $message->payload,
            state: EntryState::fromHeaders($message->headers),
        );
    }

    /**
     * @param non-empty-string $key
     * @param ?TimeSpan $ttl how long to keep the entry, requires {@see BucketConfig::$limitMarkerTtl}
     * @return non-negative-int the revision of the entry
     * @throws NatsException
     */
    public function put(
        string $key,
        ?string $value = null,
        ?Headers $headers = null,
        ?TimeSpan $ttl = null,
    ): int {
        return $this->publish($key, new Message($value, $headers), new PublishOptions(ttl: $ttl));
    }

    /**
     * Puts the value only if the key has no value: it was never put, or it was deleted or purged.
     * Otherwise, the server rejects the value with {@see WrongLastSequence}.
     *
     * @param non-empty-string $key
     * @param ?TimeSpan $ttl how long to keep the entry, requires {@see BucketConfig::$limitMarkerTtl}
     * @return non-negative-int the revision of the entry
     * @throws WrongLastSequence if the key has a value
     * @throws NatsException
     */
    public function create(
        string $key,
        ?string $value = null,
        ?TimeSpan $ttl = null,
    ): int {
        try {
            return $this->update($key, 0, $value, $ttl);
        } catch (WrongLastSequence $e) {
            // The last message of the key may be a delete or purge marker: the key has no value then.
            $entry = $this->get($key);

            if ($entry !== null && $entry->state->isMarker()) {
                return $this->update($key, $entry->revision, $value, $ttl);
            }

            throw $e;
        }
    }

    /**
     * Puts the value only if the latest revision of the key is the given one.
     *
     * @param non-empty-string $key
     * @param non-negative-int $revision
     * @param ?TimeSpan $ttl how long to keep the entry, requires {@see BucketConfig::$limitMarkerTtl}
     * @return non-negative-int the revision of the entry
     * @throws WrongLastSequence if the latest revision differs
     * @throws NatsException
     */
    public function update(
        string $key,
        int $revision,
        ?string $value = null,
        ?TimeSpan $ttl = null,
    ): int {
        return $this->publish($key, new Message($value), new PublishOptions(
            expectedLastSubjectSequence: $revision,
            ttl: $ttl,
        ));
    }

    /**
     * @param non-empty-string $key
     * @param ?non-negative-int $revision
     * @throws NatsException
     */
    public function delete(string $key, ?int $revision = null): void
    {
        $this->publish(
            $key,
            new Message(
                headers: (new Headers())
                    ->with(Header\KvOperation::header(), Header\KvOperation::OP_DEL),
            ),
            new PublishOptions(expectedLastSubjectSequence: $revision),
        );
    }

    /**
     * @param non-empty-string $key
     * @param ?non-negative-int $revision
     * @throws NatsException
     */
    public function purge(
        string $key,
        ?int $revision = null,
        ?TimeSpan $ttl = null,
    ): void {
        $this->publish(
            $key,
            new Message(
                headers: (new Headers())
                    ->with(Header\KvOperation::header(), Header\KvOperation::OP_PURGE)
                    ->with(Header\MsgRollup::header(), Header\MsgRollup::ROLLUP_SUBJECT),
            ),
            new PublishOptions(
                expectedLastSubjectSequence: $revision,
                ttl: $ttl,
            ),
        );
    }

    /**
     * Calls the handler with the latest entry of every watched key and then with every change.
     * {@see Watcher::awaitInitialValues()} tells when the entries the bucket had at the start have been handled,
     * {@see WatchConfig} changes what is delivered.
     *
     * @param callable(Entry, Subscription): void $handler
     * @param list<non-empty-string>|non-empty-string $keys keys or patterns with wildcards, all keys if empty
     * @throws NatsException
     */
    public function watch(
        callable $handler,
        string|array $keys = [],
        WatchConfig $config = new WatchConfig(),
        ?Cancellation $cancellation = null,
    ): Watcher {
        return $this->startWatch($handler, $keys, $config, cancellation: $cancellation);
    }

    /**
     * Returns the keys that have a value, i.e. are not deleted or purged.
     *
     * @param list<non-empty-string>|non-empty-string $filters keys or patterns with wildcards, all keys if empty
     * @return list<non-empty-string>
     * @throws NatsException
     */
    public function keys(string|array $filters = [], ?Cancellation $cancellation = null): array
    {
        return array_map(
            static fn(Entry $entry): string => $entry->key,
            $this->initialEntries($filters, new WatchConfig(ignoreDeletes: true, metaOnly: true), $cancellation),
        );
    }

    /**
     * Returns every stored revision of the key, from the oldest, delete and purge markers included.
     *
     * @param non-empty-string $key a key or a pattern with wildcards
     * @return list<Entry>
     * @throws NatsException
     */
    public function history(string $key, ?Cancellation $cancellation = null): array
    {
        return $this->initialEntries($key, new WatchConfig(includeHistory: true), $cancellation);
    }

    /**
     * Removes the revisions of the deleted and purged keys. The markers older than $olderThan are removed as well,
     * the recent ones are kept, so that the watches that are behind still see the keys are gone.
     *
     * @param ?TimeSpan $olderThan 30 minutes by default, zero or negative to remove all markers
     * @throws NatsException
     */
    public function purgeDeletes(?TimeSpan $olderThan = null, ?Cancellation $cancellation = null): void
    {
        $olderThan ??= TimeSpan::fromMinutes(self::PURGE_DELETES_OLDER_THAN_MINUTES);

        $markers = array_filter(
            $this->initialEntries([], new WatchConfig(metaOnly: true), $cancellation),
            static fn(Entry $entry): bool => $entry->state->isMarker(),
        );

        $now = new \DateTimeImmutable();

        foreach ($markers as $marker) {
            $recent = $olderThan->isPositive() && TimeSpan::diff($now, $marker->created)->isLessThan($olderThan);

            $this->stream->purge(
                keep: $recent ? 1 : null,
                subject: "{$this->prefix}{$marker->key}",
                cancellation: $cancellation,
            );
        }
    }

    /**
     * @throws NatsException
     */
    public function status(): BucketStatus
    {
        return new BucketStatus($this->name, $this->stream->actualInfo());
    }

    /**
     * @param list<non-empty-string>|non-empty-string $filters
     * @return list<Entry>
     * @throws NatsException
     */
    private function initialEntries(string|array $filters, WatchConfig $config, ?Cancellation $cancellation): array
    {
        $entries = new Internal\EntryCollector();

        $watcher = $this->startWatch($entries, $filters, $config, initialValuesOnly: true, cancellation: $cancellation);

        try {
            $watcher->awaitInitialValues($cancellation);
        } finally {
            try {
                $watcher->stop($cancellation);
            } catch (CancelledException) {
                // The server removes the ephemeral consumer itself once the subscription is gone.
            }
        }

        return $entries->entries();
    }

    /**
     * @param callable(Entry, Subscription): void $handler
     * @param list<non-empty-string>|non-empty-string $keys
     * @throws NatsException
     */
    private function startWatch(
        callable $handler,
        string|array $keys,
        WatchConfig $config,
        bool $initialValuesOnly = false,
        ?Cancellation $cancellation = null,
    ): Watcher {
        if (!\is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $key) {
            Validate::keyFilter($key);
        }

        $keys = array_map(
            fn(string $key): string => "{$this->prefix}{$key}",
            $keys !== [] ? $keys : [self::ALL_KEYS],
        );

        // The same precedence as in nats.go: the revision to resume from wins, then updates only.
        [$deliverPolicy, $startSeq] = match (true) {
            $config->resumeFromRevision !== null => [DeliverPolicy::ByStartSequence, $config->resumeFromRevision],
            $config->updatesOnly => [DeliverPolicy::New, null],
            $config->includeHistory => [DeliverPolicy::All, null],
            default => [DeliverPolicy::LastPerSubject, null],
        };

        // An ordered consumer of nats.go: no acks, flow control with heartbeats, a replica in memory.
        $consumerConfig = new JetStream\Api\ConsumerConfig(
            description: 'kv watch consumer',
            deliverPolicy: $deliverPolicy,
            deliverSubject: Id\generateInboxId(),
            startSeq: $startSeq,
            ackPolicy: AckPolicy::None,
            maxDeliver: 1,
            flowControl: true,
            idleHeartbeat: TimeSpan::fromSeconds(self::WATCH_HEARTBEAT_SECONDS),
            replayPolicy: ReplayPolicy::Instant,
            headersOnly: $config->metaOnly || $config->headersOnly,
            replicas: 1,
            memoryStorage: true,
            filterSubjects: $keys,
        );

        $consumer = $this->stream->createOrUpdateConsumer($consumerConfig, $cancellation);

        $initialValues = $config->updatesOnly
            ? Internal\InitialValues::none()
            : new Internal\InitialValues($consumer->info->numPending + $consumer->info->delivered->consumerSeq);

        $subscription = $consumer->push(
            new Internal\WatchHandler(
                handler: $handler,
                bucket: $this->name,
                prefix: $this->prefix,
                ignoreDeletes: $config->ignoreDeletes,
                initialValues: $initialValues,
                initialValuesOnly: $initialValuesOnly,
            ),
            cancellation: $cancellation,
        );

        // Awaited only together with the initial values: a failed watch must not be reported as unhandled.
        $completion = async($subscription->awaitCompletion(...));
        $completion->ignore();

        return new Watcher(
            subscription: $subscription,
            consumer: $consumer,
            initialValues: $initialValues->future(),
            completion: $completion,
        );
    }

    /**
     * @param non-empty-string $key
     * @return non-negative-int
     * @throws NatsException
     */
    private function publish(string $key, Message $message, PublishOptions $options): int
    {
        Validate::key($key);

        return $this->js
            ->publish("{$this->putPrefix}{$key}", $message, $options)
            ->seq ?? throw new \LogicException('Sequence expected on kv publish');
    }
}
