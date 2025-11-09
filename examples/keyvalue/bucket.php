<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new KeyValue\BucketConfig('profiles'));

$kv->put('user.1', 'John');
dump($kv->get('user.1'));

$kv->delete('user.1');

dump($kv->get('user.1'));
