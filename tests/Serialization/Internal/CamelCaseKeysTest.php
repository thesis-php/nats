<?php

declare(strict_types=1);

namespace Thesis\Nats\Serialization\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CamelCaseKeys::class)]
final class CamelCaseKeysTest extends TestCase
{
    public function testKeepsKeysOfMaps(): void
    {
        $data = new CamelCaseKeys([
            'config' => [
                'max_msgs' => 10,
                'subjects' => ['orders.new_one'],
                'metadata' => ['team_name' => 'core', 'Build-Id' => '42', '_nats.level' => '1'],
            ],
            'state' => [
                'num_subjects' => 2,
                'subjects' => ['orders.new_one' => 1, 'orders.old-one' => 2],
            ],
            'headers' => ['X-Kind' => ['text']],
            'tiers' => [
                'R1_tier' => ['max_storage' => 1, 'metadata' => ['a_b' => 'c']],
            ],
            'list' => [['first_key' => 1]],
        ]);

        self::assertSame(
            [
                'config' => [
                    'maxMsgs' => 10,
                    'subjects' => ['orders.new_one'],
                    'metadata' => ['team_name' => 'core', 'Build-Id' => '42', '_nats.level' => '1'],
                ],
                'state' => [
                    'numSubjects' => 2,
                    'subjects' => ['orders.new_one' => 1, 'orders.old-one' => 2],
                ],
                'headers' => ['X-Kind' => ['text']],
                'tiers' => [
                    'R1_tier' => ['maxStorage' => 1, 'metadata' => ['a_b' => 'c']],
                ],
                'list' => [['firstKey' => 1]],
            ],
            iterator_to_array($data),
        );
    }

    public function testFirstOfCollidingKeysWins(): void
    {
        self::assertSame(['maxMsgs' => 1], iterator_to_array(new CamelCaseKeys(['max_msgs' => 1, 'max-msgs' => 2])));
    }

    /**
     * The map fields are known by name: a new one in the deserialized types must be added to the list.
     */
    public function testKnowsEveryMapFieldOfDeserializedTypes(): void
    {
        $maps = new \ReflectionClassConstant(CamelCaseKeys::class, 'MAPS')->getValue();
        self::assertIsArray($maps);

        $api = glob(__DIR__ . '/../../../src/JetStream/Api/*.php');
        self::assertNotFalse($api);

        $files = [
            ...$api,
            __DIR__ . '/../../../src/JetStream/ObjectStore/ObjectInfo.php',
        ];

        $fields = [];

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Thesis\Nats\\' . str_replace('/', '\\', substr((string) realpath($file), \strlen((string) realpath(__DIR__ . '/../../../src/')) + 1, -4));

            $doc = new \ReflectionClass($class)->getConstructor()?->getDocComment();

            if (\is_string($doc) && preg_match_all('/@param\s+\??array<(?:non-empty-)?string,\s*.+>\s+\$(\w+)/', $doc, $matches) > 0) {
                foreach ($matches[1] as $field) {
                    $fields[$field] = $class;
                }
            }
        }

        self::assertNotEmpty($fields);

        foreach ($fields as $field => $class) {
            self::assertArrayHasKey($field, $maps, "{$class}::\${$field} is a map: add it to CamelCaseKeys::MAPS.");
        }
    }
}
