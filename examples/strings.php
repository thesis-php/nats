<?php

declare(strict_types=1);

function randomString(): string
{
    return bin2hex(random_bytes(10));
}

/** @var array<non-empty-string, true> */
const vowels = [
    'a' => true,
    'e' => true,
    'i' => true,
    'o' => true,
    'u' => true,
];

function countVowels(string $v): int
{
    $count = 0;

    for ($i = 0; $i < strlen($v); ++$i) {
        if (isset(vowels[strtolower($v[$i])])) {
            ++$count;
        }
    }

    return $count;
}

function countConsonants(string $v): int
{
    $count = 0;

    for ($i = 0; $i < strlen($v); ++$i) {
        if (!isset(vowels[strtolower($v[$i])])) {
            ++$count;
        }
    }

    return $count;
}

function countDigits(string $v): int
{
    return count(array_filter(str_split($v), is_numeric(...)));
}
