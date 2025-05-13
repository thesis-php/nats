<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class SubjectTransformConfig implements \JsonSerializable
{
    /**
     * @param string $src the subject transform source
     * @param string $dest the subject transform destination
     */
    public function __construct(
        public string $src,
        public string $dest,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'src' => $this->src,
            'dest' => $this->dest,
        ];
    }
}
