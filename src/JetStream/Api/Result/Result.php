<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

use Thesis\Nats\Exception\NoServerResponse;
use Thesis\Nats\NatsException;

/**
 * @internal
 * @template ResponseType of object
 */
final readonly class Result
{
    /**
     * @param ?non-empty-string $type
     * @param null|ResponseType $response
     */
    public function __construct(
        public ?string $type = null,
        public ?Error $error = null,
        public ?object $response = null,
    ) {}

    /**
     * @return ResponseType
     * @throws NatsException
     */
    public function response(): mixed
    {
        return $this->response ?? throw $this->error?->exception() ?? new NoServerResponse();
    }

    /**
     * @param non-empty-string $type
     * @return non-empty-string
     */
    public static function type(string $type): string
    {
        return self::class . "<{$type}>";
    }
}
