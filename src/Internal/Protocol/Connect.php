<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Connect implements \JsonSerializable
{
    /**
     * @param bool $verbose turns on +OK protocol acknowledgements
     * @param bool $pedantic turns on additional strict format checking, e.g. for properly formed subjects
     * @param bool $tlsRequired indicates whether the client requires an SSL connection
     * @param non-empty-string $name client name
     * @param non-empty-string $lang the implementation language of the client
     * @param non-empty-string $version the version of the client
     * @param ?non-empty-string $authToken client authorization token
     * @param ?non-empty-string $user connection username
     * @param ?non-empty-string $pass connection password
     * @param ?Proto $protocol sending 0 (or absent) indicates client supports original protocol. Sending 1 indicates that the client supports dynamic reconfiguration of cluster topology changes by asynchronously receiving INFO messages with known servers it can reconnect to
     * @param ?bool $echo if set to false, the server (version 1.2.0+) will not send originating messages from this connection to its own subscriptions. Clients should set this to false only for server supporting this feature, which is when proto in the INFO protocol is set to at least 1
     * @param ?string $sig in case the server has responded with a nonce on INFO, then a NATS client must use this field to reply with the signed nonce
     * @param ?string $jwt the JWT that identifies a user permissions and account
     * @param ?bool $noResponders enable quick replies for cases where a request is sent to a topic with no responders
     * @param ?bool $headers whether the client supports headers
     * @param ?string $nkey the public NKey to authenticate the client. This will be used to verify the signature (sig) against the nonce provided in the INFO message
     */
    public function __construct(
        public readonly bool $verbose,
        public readonly bool $pedantic,
        public readonly bool $tlsRequired,
        public readonly string $name,
        public readonly string $lang,
        public readonly string $version,
        public readonly ?string $authToken = null,
        public readonly ?string $user = null,
        public readonly ?string $pass = null,
        public readonly ?Proto $protocol = null,
        public readonly ?bool $echo = null,
        public readonly ?string $sig = null,
        public readonly ?string $jwt = null,
        public readonly ?bool $noResponders = null,
        public readonly ?bool $headers = null,
        public readonly ?string $nkey = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'verbose' => $this->verbose,
                'pedantic' => $this->pedantic,
                'tls_required' => $this->tlsRequired,
                'name' => $this->name,
                'lang' => $this->lang,
                'version' => $this->version,
                'auth_token' => $this->authToken,
                'user' => $this->user,
                'pass' => $this->pass,
                'protocol' => $this->protocol?->value,
                'echo' => $this->echo,
                'sig' => $this->sig,
                'jwt' => $this->jwt,
                'no_responders' => $this->noResponders,
                'headers' => $this->headers,
                'nkey' => $this->nkey,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
