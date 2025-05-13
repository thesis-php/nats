<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Thesis\Nats\Exception;

/**
 * @internal
 */
final readonly class Err implements Frame
{
    /** @var non-empty-string */
    public string $message;

    /**
     * @param non-empty-string $message
     */
    public function __construct(string $message)
    {
        $this->message = trim($message, '\'') ?: 'unknown';
    }

    public function encode(): string
    {
        return "-ERR '{$this->message}'\r\n";
    }

    public function toException(): \Exception
    {
        return match (true) {
            str_starts_with($this->message, 'Unknown Protocol Operation') => new Exception\UnknownProtocolOperation(),
            str_starts_with($this->message, 'Authorization Violation') => new Exception\AuthorizationViolation(),
            str_starts_with($this->message, 'Authorization Timeout') => new Exception\AuthorizationTimeout(),
            str_starts_with($this->message, 'Invalid Client Protocol') => new Exception\InvalidClientProtocol(),
            str_starts_with($this->message, 'Parser Error') => new Exception\ParserError(),
            str_starts_with($this->message, 'Secure Connection - TLS Required') => new Exception\TLSRequired(),
            str_starts_with($this->message, 'Stale Connection') => new Exception\StaleConnection(),
            str_starts_with($this->message, 'Maximum Connections Exceeded') => new Exception\MaximumConnectionsExceeded(),
            str_starts_with($this->message, 'Slow Consumer') => new Exception\SlowConsumer(),
            str_starts_with($this->message, 'Invalid Subject') => new Exception\InvalidSubject(),
            str_starts_with($this->message, 'Permissions Violation') => new Exception\PermissionViolated($this->message),
            default => new \RuntimeException($this->message),
        };
    }
}
