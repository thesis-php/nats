<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Counter\CounterConfig;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();

$jetstream->deleteCounter('atomics');

$counter = $jetstream->createOrUpdateCounter(new CounterConfig(
    name: 'atomics',
));

dump($counter->add('x', 1));
dump($counter->add('x', 2));
dump($counter->add('x', 3));
dump($counter->get('x'));
