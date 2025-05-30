<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Nats\Exception\HeadersIsInvalid;
use Thesis\Nats\Exception\MessageNotFound;
use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\JetStream;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final readonly class Stream
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public Api\StreamInfo $info,
        public string $name,
        private JetStream $js,
    ) {}

    /**
     * @throws NatsException
     */
    public function actualInfo(): Api\StreamInfo
    {
        return $this->js->streamInfo($this->name);
    }

    /**
     * @throws NatsException
     */
    public function delete(): Api\StreamDeleted
    {
        return $this->js->deleteStream($this->name);
    }

    /**
     * @param ?non-negative-int $sequence
     * @param ?non-negative-int $keep
     * @throws NatsException
     */
    public function purge(
        ?int $sequence = null,
        ?int $keep = null,
        ?string $subject = null,
    ): Api\StreamPurged {
        return $this->js->purgeStream(
            name: $this->name,
            sequence: $sequence,
            keep: $keep,
            subject: $subject,
        );
    }

    /**
     * @throws NatsException
     */
    public function createConsumer(Api\ConsumerConfig $config = new Api\ConsumerConfig()): Consumer
    {
        return $this->js->createConsumer($this->name, $config);
    }

    /**
     * @throws NatsException
     */
    public function createOrUpdateConsumer(Api\ConsumerConfig $config = new Api\ConsumerConfig()): Consumer
    {
        return $this->js->createOrUpdateConsumer($this->name, $config);
    }

    /**
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function deleteConsumer(string $consumer): Api\ConsumerDeleted
    {
        return $this->js->deleteConsumer($this->name, $consumer);
    }

    /**
     * @param non-negative-int $seq
     * @param ?non-empty-string $subject
     */
    public function getMessage(int $seq, ?string $subject = null): ?Message
    {
        try {
            return $this->doGetMessage(
                seq: $seq,
                nextBySubject: $subject,
            );
        } catch (MessageNotFound) {
            return null;
        }
    }

    /**
     * @param non-empty-string $subject
     */
    public function getLastMessageForSubject(string $subject): ?Message
    {
        try {
            return $this->doGetMessage(
                lastBySubject: $subject,
            );
        } catch (MessageNotFound) {
            return null;
        }
    }

    /**
     * @param non-negative-int $seq
     * @throws NatsException
     */
    public function deleteMessage(int $seq): Api\MessageDeleted
    {
        return $this->js->request(new Api\DeleteMessageRequest(
            stream: $this->name,
            seq: $seq,
            noErase: true,
        ));
    }

    /**
     * @param non-negative-int $seq
     * @throws NatsException
     */
    public function secureDeleteMessage(int $seq): Api\MessageDeleted
    {
        return $this->js->request(new Api\DeleteMessageRequest(
            stream: $this->name,
            seq: $seq,
        ));
    }

    /**
     * @param ?non-negative-int $seq
     * @param ?non-empty-string $nextBySubject
     * @param ?non-empty-string $lastBySubject
     * @throws NatsException
     */
    private function doGetMessage(
        ?int $seq = null,
        ?string $nextBySubject = null,
        ?string $lastBySubject = null,
    ): Message {
        if ($this->info->config->allowDirect) {
            [$endpoint, $req] = match (true) {
                $lastBySubject !== null => [
                    Api\ApiMethod::DirectMsgGetLastBySubject->compile($this->name, $lastBySubject),
                    null,
                ],
                default => [
                    Api\ApiMethod::DirectMsgGet->compile($this->name),
                    new Api\GetMessageRequest(
                        stream: $this->name,
                        seq: $seq,
                        nextBySubject: $nextBySubject,
                    ),
                ],
            };

            return $this->js->rawRequest($endpoint, $req);
        }

        $response = $this->js->request(new Api\GetMessageRequest(
            stream: $this->name,
            seq: $seq,
            lastBySubject: $lastBySubject,
            nextBySubject: $nextBySubject,
        ));

        $headers = new Headers();

        if ($response->message->hdrs !== null) {
            $headers = $headers->merge(Protocol\decodeHeaders(
                base64_decode($response->message->hdrs, true) ?: throw new HeadersIsInvalid(),
            ));
        }

        $headers = $headers
            ->with(Header\Stream::header(), $this->name)
            ->with(Header\Subject::header(), $response->message->subject)
            ->with(Header\Sequence::header(), $response->message->seq)
            ->with(Header\Timestamp::Header, $response->message->time);

        return new Message(
            payload: $response->message->data !== null ? (base64_decode($response->message->data, true) ?: null) : null,
            headers: $headers,
        );
    }
}
