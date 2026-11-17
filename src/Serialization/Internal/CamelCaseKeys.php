<?php

declare(strict_types=1);

namespace Thesis\Nats\Serialization\Internal;

/**
 * Camel-cases the keys of the data, so that they match the property names, except the keys of the maps:
 * a metadata key `team_name`, a header `X-Kind` or a subject `orders.new_one` must stay as they are.
 * Valinor's own modifier converts every key, and its key converter, which skips maps, needs valinor 2.7.
 *
 * @internal
 * @template-implements \IteratorAggregate<array-key, mixed>
 */
final readonly class CamelCaseKeys implements \IteratorAggregate
{
    /**
     * The properties of the mapped types that are maps: their keys are data, not property names.
     */
    private const array MAPS = [
        'metadata' => true,
        'headers' => true,
        'subjects' => true,
        'tiers' => true,
    ];

    /** @var array<mixed> */
    private array $data;

    /**
     * @param iterable<mixed> $data
     */
    public function __construct(iterable $data)
    {
        $this->data = self::convert($data, keepKeys: false);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->data;
    }

    /**
     * @param iterable<mixed> $data
     * @return array<mixed>
     */
    private static function convert(iterable $data, bool $keepKeys): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            \assert(\is_string($key) || \is_int($key));

            if (is_iterable($value)) {
                // The values of a map are converted again: e.g. a tier is an object with properties.
                $value = self::convert($value, keepKeys: \is_string($key) && !$keepKeys && isset(self::MAPS[$key]));
            }

            if (\is_string($key) && !$keepKeys) {
                $key = lcfirst(str_replace([' ', '_', '-'], '', ucwords($key, ' _-')));

                if (\array_key_exists($key, $result)) {
                    continue;
                }
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
