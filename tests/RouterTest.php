<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\JetStream\Api\Router;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    /**
     * @param non-empty-string $endpoint
     * @param non-empty-string $route
     * @param ?non-empty-string $domain
     */
    #[TestWith(['INFO', '$JS.API.INFO'])]
    #[TestWith(['INFO', '$JS.test.API.INFO', 'test'])]
    public function testRoute(string $endpoint, string $route, ?string $domain = null): void
    {
        $router = new Router($domain);

        self::assertSame($route, $router->route($endpoint));
    }
}
