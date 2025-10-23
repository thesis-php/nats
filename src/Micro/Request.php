<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Thesis\Nats\Delivery;
use Thesis\Nats\Headers;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;

/**
 * @api
 */
final readonly class Request
{
    public ?string $data;

    public Headers $headers;

    /** @var non-empty-string */
    public string $subject;

    /** @var ?non-empty-string */
    public ?string $reply;

    public function __construct(
        private Delivery $delivery,
        private Encoder $encoder,
    ) {
        $this->data = $this->delivery->message->payload;
        $this->headers = $this->delivery->message->headers ?? new Headers();
        $this->subject = $this->delivery->subject;
        $this->reply = $this->delivery->replyTo;
    }

    public function respond(Response $response, ?Cancellation $cancellation = null): void
    {
        $this->delivery->reply(new Message($response->data, $response->headers), $cancellation);
    }

    public function respondJson(mixed $response, ?Cancellation $cancellation = null): void
    {
        $this->delivery->reply(
            new Message($this->encoder->encode($response)),
            $cancellation,
        );
    }
}
