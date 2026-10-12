<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the subject (possibly with wildcards) whose last sequence is compared with {@see ExpectedLastSubjSeq}
 * instead of the subject the message is published to.
 *
 * @api
 */
final readonly class ExpectedLastSubjSeqSubject
{
    private const string HEADER = 'Nats-Expected-Last-Subject-Sequence-Subject';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<non-empty-string> */
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
