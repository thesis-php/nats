<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();

$object = $jetstream->createOrUpdateObjectStore(new Nats\JetStream\ObjectStore\StoreConfig('images'));
