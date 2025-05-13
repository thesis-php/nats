<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 * @template TValue = mixed
 * @template-implements \IteratorAggregate<non-empty-string, list<TValue>>
 */
final readonly class Headers implements \IteratorAggregate
{
    private const string PREFIX = 'NATS/1.0';

    /**
     * @param array<non-empty-string, list<TValue>> $keyvals
     */
    public function __construct(
        public array $keyvals = [],
        public ?int $status = null,
    ) {}

    /**
     * @param array<non-empty-string, mixed> $keyvals
     */
    public static function fromArray(array $keyvals): self
    {
        $headers = [];

        foreach ($keyvals as $key => $val) {
            if (!\is_array($val)) {
                $val = [$val];
            } elseif (!array_is_list($val)) {
                $val = array_values($val);
            }

            $headers[$key] = $val;
        }

        return new self($headers);
    }

    /**
     * @return self<string>
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $encoded): self
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new \UnexpectedValueException(\sprintf('Invalid msg headers "%s" received: no leading prefix "%s".', $encoded, self::PREFIX));
        }

        $lines = explode("\r\n", trim($encoded));
        $status = self::parseStatus(array_shift($lines));

        $headers = [];

        foreach ($lines as $line) {
            $keypair = explode(': ', $line);
            if (\count($keypair) !== 2) {
                throw new \InvalidArgumentException(\sprintf('Invalid msg header line "%s" received.', $line));
            }

            [$key, $value] = $keypair;

            if ($key !== '') {
                $headers[$key][] = $value;
            }
        }

        return new self($headers, $status);
    }

    /**
     * @return non-empty-string
     */
    public function encode(): string
    {
        $buffer = self::PREFIX;
        if ($this->status !== null) {
            $buffer .= " {$this->status}";
        }

        $buffer .= "\r\n";

        foreach ($this->keyvals as $key => $vals) {
            foreach ($vals as $val) {
                /** @phpstan-ignore cast.string */
                $val = (string) $val; // TODO remove cast to string
                $buffer .= "{$key}: {$val}\r\n";
            }
        }

        return "{$buffer}\r\n";
    }

    public function getIterator(): \Traversable
    {
        yield from $this->keyvals;
    }

    private static function parseStatus(string $line): ?int
    {
        $chunks = explode(' ', $line);
        if (\count($chunks) === 2) {
            return (int) $chunks[1];
        }

        return null;
    }
}
