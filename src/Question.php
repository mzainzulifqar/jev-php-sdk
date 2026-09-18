<?php

declare(strict_types=1);

namespace Zain\Jev;

use Zain\Jev\Questions\ChoiceQuestion;
use Zain\Jev\Questions\NoulQuestion;
use Zain\Jev\Questions\ScoreQuestion;

/**
 * One typed question to ask about a state.
 *
 * Build questions through the named constructors rather than the concrete classes:
 *
 *     Question::noul('Is this a complaint?')
 *     Question::choice('Which team?', ['billing' => 'Invoices and refunds', 'support' => 'Product problems'])
 *     Question::score('How urgent?', ['can wait', 'this week', 'today'])
 */
abstract class Question
{
    /**
     * A yes/no question. The answer is the probability that it is true, so 0.5 means the
     * model has no idea -- there is no separate confidence to check.
     *
     * @param  array{true?: string, false?: string}  $criteria  Optional wording of what each side means.
     */
    public static function noul(string $instructions, array $criteria = []): NoulQuestion
    {
        return new NoulQuestion($instructions, $criteria);
    }

    /**
     * Pick exactly one of the given options.
     *
     * Give every option that could apply. Where the list might not cover everything, include an
     * "other" option: without one the model must pick a wrong answer from the options it has.
     *
     * @param  array<string, string>  $options  option key => what that option means
     */
    public static function choice(string $instructions, array $options): ChoiceQuestion
    {
        return new ChoiceQuestion($instructions, $options);
    }

    /**
     * Rate the state against ordered levels, lowest first. The answer can land between two
     * levels, so three levels give a 0..2 scale rather than three discrete buckets.
     *
     * @param  list<string>  $levels  at least two level descriptions, in order
     */
    public static function score(string $instructions, array $levels): ScoreQuestion
    {
        return new ScoreQuestion($instructions, $levels);
    }

    /** @return array<string, mixed> The question as the API expects it. */
    abstract public function toArray(): array;
}
