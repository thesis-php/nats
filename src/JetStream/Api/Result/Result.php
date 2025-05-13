<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

/**
 * @internal
 * @template ResponseType
 */
final readonly class Result
{
    /**
     * @param non-empty-string $type
     * @param null|ResponseType $response
     */
    public function __construct(
        public string $type,
        public ?Error $error = null,
        public mixed $response = null,
    ) {}

    /**
     * @param non-empty-string $type
     * @return non-empty-string
     */
    public static function type(string $type): string
    {
        return self::class . "<{$type}>";
    }
}
