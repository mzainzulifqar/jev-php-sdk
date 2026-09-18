<?php

declare(strict_types=1);

namespace Zain\Jev\Answers;

/**
 * The answer to a score question, as a position along the levels you defined.
 *
 * Three levels give a 0..2 scale, and the score can land between them: 1.4 means "past the middle
 * level, not quite the top one". Round only when you have a reason to.
 */
final class ScoreAnswer implements Answer
{
    /**
     * @param  array<int|string, string>  $legend         level index => the level description
     * @param  array<int|string, float>   $probabilities  level index => probability
     */
    public function __construct(
        public readonly float $score,
        public readonly array $legend,
        public readonly array $probabilities,
        public readonly float $confidence,
    ) {
    }

    public function value(): float
    {
        return $this->score;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    /** The description of the nearest level, or null when the response carried no legend. */
    public function nearestLevel(): ?string
    {
        return $this->legend[(string) (int) round($this->score)]
            ?? $this->legend[(int) round($this->score)]
            ?? null;
    }
}
