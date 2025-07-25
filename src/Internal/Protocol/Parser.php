<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Amp\Parser\Parser as ProtocolParser;

/**
 * @internal
 */
final readonly class Parser
{
    private const string CRLF = "\r\n";
    private const string OP_OK = '+';
    private const string OP_ERR = '-';
    private const string OP_INFO = 'I';
    private const string OP_PING_PONG = 'P';
    private const string OP_MSG = 'M';
    private const string OP_HMSG = 'H';

    private ProtocolParser $parser;

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
            self::OP_MSG => yield from self::parseMsg(substr($payload, 3)),
            self::OP_HMSG => yield from self::parseHMsg(substr($payload, 4)),
            default => throw new \UnexpectedValueException("Unknown frame '{$payload}'."),
        };
    }

    /**
     * @return \Generator<int, int|string, string, Msg>
     */
    private static function parseMsg(string $payload): \Generator
    {
        $chunks = explode(' ', $payload);
        $size = \count($chunks);

        $subject = $chunks[0] ?: throw new \UnexpectedValueException('msg must contain subject.');
        $sid = ($chunks[1] ?? '') ?: throw new \UnexpectedValueException('msg must contain sid.');

        /** @var ?non-empty-string $replyTo */
        $replyTo = $size === 4 ? $chunks[2] : null;

        $length = (int) ($chunks[$size - 1] ?? 0);
        $payload = $length > 0 ? yield $length : null;

        yield self::CRLF;

        return new Msg(
            subject: $subject,
            sid: $sid,
            replyTo: $replyTo,
            message: new Message($payload),
        );
    }

    /**
     * @return \Generator<int, int|string, string, Msg>
     */
    private static function parseHMsg(string $payload): \Generator
    {
        $chunks = explode(' ', $payload);
        $size = \count($chunks);

        $subject = $chunks[0] ?: throw new \UnexpectedValueException('msg must contain subject.');
        $sid = ($chunks[1] ?? '') ?: throw new \UnexpectedValueException('msg must contain sid.');

        /** @var ?non-empty-string $replyTo */
        $replyTo = $size === 5 ? $chunks[2] : null;

        $headersLength = (int) ($chunks[$size - 2] ?? 0);
        $headers = decodeHeaders(yield $headersLength);

        $length = (int) ($chunks[$size - 1] ?? 0);
        $length -= $headersLength;
        $payload = $length > 0 ? yield $length : null;

        yield self::CRLF;

        return new Msg(
            subject: $subject,
            sid: $sid,
            replyTo: $replyTo,
            message: new Message($payload, $headers),
        );
    }
}
