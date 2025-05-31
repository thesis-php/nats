<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();

$kv = $jetstream->createOrUpdateKeyValue(new Nats\JetStream\KeyValue\BucketConfig('profiles'));

$kv->put('users.kafkiansky', '{"role": "developer"}');
dump($kv->get('users.kafkiansky'));

$kv->delete('users.kafkiansky');

dump($kv->get('users.kafkiansky'));
