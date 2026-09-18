<?php

declare(strict_types=1);

namespace Zain\Jev\Questions;

use Zain\Jev\Exceptions\InvalidQuestion;
use Zain\Jev\Question;

/** Pick one option from a fixed set. @see Question::choice() */
final class ChoiceQuestion extends Question
{
    /** @param array<string, string> $options option key => what that option means */
    public function __construct(
        public readonly string $instructions,
        public readonly array $options,
    ) {
        if (count($options) < 2) {
            throw new InvalidQuestion('A choice needs at least two options, '.count($options).' given.');
        }
    }

    public function toArray(): array
    {
        return ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->options];
    }
}
