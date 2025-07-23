<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final class StringReader implements Reader
{
    /** @var non-negative-int */
    private int $cursor = 0;

    /** @var positive-int */
    private readonly int $length;

    /**
     * @param non-empty-string $data
     */
    public function __construct(
        private readonly string $data,
    ) {
        $this->length = \strlen($data);
    }

    public function eof(): bool
    {
        return $this->cursor >= $this->length;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->data, $this->cursor, $length);
        $this->cursor += $length;

        return $chunk;
    }
}
