<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Mapping;

/**
 * @internal
 * @template-implements \IteratorAggregate<non-empty-string, mixed>
 */
final readonly class FixStructureSource implements \IteratorAggregate
{
    /** @var array<non-empty-string, mixed> */
    private array $data;

    /**
     * @param array<non-empty-string, mixed> $data
     */
    public function __construct(array $data)
    {
        if (!isset($data['error'])) {
            $data['response'] = $data;
        }

        $this->data = $data;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->data;
    }
}
