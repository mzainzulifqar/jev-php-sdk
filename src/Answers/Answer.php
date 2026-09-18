<?php

declare(strict_types=1);

namespace Zain\Jev\Answers;

/** One question's answer. */
interface Answer
{
    /** The answer reduced to a single value: the chosen key, the score, or the probability of yes. */
    public function value(): string|float;

    /**
     * How peaked the distribution is, 0..1 -- how sure the model is of THIS answer, as opposed to
     * how likely the answer is to be true.
     *
     * A yes/no answer has no separate confidence: its probability already carries that, so this
     * returns how far the probability sits from the 0.5 no-information point.
     */
    public function confidence(): float;
}
