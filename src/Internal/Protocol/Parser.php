<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Amp\Parser\Parser as ProtocolParser;

/**
 * @internal
 */
final class Parser
{
    private const CRLF = "\r\n";
    private const OP_OK = '+';
    private const OP_ERR = '-';
    private const OP_INFO = 'I';
    private const OP_PING_PONG = 'P';
    private const OP_MSG = 'M';
    private const OP_HMSG = 'H';

    private readonly ProtocolParser $parser;

    /**
     * @param \Closure(Frame): void $push
     */
    public function __construct(\Closure $push)
    {
        $this->parser = new ProtocolParser(self::parser($push));
    }

    public function push(string $bytes): void
    {
        $this->parser->push($bytes);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    /**
     * @param \Closure(Frame): void $push
     * @return \Generator<int, int|string, string, void>
     */
    private static function parser(\Closure $push): \Generator
    {
        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $push(yield from self::parseFrame(yield 1, yield self::CRLF));
        }
    }

    /**
     * @return \Generator<int, int|string, string, Frame>
     */
    private static function parseFrame(string $type, string $payload): \Generator
    {
        return match ($type) {
            self::OP_OK => Ok::Frame,
            self::OP_ERR => new Err(substr($payload, 4) ?: 'unknown'),
            self::OP_INFO => ServerInfo::fromJson(substr($payload, 4) ?: '{}'),
            self::OP_PING_PONG => $payload === 'ING' ? Ping::Frame : Pong::Frame,
            self::OP_MSG => yield from self::parseMessage(substr($payload, 3)),
            self::OP_HMSG => yield from self::parseMessage(substr($payload, 4), withHeaders: true),
            default => throw new \UnexpectedValueException("Unknown frame '{$payload}'."),
        };
    }

    /**
     * @return \Generator<int, int|string, string, Message>
     */
    private static function parseMessage(string $payload, bool $withHeaders = false): \Generator
    {
        if ($payload === '') {
            throw new \UnexpectedValueException('msg cannot be empty.');
        }

        $chunks = explode(' ', $payload);
        $size = \count($chunks);

        $subject = $chunks[0] ?: throw new \UnexpectedValueException('msg must contain subject.');
        $sid = ($chunks[1] ?? '') ?: throw new \UnexpectedValueException('msg must contain sid.');
        $replyTo = null;

        if ((!$withHeaders && $size === 4) || ($withHeaders && $size === 5)) {
            $replyTo = $chunks[2] ?: null;
        }

        $length = (int) ($chunks[$size - 1] ?? 0);

        /** @var array<non-empty-string, non-empty-string> $headers */
        $headers = [];

        if ($withHeaders) {
            $headersLength = (int) ($chunks[$size - 2] ?? 0);
            $headers = [...self::parseHeaders(yield $headersLength)];
            $length -= $headersLength;
        }

        /** @var ?non-empty-string $payload */
        $payload = match ($length) {
            0 => null,
            default => yield $length,
        };

        yield self::CRLF;

        return new Message(
            subject: $subject,
            sid: $sid,
            replyTo: $replyTo,
            payload: $payload,
            headers: $headers,
        );
    }

    /**
     * @return iterable<non-empty-string, non-empty-string>
     */
    private static function parseHeaders(string $value): iterable
    {
        foreach (explode(self::CRLF, trim($value)) as $item) {
            $keypair = explode(': ', $item);
            if (\count($keypair) === 2) {
                [$key, $value] = $keypair;

                if ($key !== '' && $value !== '') {
                    yield $key => $value;
                }
            }
        }
    }
}
