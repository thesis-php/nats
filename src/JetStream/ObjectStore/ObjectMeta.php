<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 */
final readonly class ObjectMeta
{
    /**
     * @param non-empty-string $name
     * @param array<string, list<string>> $headers
     * @param array<string, string> $metadata
     * @param ?positive-int $chunkSize
     * @throws ObjectIsInvalid
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $headers = [],
        public array $metadata = [],
        public ?int $chunkSize = null,
    ) {
        Validate::objectName($this->name);
    }
}
