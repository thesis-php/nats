<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\JetStream\Api;
use Thesis\Nats\JetStream\Api\Result\Result;
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

    public function accountInfo(): Api\AccountInfo
    {
        return $this->request(new Api\GetAccountInfoRequest());
    }

    public function createStream(Api\StreamConfig $config): Api\StreamInfo
    {
        return $this->request(new Api\CreateStreamRequest($config));
    }

    /**
     * @template T
     * @param Api\Request<T> $request
     * @return T
     */
    private function request(Api\Request $request): mixed
    {
        $response = $this->client->request(
            subject: "{$this->prefix}{$request->endpoint()}",
            message: new Message(
                payload: $request->payload() !== null ? json_encode($request->payload(), flags: JSON_THROW_ON_ERROR) : null,
            ),
        );

        /** @var array<non-empty-string, mixed> $responsePayload */
        $responsePayload = json_decode($response->message->payload ?? '{}', associative: true, flags: JSON_THROW_ON_ERROR);
        if (!isset($responsePayload['error'])) {
            $responsePayload['response'] = $responsePayload;
        }

        /** @var Result<T> $result */
        $result = $this->serializer->deserialize(Result::type($request->type()), $responsePayload);
        $result->error?->throw();

        return $result->response ?? throw new \RuntimeException('Empty response was not expected.');
    }
}
