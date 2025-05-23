<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use function Amp\trapSignal;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@localhost:4222'));
$jetstream = $client->jetStream();

foreach ($jetstream->streamNames('events.*') as $streamName) {
    $jetstream->deleteStream($streamName);
}

$stream = $jetstream->createStream(new Nats\JetStream\Api\StreamConfig(
    name: 'EventsStream',
    subjects: ['events.*'],
));

$consumer = $stream->createConsumer(new Nats\JetStream\Api\ConsumerConfig(durableName: 'EventsConsumer', ackPolicy: Nats\JetStream\Api\AckPolicy::Explicit));

$consumer->consume(
    handler: static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
);

for ($i = 0; $i < 10; ++$i) {
    $client->publish('events.activated', new Nats\Message("Message#{$i}"));
}

trapSignal([\SIGINT, \SIGTERM]);

$client->disconnect();
