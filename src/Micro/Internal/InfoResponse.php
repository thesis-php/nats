<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\EndpointInfo;
use Thesis\Nats\Micro\Info;

/**
 * @internal
 */
final readonly class InfoResponse implements \JsonSerializable
{
    public function __construct(
        private Info $info,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'io.nats.micro.v1.info_response',
            'name' => $this->info->identity->name,
            'id' => $this->info->identity->id,
            'version' => $this->info->identity->version,
            'metadata' => $this->info->identity->metadata,
            'endpoints' => array_map(
                static fn(EndpointInfo $endpointInfo): array => [
                    'name' => $endpointInfo->name,
                    'subject' => $endpointInfo->subject,
                    'queue_group' => $endpointInfo->queueGroup,
                    'metadata' => $endpointInfo->metadata,
                ],
                $this->info->endpoints,
            ),
        ];
    }
}
