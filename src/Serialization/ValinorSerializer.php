<?php

declare(strict_types=1);

namespace Thesis\Nats\Serialization;

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Cache\FileWatchingCache;
use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;

/**
 * @api
 */
final class ValinorSerializer implements Serializer
{
    private readonly TreeMapper $mapper;

    /**
     * @param ?non-empty-string $cachePath
     */
    public function __construct(?string $cachePath = null, bool $debug = false)
    {
        $builder = (new MapperBuilder())
            ->allowSuperfluousKeys();

        if ($cachePath !== null) {
            $cache = new FileSystemCache($cachePath);

            if ($debug) {
                $cache = new FileWatchingCache($cache);
            }

            $builder = $builder->withCache($cache);
        }

        $this->mapper = $builder->mapper();
    }

    public function deserialize(string $class, string $data): object
    {
        return $this->mapper->map($class, Source::json($data)->camelCaseKeys());
    }
}
