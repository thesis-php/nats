<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $nc->jetStream();

$js->deleteObjectStore('test');

$os = $js->createOrUpdateObjectStore(new ObjectStore\StoreConfig('test', description: 'test objects'));

$subscription = $os->watch(static function (ObjectStore\ObjectInfo $info): void {
    if ($info->deleted === true) {
        dump("Object '{$info->name}' from bucket '{$info->bucket}' was deleted.");
    } else {
        dump("Object '{$info->name}' in bucket '{$info->bucket}' was uploaded.");
    }
});

$os->put(new ObjectStore\ObjectMeta(name: 'x'), new ObjectStore\StringReader(str_repeat('y', 100)));

$object = $os->get('x');
dump((string) $object);

$os->delete('x');

trapSignal([SIGINT, \SIGTERM]);
$subscription->stop();
$subscription->awaitCompletion();
