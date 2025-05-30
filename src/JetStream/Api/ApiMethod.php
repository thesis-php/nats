<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @internal
 */
enum ApiMethod: string
{
    case Info = 'INFO';
    case StreamCreate = 'STREAM.CREATE.%s';
    case UpdateStream = 'STREAM.UPDATE.%s';
    case DeleteStream = 'STREAM.DELETE.%s';
    case PurgeStream = 'STREAM.PURGE.%s';
    case StreamInfo = 'STREAM.INFO.%s';
    case StreamList = 'STREAM.LIST';
    case StreamMsgGet = 'STREAM.MSG.GET.%s';
    case DirectMsgGet = 'DIRECT.GET.%s';
    case DirectMsgGetLastBySubject = 'DIRECT.GET.%s.%s';
    case StreamNames = 'STREAM.NAMES';
    case CreateConsumer = 'CONSUMER.CREATE.%s.%s';
    case DeleteConsumer = 'CONSUMER.DELETE.%s.%s';
    case ConsumerInfo = 'CONSUMER.INFO.%s.%s';
    case ConsumerList = 'CONSUMER.LIST.%s';
    case ConsumerNames = 'CONSUMER.NAMES.%s';
    case PauseConsumer = 'CONSUMER.PAUSE.%s.%s';
    case UnpinConsumer = 'CONSUMER.UNPIN.%s.%s';
    case ConsumerMessageNext = 'CONSUMER.MSG.NEXT.%s.%s';

    /**
     * @return non-empty-string
     */
    public function compile(string ...$vals): string
    {
        /** @var non-empty-string */
        return \sprintf($this->value, ...$vals);
    }
}
