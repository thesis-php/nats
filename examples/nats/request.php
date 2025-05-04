<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/strings.php';

use Thesis\Nats;
use function Amp\async;
use function Amp\Future\await;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@localhost:4222?no_responders=true'));

$client->subscribe('words.*', static function (Nats\Delivery $delivery): void {
    $delivery->reply(new Nats\Message(strrev($delivery->message->payload ?: '')));
});

$futures = [];

$start = microtime(true);

for ($i = 0; $i < 10_000; ++$i) {
    $futures[] = async(
        static fn(): string => $client
            ->request("words.{$i}", new Nats\Message("{$i}:" . randomString()))
            ->message
            ->payload ?: '',
    );
}

dump(await($futures));

dump(sprintf('elapsed: %ss', microtime(true) - $start));

$client->disconnect();
