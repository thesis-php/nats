<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Validation;

use Thesis\Nats\Exception\InvalidBucketName;
use Thesis\Nats\Exception\InvalidKey;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Exception\ObjectIsInvalid;

/**
 * Client-side checks of subjects and names, so that a malformed value fails before anything is sent
 * instead of a server -ERR, a JetStream API error or a silently accepted odd name.
 * The rules follow nats-server (subject.go, jetstream.go) and nats.go (jetstream, kv, micro).
 *
 * @internal
 */
final class Validate
{
    /** nats-server JSMaxNameLen. */
    private const int MAX_NAME_LENGTH = 255;
    private const string WHITESPACE = "/[ \t\r\n\f\v\\x00]/";
    private const string INVALID_NAME_CHARS = '/[.*>\/\\\\\x00-\x20\x7f]/';
    private const string BUCKET_REGEX = '/^[a-zA-Z0-9_-]+$/';
    private const string KEY_REGEX = '/^[-\/_=.a-zA-Z0-9]+$/';
    private const string KEY_FILTER_REGEX = '/^[-\/_=.a-zA-Z0-9*>]+$/';
    private const string ENDPOINT_NAME_REGEX = '/^[A-Za-z0-9\-_]+$/';

    /** nats-server validGroupName. */
    private const string PRIORITY_GROUP_REGEX = '/^[a-zA-Z0-9\/_=-]{1,16}$/';

    /**
     * A literal subject, e.g. of publish, request or reply-to: wildcards are not allowed.
     *
     * @throws InvalidSubject
     */
    public static function publishSubject(string $subject): void
    {
        $reason = self::subjectError($subject, partialWildcard: false, fullWildcard: false);
        if ($reason !== null) {
            throw InvalidSubject::because($subject, $reason);
        }
    }

    /**
     * A subject that may match many, e.g. of subscribe or a stream: '*' may stand for any whole token,
     * '>' for the whole rest of the subject, so only as the last token.
     *
     * @throws InvalidSubject
     */
    public static function subscribeSubject(string $subject): void
    {
        $reason = self::subjectError($subject, partialWildcard: true, fullWildcard: true);
        if ($reason !== null) {
            throw InvalidSubject::because($subject, $reason);
        }
    }

    /**
     * A subject that more tokens are appended to, e.g. a micro group: '>' would leave nothing to append after.
     *
     * @throws InvalidSubject
     */
    public static function subjectPrefix(string $subject): void
    {
        $reason = self::subjectError($subject, partialWildcard: true, fullWildcard: false);
        if ($reason !== null) {
            throw InvalidSubject::because($subject, $reason);
        }
    }

    /**
     * @throws InvalidSubject
     */
    public static function queueGroup(string $queueGroup): void
    {
        $reason = match (true) {
            $queueGroup === '' => 'it is empty',
            preg_match(self::WHITESPACE, $queueGroup) === 1 => 'it contains whitespace',
            default => null,
        };

        if ($reason !== null) {
            throw InvalidSubject::forQueueGroup($queueGroup, $reason);
        }
    }

    /**
     * A stream, consumer or counter name: it becomes a single subject token of the JetStream API.
     *
     * @throws InvalidName
     */
    public static function name(string $name): void
    {
        $reason = match (true) {
            $name === '' => 'it is empty',
            \strlen($name) > self::MAX_NAME_LENGTH => \sprintf('it is longer than %d bytes', self::MAX_NAME_LENGTH),
            preg_match(self::INVALID_NAME_CHARS, $name) === 1 => "it must not contain '.', '*', '>', '/', '\\', whitespace or control characters",
            default => null,
        };

        if ($reason !== null) {
            throw InvalidName::because($name, $reason);
        }
    }

    /**
     * @throws InvalidName
     */
    public static function endpointName(string $name): void
    {
        if (preg_match(self::ENDPOINT_NAME_REGEX, $name) !== 1) {
            throw InvalidName::because($name, "only 'A-Z', 'a-z', '0-9', '-' and '_' are allowed");
        }
    }

    /**
     * A priority group of a pull consumer: it goes into the request payload, not into the subject,
     * so the rule is nats-server's own and differs from the one of stream and consumer names.
     *
     * @throws InvalidName
     */
    public static function priorityGroup(string $group): void
    {
        if (preg_match(self::PRIORITY_GROUP_REGEX, $group) !== 1) {
            throw InvalidName::because($group, "only 'A-Z', 'a-z', '0-9', '-', '_', '/' and '=' are allowed, at most 16 characters");
        }
    }

    /**
     * A key-value or object store bucket name.
     *
     * @throws InvalidBucketName
     */
    public static function bucket(string $bucket): void
    {
        if (preg_match(self::BUCKET_REGEX, $bucket) !== 1) {
            throw InvalidBucketName::because($bucket, "only 'A-Z', 'a-z', '0-9', '-' and '_' are allowed");
        }
    }

    /**
     * @throws InvalidKey
     */
    public static function key(string $key): void
    {
        $reason = preg_match(self::KEY_REGEX, $key) !== 1
            ? "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=' and '.' are allowed"
            : self::subjectError($key, partialWildcard: false, fullWildcard: false);

        if ($reason !== null) {
            throw InvalidKey::because($key, $reason);
        }
    }

    /**
     * A key or a key pattern with wildcards, e.g. to watch.
     *
     * @throws InvalidKey
     */
    public static function keyFilter(string $key): void
    {
        $reason = preg_match(self::KEY_FILTER_REGEX, $key) !== 1
            ? "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=', '.', '*' and '>' are allowed"
            : self::subjectError($key, partialWildcard: true, fullWildcard: true);

        if ($reason !== null) {
            throw InvalidKey::because($key, $reason);
        }
    }

    /**
     * An object store object name: any non-empty string, it is base64-encoded into the subject.
     *
     * @throws ObjectIsInvalid
     */
    public static function objectName(string $name): void
    {
        if ($name === '') {
            throw new ObjectIsInvalid('Object name is empty.');
        }
    }

    /**
     * Like nats-server, a wildcard is only a whole token: in "foo*" the '*' is a literal character.
     */
    private static function subjectError(string $subject, bool $partialWildcard, bool $fullWildcard): ?string
    {
        if ($subject === '') {
            return 'it is empty';
        }

        if (preg_match(self::WHITESPACE, $subject) === 1) {
            return 'it contains whitespace';
        }

        $tokens = explode('.', $subject);
        $last = \count($tokens) - 1;

        foreach ($tokens as $i => $token) {
            $error = match (true) {
                $token === '' => 'it contains an empty token',
                $token === '*' && !$partialWildcard,
                $token === '>' && !$fullWildcard => "the wildcard '{$token}' is not allowed",
                $token === '>' && $i !== $last => "the wildcard '>' must be the last token",
                default => null,
            };

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }
}
