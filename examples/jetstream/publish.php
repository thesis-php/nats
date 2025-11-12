<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$stream = $js->createOrUpdateStream(new Api\StreamConfig(
    name: 'EventsStream',
    description: 'Testing Stream',
    subjects: ['events.*'],
));

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

$nc->stop();
