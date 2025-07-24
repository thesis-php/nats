<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\QueueIterator;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Time\TimeSpan;
use function Amp\weakClosure;

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
        private Client $nats,
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

        if (\in_array($message->headers->get(Header\KvOperation::header()), [Header\KvOperation::OP_DEL, Header\KvOperation::OP_PURGE], true)) {
            return null;
        }

        return new Entry(
            bucket: $this->stream->name,
            key: $key,
            created: $message->headers->get(Header\Timestamp::Header) ?? new \DateTimeImmutable(),
            revision: $message->headers->get(Header\Sequence::header()) ?? 1,
            value: $message->payload,
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
            ->seq;
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
            ->seq;
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
     * @param list<non-empty-string>|non-empty-string $keys
     * @return QueueIterator<Entry>
     */
    public function watch(
        string|array $keys = [],
        WatchConfig $config = new WatchConfig(),
        ?Cancellation $cancellation = null,
    ): QueueIterator {
        if (!\is_array($keys)) {
            $keys = [$keys];
        }

        if ($keys === []) {
            $keys = [self::ALL_KEYS];
        }

        foreach ($keys as $idx => $key) {
            $keys[$idx] = "{$this->prefix}{$key}";
        }

        $this->stream->createOrUpdateConsumer(new JetStream\Api\ConsumerConfig(
            description: 'kv watch consumer',
            deliverPolicy: DeliverPolicy::New,
            deliverSubject: $id = Id\generateInboxId(),
            replayPolicy: ReplayPolicy::Instant,
            headersOnly: $config->headersOnly,
            filterSubjects: array_values($keys), // @phpstan-ignore-line
        ));

        /** @var Pipeline\Queue<Entry> $queue */
        $queue = new Pipeline\Queue();

        $sid = $this->nats->subscribe(
            subject: $id,
            handler: weakClosure(function (Delivery $delivery) use ($queue, $config): void {
                $reply = $delivery->replyTo;
                if ($reply === null) {
                    return;
                }

                $key = substr($delivery->subject, \strlen($this->prefix));
                if ($key === '') {
                    return;
                }

                $op = $delivery->message->headers?->get(Header\KvOperation::header());

                if (!$config->ignoreDeletes || !\in_array($op, [Header\KvOperation::OP_PURGE, Header\KvOperation::OP_DEL], true)) {
                    $metadata = JetStream\Metadata::parse($reply);

                    $queue->push(new Entry(
                        bucket: $this->name,
                        key: $key,
                        created: $metadata->timestamp,
                        revision: max($metadata->streamSequence, 0),
                        value: $delivery->message->payload,
                        delta: $metadata->pending,
                    ));
                }
            }),
            cancellation: $cancellation,
        );

        return new QueueIterator(
            iterator: $queue->iterate(),
            complete: function (?Cancellation $cancellation = null) use ($sid, $queue): void {
                $this->nats->unsubscribe($sid, $cancellation);
                $queue->complete();
            },
            cancel: function (\Throwable $e, ?Cancellation $cancellation = null) use ($sid, $queue): void {
                $this->nats->unsubscribe($sid, $cancellation);
                $queue->error($e);
            },
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
