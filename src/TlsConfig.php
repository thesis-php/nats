<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * The TLS options of the connection. With them the client requires TLS and fails to connect to a server that does not offer it;
 * without them the connection is still upgraded to TLS, with the default options, when the server requires it.
 *
 * @api
 */
final readonly class TlsConfig
{
    /**
     * @param ?non-empty-string $caFile the path to the PEM bundle of the certificate authorities that sign the server certificate,
     *                                  the system ones are used without it
     * @param ?non-empty-string $certFile the path to the PEM client certificate, for the servers that verify the clients;
     *                                    it may hold the private key too
     * @param ?non-empty-string $keyFile the path to the PEM private key of the client certificate, if the certificate file does not hold it
     * @param ?non-empty-string $keyPassphrase the passphrase of an encrypted private key
     * @param bool $verifyPeer whether the server certificate and its name are verified; turning it off is meant for testing only
     * @param ?non-empty-string $serverName the name the server certificate is verified against and sent in SNI,
     *                                      the host of the server being connected to without it
     * @param bool $handshakeFirst whether the TLS handshake comes before the server's INFO, for the servers
     *                             configured with `handshake_first`; otherwise the connection is upgraded after INFO
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public ?string $caFile = null,
        public ?string $certFile = null,
        public ?string $keyFile = null,
        #[\SensitiveParameter]
        public ?string $keyPassphrase = null,
        public bool $verifyPeer = true,
        public ?string $serverName = null,
        public bool $handshakeFirst = false,
    ) {
        if ($certFile === null && $keyFile !== null) {
            throw new \InvalidArgumentException('A private key file requires a certificate file.');
        }

        if ($certFile === null && $keyPassphrase !== null) {
            throw new \InvalidArgumentException('A private key passphrase requires a certificate file.');
        }
    }
}
