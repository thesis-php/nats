<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 */
final readonly class GroupConfig
{
    /**
     * @param non-empty-string $name the subject prefix of the group's endpoints
     * @param ?non-empty-string $queueGroup
     * @throws InvalidSubject
     */
    public function __construct(
        public string $name,
        public ?string $queueGroup = null,
    ) {
        Validate::subjectPrefix($this->name);

        if ($this->queueGroup !== null) {
            Validate::queueGroup($this->queueGroup);
        }
    }
}
