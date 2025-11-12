<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/strings.php';

use Thesis\Nats;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));

$nc->subscribe('words.*', static function (Nats\Delivery $delivery): void {
    $consonants = countConsonants($delivery->message->payload ?? '');

    dump("the word {$delivery->message->payload} contains '{$consonants}' consonants");
});

$nc->subscribe('words.*', static function (Nats\Delivery $delivery): void {
    $vowels = countVowels($delivery->message->payload ?? '');

    dump("the word {$delivery->message->payload} contains '{$vowels}' vowels");
});

$nc->subscribe('words.*', static function (Nats\Delivery $delivery): void {
    $digits = countDigits($delivery->message->payload ?? '');

    dump("the word {$delivery->message->payload} contains '{$digits}' digits");
});

for ($i = 0; $i < 10_000; ++$i) {
    $nc->publish("words.{$i}", new Nats\Message(randomString()));
}

trapSignal([\SIGINT, \SIGTERM]);

$nc->stop();
