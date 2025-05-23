<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class Metadata
{
    public function __construct(
        public string $stream,
        public string $consumer,
        public int $delivered,
        public int $streamSequence,
        public int $consumerSequence,
        public \DateTimeImmutable $timestamp,
        public int $pending,
        public ?string $domain = null,
        public ?string $hash = null,
        public ?string $token = null,
    ) {}

    /**
     * Parse jetstream consumer's reply subject in one of the following forms:
     * <code>
     * $JS.ACK.<stream>.<consumer>.<delivered>.<sseq>.<cseq>.<tm>.<pending>
     * $JS.ACK.<domain>.<account hash>.<stream>.<consumer>.<delivered>.<sseq>.<cseq>.<tm>.<pending>?.<a token with a random value>
     * </code>
     *
     * @internal
     * @param non-empty-string $subject
     * @throws \InvalidArgumentException
     */
    public static function parse(string $subject): self
    {
        $prefix = '$JS.ACK.';

        if (!str_starts_with($subject, $prefix)) {
            throw new \InvalidArgumentException("Invalid subject format: no leading '{$prefix}' prefix.");
        }

        $tokens = explode('.', substr($subject, strlen($prefix)));
        $length = \count($tokens);

        if ($length < 7 || ($length > 7 && $length < 9)) {
            throw new \InvalidArgumentException('Invalid subject format: incorrect length.');
        }

        if ($length === 7) {
            array_unshift($tokens, null, null);
        }

        /**
         * @var array{
         *     0: ?string,
         *     1: ?string,
         *     2: string,
         *     3: string,
         *     4: string,
         *     5: string,
         *     6: string,
         *     7: string,
         *     8: string,
         *     9?: string,
         * } $tokens
         */

        return new self(
            stream: $tokens[2],
            consumer: $tokens[3],
            delivered: (int) $tokens[4],
            streamSequence: (int) $tokens[5],
            consumerSequence: (int) $tokens[6],
            timestamp: (new \DateTimeImmutable())->setTimestamp(
                TimeSpan::fromNanoseconds((int) $tokens[7])->toSeconds(),
            ),
            pending: (int) $tokens[8],
            domain: $tokens[0],
            hash: $tokens[1],
            token: $tokens[9] ?? null,
        );
    }
}
