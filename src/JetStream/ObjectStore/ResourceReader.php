<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\Exception\FileIsNotAccessible;

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

    /**
     * @throws FileIsNotAccessible if reading fails: an object cut short must not be stored as a whole one
     */
    public function read(int $length): ?string
    {
        error_clear_last();
        $chunk = @fread($this->handle, $length);

        if ($chunk === false) {
            throw new FileIsNotAccessible(\sprintf('Failed to read the resource: %s.', error_get_last()['message'] ?? 'unknown error'));
        }

        return $chunk !== '' ? $chunk : null;
    }
}
