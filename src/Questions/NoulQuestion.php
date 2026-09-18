<?php

declare(strict_types=1);

namespace Zain\Jev\Questions;

use Zain\Jev\Question;

/** A yes/no question. @see Question::noul() */
final class NoulQuestion extends Question
{
    /** @param array{true?: string, false?: string} $criteria */
    public function __construct(
        public readonly string $instructions,
        public readonly array $criteria = [],
    ) {
    }

    public function toArray(): array
    {
        $question = ['type' => 'noul', 'instructions' => $this->instructions];

        if ($this->criteria !== []) {
            $question['criteria'] = $this->criteria;
        }

        return $question;
    }
}
