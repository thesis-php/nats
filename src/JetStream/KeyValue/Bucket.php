<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\JetStream;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class Bucket
{
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

        if (\in_array($message->headers->get(Header\KvOperation::header()), [Header\KvOperation::OP_DEL, Header\KvOperation::OP_PURGE], true)) {
            return null;
        }

        return new Entry(
            bucket: $this->stream->name,
            key: $key,
            created: $message->headers->get(Header\Timestamp::Header) ?? new \DateTimeImmutable(),
            sequence: $message->headers->get(Header\Sequence::header()) ?? 1,
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
