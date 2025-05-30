<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;

$date = new DateTimeImmutable();

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$jetstream = $client->jetStream();

foreach ($jetstream->streamNames('events.*') as $streamName) {
    $jetstream->deleteStream($streamName);
}

$stream = $jetstream->createStream(new Nats\JetStream\Api\StreamConfig(
    name: 'EventsStream',
    description: 'Testing Stream',
    subjects: ['events.*'],
));

$jetstream->publish(
    subject: 'events.activated',
    message: new Nats\Message(
        payload: 'Message',
        headers: (new Nats\Headers())
            ->with(Nats\Header\ScalarKey::string('x'), 'y'),
    ),
);

dump($stream->getMessage(1));
