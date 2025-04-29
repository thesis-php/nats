<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 * @template TValue = mixed
 * @template-implements \IteratorAggregate<non-empty-string, list<TValue>>
 */
final class Headers implements \IteratorAggregate
{
    private const PREFIX = "NATS/1.0\r\n";

    /**
     * @param array<non-empty-string, list<TValue>> $keyvals
     */
    public function __construct(
        public readonly array $keyvals = [],
    ) {}

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

        $encoded = substr($encoded, \strlen(self::PREFIX));
        $headers = [];

        foreach (explode("\r\n", trim($encoded)) as $line) {
            $keypair = explode(': ', $line);
            if (\count($keypair) !== 2) {
                throw new \InvalidArgumentException(\sprintf('Invalid msg header line "%s" received.', $line));
            }

            [$key, $value] = $keypair;

            if ($key !== '') {
                $headers[$key][] = $value;
            }
        }

        return new self($headers);
    }

    /**
     * @return non-empty-string
     */
    public function encode(): string
    {
        $buffer = self::PREFIX;

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
}
