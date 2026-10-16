<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * Parses `[scheme://][user[:password]@]host[:port][,host[:port]...][/][?query]`.
 * Every host may repeat the scheme and the credentials, as nats.go server lists do (`nats://u:p@a:4222,nats://u:p@b:4222`).
 *
 * @internal
 */
final readonly class Uri
{
    private const string DEFAULT_SCHEME = 'nats';
    private const string DEFAULT_HOST = '127.0.0.1';
    private const int DEFAULT_PORT = 4222;

    /** @var list<non-empty-string> */
    private const array SCHEMES = ['nats', 'tcp'];

    /** @var list<non-empty-string> */
    private const array UNSUPPORTED_SCHEMES = ['tls', 'ws', 'wss'];

    /**
     * @param non-empty-list<non-empty-string> $urls in the `host:port` form, an IPv6 host in brackets
     * @param ?non-empty-string $user
     * @param ?non-empty-string $password
     */
    private function __construct(
        public array $urls,
        public ?string $user,
        public ?string $password,
        public Query $query,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(#[\SensitiveParameter] string $uri): self
    {
        $rest = trim($uri);

        $fragment = strpos($rest, '#');
        if ($fragment !== false) {
            $rest = substr($rest, 0, $fragment);
        }

        [$rest, $options] = self::split($rest, '?');
        $query = Query::parse($options);

        $scheme = null;
        $credentials = null;
        $urls = [];

        // The list is split first: every server is parsed on its own, so its scheme and credentials
        // cannot be taken for a part of a neighbour (a password with a comma must be percent-encoded).
        $servers = explode(',', $rest);

        foreach ($servers as $server) {
            $server = trim($server);

            [$serverScheme, $server] = self::scheme($server) ?? [$scheme ?? self::DEFAULT_SCHEME, $server];
            if ($scheme !== null && $serverScheme !== $scheme) {
                throw new \InvalidArgumentException("The uri mixes the schemes '{$scheme}' and '{$serverScheme}'.");
            }

            $scheme = $serverScheme;

            $at = strrpos($server, '@');
            if ($at !== false) {
                $serverCredentials = self::credentials(substr($server, 0, $at));
                $server = substr($server, $at + 1);

                // A single user and password are sent to every server: the nats.go form repeats them, any other is refused.
                if ($credentials !== null && $serverCredentials !== $credentials) {
                    throw new \InvalidArgumentException('The servers of the uri must share the same credentials.');
                }

                $credentials = $serverCredentials;
            }

            if (str_ends_with($server, '/')) {
                $server = substr($server, 0, -1);
            }

            // A lone empty server is the default one, as in "nats://", an empty one in a list is likely a typo.
            if ($server === '' && \count($servers) > 1) {
                throw new \InvalidArgumentException('The uri has an empty server in the list.');
            }

            $urls[] = self::server($server);
        }

        [$user, $password] = $credentials ?? [null, null];

        return new self($urls, $user, $password, $query);
    }

    /**
     * @return ?array{non-empty-string, string}
     * @throws \InvalidArgumentException
     */
    private static function scheme(string $uri): ?array
    {
        if (preg_match('/^([a-z][a-z0-9+.-]*):\/\//i', $uri, $matches) !== 1) {
            return null;
        }

        $scheme = strtolower($matches[1]);

        if (\in_array($scheme, self::UNSUPPORTED_SCHEMES, true)) {
            throw new \InvalidArgumentException("The scheme '{$scheme}' is not supported yet.");
        }

        if (!\in_array($scheme, self::SCHEMES, true)) {
            throw new \InvalidArgumentException(\sprintf("The scheme '%s' is unknown, expected one of: %s.", $scheme, implode(', ', self::SCHEMES)));
        }

        // tcp:// is kept for compatibility, both mean a plain connection.
        return [$scheme === 'tcp' ? self::DEFAULT_SCHEME : $scheme, substr($uri, \strlen($matches[0]))];
    }

    /**
     * @return array{?non-empty-string, ?non-empty-string}
     */
    private static function credentials(#[\SensitiveParameter] string $userinfo): array
    {
        [$user, $password] = self::split($userinfo, ':');
        $user = rawurldecode($user);
        $password = rawurldecode($password);

        return [
            $user !== '' ? $user : null,
            $password !== '' ? $password : null,
        ];
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    private static function server(string $server): string
    {
        if (str_starts_with($server, '[')) {
            $end = strpos($server, ']');
            if ($end === false) {
                throw new \InvalidArgumentException("The server '{$server}' has an unterminated IPv6 address.");
            }

            $host = substr($server, 0, $end + 1);
            $port = substr($server, $end + 1);

            if ($port !== '' && !str_starts_with($port, ':')) {
                throw new \InvalidArgumentException("The server '{$server}' is invalid.");
            }

            $port = substr($port, 1);
        } else {
            [$host, $port] = self::split($server, ':');

            if (str_contains($port, ':')) {
                throw new \InvalidArgumentException("The server '{$server}' is invalid: an IPv6 address must be enclosed in brackets.");
            }
        }

        if ($host !== '' && preg_match('/[\/?#@\s]/', $host) === 1) {
            throw new \InvalidArgumentException("The server '{$server}' has an invalid host.");
        }

        return \sprintf('%s:%d', $host === '' ? self::DEFAULT_HOST : $host, self::port($server, $port));
    }

    /**
     * @return int<1, 65535>
     * @throws \InvalidArgumentException
     */
    private static function port(string $server, string $port): int
    {
        if ($port === '') {
            return self::DEFAULT_PORT;
        }

        if (preg_match('/^\d+$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException("The server '{$server}' has an invalid port '{$port}'.");
        }

        return (int) $port;
    }

    /**
     * @param non-empty-string $separator
     * @return array{string, string} the parts before and after the first separator, the second is empty without one
     */
    private static function split(#[\SensitiveParameter] string $value, string $separator): array
    {
        $position = strpos($value, $separator);

        return $position === false
            ? [$value, '']
            : [substr($value, 0, $position), substr($value, $position + \strlen($separator))];
    }
}
