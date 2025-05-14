<?php

declare(strict_types=1);

namespace Thesis\Nats\Serialization;

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Cache\FileWatchingCache;
use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ValinorSerializer implements Serializer
{
    private TreeMapper $mapper;

    /**
     * @param ?non-empty-string $cachePath
     */
    public function __construct(?string $cachePath = null, bool $debug = false)
    {
        $builder = (new MapperBuilder())
            ->allowSuperfluousKeys()
            ->registerConstructor(
                TimeSpan::fromNanoseconds(...),
                static fn(string $date): \DateTimeImmutable => new \DateTimeImmutable($date),
            );

        if ($cachePath !== null) {
            $cache = new FileSystemCache($cachePath);

            if ($debug) {
                $cache = new FileWatchingCache($cache);
            }

            $builder = $builder->withCache($cache);
        }

        $this->mapper = $builder->mapper();
    }

    public function deserialize(string $type, iterable $data): mixed
    {
        return $this->mapper->map(
            signature: $type,
            source: Source::iterable($data)->camelCaseKeys(),
        );
    }
}
