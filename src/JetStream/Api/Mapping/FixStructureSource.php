<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Mapping;

use Thesis\Nats\JetStream\Api\StreamConfig;

/**
 * @internal
 * @template-implements \IteratorAggregate<non-empty-string, mixed>
 */
final readonly class FixStructureSource implements \IteratorAggregate
{
    /**
     * The keys of a stream config the server sends that differ from the property names of {@see StreamConfig}.
     */
    private const array STREAM_CONFIG_KEYS = [
        'max_msgs' => 'max_messages',
        'max_msgs_per_subject' => 'max_messages_per_subject',
        'max_msg_size' => 'max_message_size',
        'storage' => 'storage_type',
        'num_replicas' => 'replicas',
        'allow_rollup_hdrs' => 'allow_rollup',
        'allow_msg_ttl' => 'allow_message_ttl',
        'allow_msg_counter' => 'allow_message_counter',
        'allow_atomic' => 'allow_atomic_publish',
        'republish' => 're_publish',
    ];

    /** @var array<non-empty-string, mixed> */
    private array $data;

    /**
     * @param array<non-empty-string, mixed> $data
     */
    public function __construct(array $data)
    {
        /** @var array<non-empty-string, mixed> $data */
        $data = self::fixStreamConfigs($data);

        if (!isset($data['error'])) {
            $data['response'] = $data;
        }

        $this->data = $data;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->data;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function fixStreamConfigs(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            $value = self::fixStreamConfigs($value);

            // A stream config always has the retention policy, a consumer config never does.
            if ($key === 'config' && isset($value['retention'])) {
                $value = self::renameStreamConfigKeys($value);
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param array<mixed> $config
     * @return array<mixed>
     */
    private static function renameStreamConfigKeys(array $config): array
    {
        $renamed = [];

        foreach ($config as $key => $value) {
            $renamed[\is_string($key) ? self::STREAM_CONFIG_KEYS[$key] ?? $key : $key] = $value;
        }

        return $renamed;
    }
}
