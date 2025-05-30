<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @internal
 */
final class Router
{
    public const string DEFAULT_PREFIX = '$JS.API.';

    /** @var non-empty-string */
    private string $prefix = self::DEFAULT_PREFIX;

    /**
     * @param ?non-empty-string $domain
     */
    public function __construct(?string $domain = null)
    {
        if ($domain !== null) {
            $this->prefix = substr_replace($this->prefix, "\$JS.{$domain}.API.", 0);
        }
    }

    /**
     * @return non-empty-string
     */
    public function prefix(): string
    {
        return $this->prefix;
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
