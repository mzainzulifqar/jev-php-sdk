<?php

declare(strict_types=1);

namespace Zain\Jev\Questions;

use Zain\Jev\Exceptions\InvalidQuestion;
use Zain\Jev\Question;

/** Rate against ordered levels. @see Question::score() */
final class ScoreQuestion extends Question
{
    /** @param list<string> $levels level descriptions, lowest first */
    public function __construct(
        public readonly string $instructions,
        public readonly array $levels,
    ) {
        if (count($levels) < 2) {
            throw new InvalidQuestion('A score needs at least two levels, '.count($levels).' given.');
        }
    }

    public function toArray(): array
    {
        return ['type' => 'score', 'instructions' => $this->instructions, 'criteria' => array_values($this->levels)];
    }
}
