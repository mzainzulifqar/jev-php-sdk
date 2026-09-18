<?php

declare(strict_types=1);

namespace Zain\Jev\Answers;

/** The answer to a choice question: the selected option, plus the odds it gave every option. */
final class ChoiceAnswer implements Answer
{
    /** @param array<string, float> $probabilities option key => probability */
    public function __construct(
        public readonly string $choice,
        public readonly array $probabilities,
        public readonly float $confidence,
    ) {
    }

    public function value(): string
    {
        return $this->choice;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    /** The probability of one option, or 0.0 for an option the answer does not mention. */
    public function probabilityOf(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }

    /**
     * The options ordered most to least likely, so a caller can show the runner-up or send the
     * top few somewhere else. Keys are option keys, values are probabilities.
     *
     * @return array<string, float>
     */
    public function ranked(): array
    {
        $ranked = $this->probabilities;
        arsort($ranked);

        return $ranked;
    }
}
