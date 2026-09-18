<?php

declare(strict_types=1);

namespace Zain\Jev\Exceptions;

use Zain\Jev\Answers\Answer;

/** An answer was read under a name that is not in the result, or as the wrong type. */
final class MissingAnswer extends JevException
{
    /** @param list<string> $available */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf(
            'No answer named [%s]. This result has: %s.',
            $name,
            $available === [] ? 'nothing' : implode(', ', $available),
        ));
    }

    /** @param class-string $expected */
    public static function wrongType(string $name, string $expected, Answer $actual): self
    {
        return new self(sprintf(
            'Answer [%s] is a %s, not a %s -- read it with the accessor matching the question you asked.',
            $name,
            self::shortName($actual::class),
            self::shortName($expected),
        ));
    }

    private static function shortName(string $class): string
    {
        return substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
    }
}
