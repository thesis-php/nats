<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\ServiceIdentity;

/**
 * @internal
 */
final readonly class Ping implements \JsonSerializable
{
    public function __construct(
        public ServiceIdentity $identity,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'io.nats.micro.v1.ping_response',
            'name' => $this->identity->name,
            'id' => $this->identity->id,
            'version' => $this->identity->version,
            'metadata' => $this->identity->metadata,
        ];
    }
}
