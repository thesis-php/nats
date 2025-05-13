<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class ExternalStream implements \JsonSerializable
{
    /**
     * @param non-empty-string $api
     */
    public function __construct(
        public string $api,
        public string $deliver = '',
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'api' => $this->api,
            'deliver' => $this->deliver,
        ];
    }
}
