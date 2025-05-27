<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Time\TimeSpan;
use function Amp\trapSignal;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@localhost:4222?no_responders=true'));
$jetstream = $client->jetStream();

foreach ($jetstream->streamNames('events.*') as $streamName) {
    $jetstream->deleteStream($streamName);
}

$stream = $jetstream->createStream(new Nats\JetStream\Api\StreamConfig(
    name: 'EventsStream',
    subjects: ['events.*'],
    duplicateWindow: TimeSpan::fromSeconds(20),
    allowMessageTtl: true,
));

$consumer = $stream->createConsumer(new Nats\JetStream\Api\ConsumerConfig(durableName: 'EventsConsumer', ackPolicy: Nats\JetStream\Api\AckPolicy::Explicit));

$consumer->consume(
    handler: static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
);

for ($i = 0; $i < 10; ++$i) {
    $response = $jetstream->publish(
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

$client->disconnect();
