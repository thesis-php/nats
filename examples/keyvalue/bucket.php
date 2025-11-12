<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new KeyValue\BucketConfig('profiles'));

$subscription = $kv->watch(static function (KeyValue\Entry $entry): void {
    if ($entry->state === KeyValue\EntryState::Created) {
        dump("Entry '{$entry->key}' in kv '{$entry->bucket}' was inserted.");
    } else {
        dump("Entry '{$entry->key}' from kv '{$entry->bucket}' was deleted.");
    }
});

$kv->put('user.1', 'John');

dump($kv->get('user.1')?->value);

$kv->delete('user.1');

trapSignal([\SIGINT, \SIGTERM]);
$subscription->stop();
$subscription->awaitCompletion();
