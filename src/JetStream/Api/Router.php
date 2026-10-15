<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @internal
 */
final readonly class Router
{
    public const string DEFAULT_PREFIX = '$JS.API.';

    /** @var non-empty-string */
    public string $prefix;

    /**
     * @param ?non-empty-string $domain
     */
    public function __construct(?string $domain = null)
    {
        $this->prefix = $domain !== null ? "\$JS.{$domain}.API." : self::DEFAULT_PREFIX;
    }

    /**
     * @param non-empty-string $endpoint
     * @return non-empty-string
     */
    public function route(string $endpoint): string
    {
        return "{$this->prefix}{$endpoint}";
    }
}
