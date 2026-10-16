<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * The options of a connection uri. An option that is absent or has a value of the wrong type is null,
 * so the default of the config applies.
 *
 * @internal
 */
final readonly class Query
{
    /**
     * @param ?float $connectionTimeout positive and finite
     * @param ?float $requestTimeout positive and finite
     * @param ?int $ping any integer, the config decides what a negative one means
     * @param ?positive-int $maxPings
     * @param ?non-empty-string $jetStreamDomain
     * @param ?non-empty-string $clientName
     * @param ?non-empty-string $jwt
     * @param ?non-empty-string $nkey
     */
    private function __construct(
        public ?bool $verbose = null,
        public ?bool $pedantic = null,
        public ?bool $tcpNoDelay = null,
        public ?bool $noResponders = null,
        public ?float $connectionTimeout = null,
        public ?float $requestTimeout = null,
        public ?int $ping = null,
        public ?int $maxPings = null,
        public ?string $jetStreamDomain = null,
        public ?string $clientName = null,
        #[\SensitiveParameter]
        public ?string $jwt = null,
        #[\SensitiveParameter]
        public ?string $nkey = null,
    ) {}

    public static function parse(#[\SensitiveParameter] string $query): self
    {
        parse_str($query, $options);

        return new self(
            verbose: self::bool($options, 'verbose'),
            pedantic: self::bool($options, 'pedantic'),
            tcpNoDelay: self::bool($options, 'tcp_nodelay'),
            noResponders: self::bool($options, 'no_responders'),
            connectionTimeout: self::duration($options, 'connection_timeout'),
            requestTimeout: self::duration($options, 'request_timeout'),
            ping: self::int($options, 'ping'),
            maxPings: self::positiveInt($options, 'max_pings'),
            jetStreamDomain: self::string($options, 'jetstream_domain'),
            clientName: self::string($options, 'client_name'),
            jwt: self::string($options, 'jwt'),
            nkey: self::string($options, 'nkey'),
        );
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function bool(array $options, string $name): ?bool
    {
        if (!isset($options[$name])) {
            return null;
        }

        return filter_var($options[$name], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function int(array $options, string $name): ?int
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);

        return $value !== false ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $options
     * @return ?positive-int
     */
    private static function positiveInt(array $options, string $name): ?int
    {
        $value = self::int($options, $name);

        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function duration(array $options, string $name): ?float
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_FLOAT);

        return $value !== false && is_finite($value) && $value > 0 ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $options
     * @return ?non-empty-string
     */
    private static function string(#[\SensitiveParameter] array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
