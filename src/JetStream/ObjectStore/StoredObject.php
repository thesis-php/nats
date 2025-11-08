<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;

/**
 * @api
 * @template-implements \IteratorAggregate<string>
 */
final readonly class StoredObject implements
    \IteratorAggregate,
    \Stringable
{
    public function __construct(
        public ObjectInfo $info,
        public ReadableStream $stream = new ReadableBuffer(),
    ) {}

    public function buffer(): string
    {
        return implode('', [...$this]);
    }

    public function getIterator(): \Traversable
    {
        while (($chunk = $this->stream->read()) !== null) {
            yield $chunk;
        }
    }

    public function __toString(): string
    {
        return $this->buffer();
    }
}
