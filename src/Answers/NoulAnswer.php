<?php

declare(strict_types=1);

namespace Zain\Jev\Answers;

/**
 * The answer to a yes/no question, as the probability that it is true.
 *
 * There is no separate confidence field: 0.5 means no information, and certainty grows towards
 * either end. Read `probability` and compare it against a threshold you chose deliberately.
 */
final class NoulAnswer implements Answer
{
    public function __construct(public readonly float $probability)
    {
    }

    public function value(): float
    {
        return $this->probability;
    }

    /** Distance from the 0.5 no-information point, rescaled to 0..1. */
    public function confidence(): float
    {
        return abs($this->probability - 0.5) * 2;
    }

    /** True when the probability clears $threshold. Pick the threshold for the cost of being wrong. */
    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }
}
