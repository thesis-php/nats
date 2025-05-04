<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;

/**
 * @api
 */
final class JetStream
{
    /** @var non-empty-string */
    private string $prefix = '$JS.API.';

    /**
     * @internal
     * @param ?non-empty-string $domain
     */
    public function __construct(
        private readonly Client $client,
        private readonly Serializer $serializer = new ValinorSerializer(),
        ?string $domain = null,
    ) {
        if ($domain !== null) {
            $this->prefix = substr_replace($this->prefix, "\$JS.{$domain}.API.", 0);
        }
    }

    public function accountInfo(): JetStream\AccountInfo
    {
        return $this->doRequest('INFO', JetStream\AccountInfo::class);
    }

    /**
     * @template T of object
     * @param non-empty-string $method
     * @param class-string<T> $classType
     * @return T
     */
    private function doRequest(string $method, string $classType): object
    {
        $response = $this->client->request("{$this->prefix}{$method}");

        return $this->serializer->deserialize($classType, $response->message->payload ?: '{}');
    }
}
