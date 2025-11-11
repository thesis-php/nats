<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Amp\Cancellation;
use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\JetStream\Delivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class Bucket
{
    private const string ALL_KEYS = '>';

    /**
     * @param non-empty-string $name
     * @param non-empty-string $prefix
     * @param ?non-empty-string $jsPrefix
     */
    public function __construct(
        public string $name,
        private JetStream $js,
        private JetStream\Stream $stream,
        private string $prefix,
        private ?string $jsPrefix = null,
    ) {}

    /**
     * @param non-empty-string $key
     * @param non-negative-int $revision
     */
    public function get(string $key, int $revision = 0): ?Entry
    {
        $subject = "{$this->prefix}{$key}";

        $message = match ($revision) {
            0 => $this->stream->getLastMessageForSubject($subject),
            default => $this->stream->getMessage($revision),
        };

        if ($message === null || $message->headers?->get(Header\Subject::header()) !== $subject) {
            return null;
        }

        $state = EntryState::fromKVOperation($message->headers->get(Header\KvOperation::header()));

        return new Entry(
            bucket: $this->stream->name,
            key: $key,
            created: $message->headers->get(Header\Timestamp::Header) ?? new \DateTimeImmutable(),
            revision: $message->headers->get(Header\Sequence::header()) ?? 1,
            value: $message->payload,
            state: $state,
        );
    }

    /**
     * @param non-empty-string $key
     * @return non-negative-int
     */
    public function put(string $key, ?string $value = null): int
    {
        return $this->js
            ->publish($this->prefixedSubject($key), new Message($value))
            ->seq ?? throw new \LogicException('Sequence expected on kv publish');
    }

    /**
     * @param non-empty-string $key
     * @param non-negative-int $revision
     * @return non-negative-int
     */
    public function update(
        string $key,
        int $revision,
        ?string $value = null,
        ?TimeSpan $ttl = null,
    ): int {
        $headers = (new Headers())
            ->with(Header\ExpectedLastSubjSeq::Header, $revision);

        if ($ttl !== null) {
            $headers = $headers->with(Header\MsgTtl::Header, $ttl);
        }

        return $this->js
            ->publish($this->prefixedSubject($key), new Message(
                payload: $value,
                headers: $headers,
            ))
            ->seq ?? throw new \LogicException('Sequence expected on kv update');
    }

    /**
     * @param non-empty-string $key
     * @param ?non-negative-int $revision
     * @throws NatsException
     */
    public function delete(string $key, ?int $revision = null): void
    {
        $headers = (new Headers())
            ->with(Header\KvOperation::header(), Header\KvOperation::OP_DEL);

        if ($revision !== null) {
            $headers = $headers->with(Header\ExpectedLastSubjSeq::Header, $revision);
        }

        $this->js->publish($this->prefixedSubject($key), new Message(
            headers: $headers,
        ));
    }

    /**
     * @param non-empty-string $key
     * @param ?non-negative-int $revision
     */
    public function purge(
        string $key,
        ?int $revision = null,
        ?TimeSpan $ttl = null,
    ): void {
        $headers = (new Headers())
            ->with(Header\KvOperation::header(), Header\KvOperation::OP_PURGE)
            ->with(Header\MsgRollup::header(), Header\MsgRollup::ROLLUP_SUBJECT);

        if ($revision !== null) {
            $headers = $headers->with(Header\ExpectedLastSubjSeq::Header, $revision);
        }

        if ($ttl !== null) {
            $headers = $headers->with(Header\MsgTtl::Header, $ttl);
        }

        $this->js->publish($this->prefixedSubject($key), new Message(
            headers: $headers,
        ));
    }

    /**
     * @param callable(Entry, Subscription): void $handler
     * @param list<non-empty-string>|non-empty-string $keys
     */
    public function watch(
        callable $handler,
        string|array $keys = [],
        WatchConfig $config = new WatchConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        if (!\is_array($keys)) {
            $keys = [$keys];
        }

        $keys = array_map(
            fn(string $key): string => "{$this->prefix}{$key}",
            $keys !== [] ? $keys : [self::ALL_KEYS],
        );

        $name = $this->name;
        $prefix = $this->prefix;

        return $this->stream
            ->createOrUpdateConsumer(new JetStream\Api\ConsumerConfig(
                description: 'kv watch consumer',
                deliverPolicy: DeliverPolicy::New,
                deliverSubject: Id\generateInboxId(),
                replayPolicy: ReplayPolicy::Instant,
                headersOnly: $config->headersOnly,
                filterSubjects: $keys,
            ))
            ->push(
                static function (Delivery $delivery, Subscription $subscription) use (
                    $config,
                    $handler,
                    $name,
                    $prefix,
                ): void {
                    $metadata = $delivery->metadata;
                    if ($metadata === null) {
                        return;
                    }

                    $key = substr($delivery->subject, \strlen($prefix));
                    if ($key === '') {
                        return;
                    }

                    $state = EntryState::fromKVOperation($delivery->message->headers?->get(Header\KvOperation::header()));

                    if ($config->ignoreDeletes && \in_array($state, [EntryState::Deleted, EntryState::Purged], true)) {
                        return;
                    }

                    $entry = new Entry(
                        bucket: $name,
                        key: $key,
                        created: $metadata->timestamp,
                        revision: max($metadata->streamSequence, 0),
                        value: $delivery->message->payload,
                        delta: $metadata->pending,
                        state: $state,
                    );

                    $handler($entry, $subscription);
                },
                cancellation: $cancellation,
            );
    }

    /**
     * @param non-empty-string $cmd
     * @return non-empty-string
     */
    private function prefixedSubject(string $cmd): string
    {
        $subject = '';

        if ($this->jsPrefix !== null) {
            $subject .= $this->jsPrefix;
        }

        $subject .= "{$this->prefix}{$cmd}";

        return $subject;
    }
}
