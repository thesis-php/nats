<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\Header;
use Thesis\Nats\JetStream;
use Thesis\Nats\Message;

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
        $subject = '';

        if ($this->jsPrefix !== null) {
            $subject .= $this->jsPrefix;
        }

        $subject .= "{$this->prefix}{$key}";

        return $this->js
            ->publish($subject, new Message($value))
            ->seq;
    }
}
