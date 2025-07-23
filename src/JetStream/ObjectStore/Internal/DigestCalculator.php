<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore\Internal;

/**
 * @internal
 */
final readonly class DigestCalculator
{
    private const string ALGO_SHA256 = 'sha256';

    private \HashContext $context;

    /**
     * @param self::ALGO_* $algorithm
     */
    private function __construct(
        private string $algorithm,
    ) {
        $this->context = hash_init($algorithm);
    }

    public static function sha256(): self
    {
        return new self(self::ALGO_SHA256);
    }

    /**
     * @param non-empty-string $data
     */
    public function update(string $data): void
    {
        hash_update($this->context, $data);
    }

    public function finish(): string
    {
        return hash_final($this->context, true);
    }

    public function name(): string
    {
        return match ($this->algorithm) {
            self::ALGO_SHA256 => 'SHA-256',
        };
    }
}
