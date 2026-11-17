<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;

/**
 * Reads an amphp byte stream, e.g. a socket, a process output or a {@see StoredObject::$stream} of another object.
 * The parts the stream returns are glued and split, so that every chunk but the last one has the requested length.
 *
 * @api
 */
final class StreamReader implements Reader
{
    private string $buffer = '';

    private bool $ended = false;

    public function __construct(
        private readonly ReadableStream $stream,
        private readonly ?Cancellation $cancellation = null,
    ) {}

    /**
     * @throws StreamException
     */
    public function eof(): bool
    {
        if ($this->buffer === '' && !$this->ended) {
            $this->fill(1);
        }

        return $this->buffer === '';
    }

    /**
     * @throws StreamException
     */
    public function read(int $length): ?string
    {
        $this->fill($length);

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $chunk !== '' ? $chunk : null;
    }

    /**
     * @param positive-int $length
     * @throws StreamException
     */
    private function fill(int $length): void
    {
        while (!$this->ended && \strlen($this->buffer) < $length) {
            $part = $this->stream->read($this->cancellation);

            if ($part === null) {
                $this->ended = true;
            } else {
                $this->buffer .= $part;
            }
        }
    }
}
