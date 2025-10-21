<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\EndpointInfo;
use Thesis\Nats\Micro\ServiceIdentity;

/**
 * @internal
 */
final readonly class Info implements \JsonSerializable
{
    /**
     * @param non-empty-string $description
     * @param list<EndpointInfo> $endpoints
     */
    public function __construct(
        public ServiceIdentity $identity,
        public string $description,
        public array $endpoints = [],
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'io.nats.micro.v1.info_response',
            'name' => $this->identity->name,
            'id' => $this->identity->id,
            'version' => $this->identity->version,
            'metadata' => $this->identity->metadata,
            'endpoints' => array_map(
                static fn (EndpointInfo $endpointInfo): array => [
                    'name' => $endpointInfo->name,
                    'subject' => $endpointInfo->subject,
                    'queue_group' => $endpointInfo->queueGroup,
                    'metadata' => $endpointInfo->metadata,
                ],
                $this->endpoints,
            ),
        ];
    }
}
