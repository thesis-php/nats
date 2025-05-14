<?php

declare(strict_types=1);

namespace Thesis\Nats\Json;

/**
 * @api
 */
interface Encoder
{
    /**
     * @return non-empty-string
     * @throws \Exception
     */
    public function encode(mixed $value): string;

    /**
     * @param non-empty-string $value
     * @return array<non-empty-string, mixed>
     * @throws \Exception
     */
    public function decode(string $value): array;
}
