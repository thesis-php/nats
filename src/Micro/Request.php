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

    /**
     * @internal
     */
    public function __construct(
        private Delivery $delivery,
        private Encoder $encoder,
        private Internal\RequestOutcome $outcome = new Internal\RequestOutcome(),
    ) {
        $this->data = $this->delivery->message->payload;
        $this->headers = $this->delivery->message->headers ?? new Headers();
        $this->subject = $this->delivery->subject;
        $this->reply = $this->delivery->replyTo;
    }

    public function respond(Response $response, ?Cancellation $cancellation = null): void
    {
        $this->outcome->markReplied();
        $this->delivery->reply(new Message($response->data, $response->headers), $cancellation);
    }

    public function respondJson(mixed $response, ?Cancellation $cancellation = null): void
    {
        // Encoded first: a response that cannot be encoded is not sent, so the requester still gets the error reply.
        $message = new Message($this->encoder->encode($response));

        $this->outcome->markReplied();
        $this->delivery->reply($message, $cancellation);
    }

    /**
     * Replies with the Nats-Service-Error and Nats-Service-Error-Code headers and counts the request as failed in the endpoint stats.
     *
     * @param non-empty-string $description
     */
    public function error(
        int $code,
        string $description,
        ?string $data = null,
        Headers $headers = new Headers(),
        ?Cancellation $cancellation = null,
    ): void {
        $this->respond(
            new Response($data, $headers)
                ->withErrorCode($code)
                ->withErrorDescription($description),
            $cancellation,
        );

        $this->outcome->fail(new ServiceError($code, $description, $data));
    }
}
