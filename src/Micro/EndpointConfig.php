<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 */
final readonly class EndpointConfig
{
    /**
     * @param non-empty-string $name
     * @param ?non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param array<non-empty-string, string> $metadata
     * @throws InvalidName
     * @throws InvalidSubject
     */
    public function __construct(
        public string $name,
        public ?string $subject = null,
        public ?string $queueGroup = null,
        public array $metadata = [],
    ) {
        Validate::endpointName($this->name);

        if ($this->subject !== null) {
            Validate::subscribeSubject($this->subject);
        }

        if ($this->queueGroup !== null) {
            Validate::queueGroup($this->queueGroup);
        }
    }
}
