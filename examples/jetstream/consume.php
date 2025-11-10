<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Nats\JetStream\ConsumeConfig;
use Thesis\Time\TimeSpan;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$js->deleteStreams(...$js->streamNames('events.*'));

$stream = $js->createStream(new Api\StreamConfig(
    name: 'EventsStream',
    description: 'Testing Stream',
    subjects: ['events.*'],
));

$consumer = $stream->createConsumer(new Api\ConsumerConfig(durableName: 'EventsConsumer', ackPolicy: Api\AckPolicy::Explicit));

$subscription = $consumer->pull(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
    config: new ConsumeConfig(
        batch: 2,
        heartbeat: TimeSpan::fromSeconds(5),
    ),
);

for ($i = 0; $i < 10; ++$i) {
    $response = $js->publish(
        subject: 'events.activated',
        message: new Nats\Message(
            payload: "Message#{$i}",
            headers: (new Nats\Headers())
                ->with(Nats\Header\MsgId::header(), "id:{$i}"),
        ),
    );

    dump($response->seq);
}

trapSignal([\SIGINT, \SIGTERM]);

$subscription->stop();
$subscription->awaitCompletion();

$nc->disconnect();
