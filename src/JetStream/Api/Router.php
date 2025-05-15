<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @internal
 */
final class Router
{
    /** @var non-empty-string */
    private string $prefix = '$JS.API.';

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
     * @param non-empty-string $endpoint
     * @return non-empty-string
     */
    public function route(string $endpoint): string
    {
        return "{$this->prefix}{$endpoint}";
    }
}
