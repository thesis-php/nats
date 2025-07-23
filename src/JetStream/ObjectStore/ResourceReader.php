<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ResourceReader implements Reader
{
    /**
     * @param resource $handle
     */
    public function __construct(
        private mixed $handle,
    ) {}

    public function eof(): bool
    {
        return feof($this->handle);
    }

    public function read(int $length): ?string
    {
        $chunk = fread($this->handle, $length);
        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }
}
