<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Counter;

use Amp\Pipeline;
use Thesis\Nats\Header\Incr;
use Thesis\Nats\Header\Subject;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\Message;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class CounterStore
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $prefix
     * @param \Closure(non-empty-string): non-empty-string $publishPrefix
     */
    public function __construct(
        public string $name,
        private JetStream $js,
        private JetStream\Stream $stream,
        private string $prefix,
        private \Closure $publishPrefix,
    ) {}

    /**
     * @param non-empty-string $subject
     */
    public function add(string $subject, int $value): int
    {
        $ack = $this->js->publish(
            ($this->publishPrefix)($subject),
            Message::incr($value),
        );

        return (int) $ack->val;
    }

    /**
     * @param non-empty-string $subject
     */
    public function get(string $subject): ?Entry
    {
        $message = $this->stream->getLastMessageForSubject("{$this->prefix}{$subject}");

        if ($message !== null) {
            $subject = $message->headers?->get(Subject::header());
            if ($subject === null || $subject === '') {
                throw new \LogicException('Message has no subject.');
            }

            return $this->entryFromMessage(
                $message,
                $this->normalizeSubject($subject),
            );
        }

        return null;
    }

    /**
     * @param ?list<non-empty-string> $subjects
     * @return iterable<Entry>
     */
    public function getMultiple(?array $subjects = []): iterable
    {
        if ($subjects === null || $subjects === []) {
            $subjects = ['>'];
        }

        $subjects = array_map(
            fn(string $subject): string => "{$this->prefix}{$subject}",
            $subjects,
        );

        $consumer = $this->stream->createOrUpdateConsumer(new Api\ConsumerConfig(
            deliverPolicy: DeliverPolicy::LastPerSubject,
            ackPolicy: AckPolicy::None,
            replayPolicy: ReplayPolicy::Instant,
            filterSubjects: $subjects,
        ));

        /** @var Pipeline\Queue<Entry> $queue */
        $queue = new Pipeline\Queue(1_000);

        $subscription = $consumer->consume(
            function (JetStream\Delivery $delivery, Subscription $subscription) use ($queue): void {
                $queue->push(
                    $this->entryFromMessage($delivery->message, $this->normalizeSubject($delivery->subject)),
                );

                if ($delivery->metadata->pending === 0) {
                    $subscription->stop();
                }
            },
            new JetStream\ConsumeConfig(
                expires: TimeSpan::fromSeconds(0),
                batch: 1_000,
                noWait: true,
                completeOnNoMessages: true,
            ),
        );

        $subscription->onComplete($queue->complete(...));

        return $queue->iterate();
    }

    /**
     * @param non-empty-string $subject
     */
    private function entryFromMessage(
        Message $message,
        string $subject,
    ): Entry {
        /** @var array{val?: numeric-string} $counter */
        $counter = json_decode($message->payload ?? '{}', true);

        return new Entry(
            subject: $subject,
            value: (int) ($counter['val'] ?? 0),
            incr: $message->headers?->get(Incr::Header) ?? 0,
        );
    }

    /**
     * @param non-empty-string $subject
     * @return non-empty-string
     */
    private function normalizeSubject(string $subject): string
    {
        /** @var non-empty-string */
        return substr($subject, \strlen($this->prefix));
    }
}
