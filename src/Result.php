<?php

declare(strict_types=1);

namespace Zain\Jev;

use Zain\Jev\Answers\Answer;
use Zain\Jev\Answers\ChoiceAnswer;
use Zain\Jev\Answers\NoulAnswer;
use Zain\Jev\Answers\ScoreAnswer;
use Zain\Jev\Exceptions\MissingAnswer;

/**
 * The answers to one request, keyed by the names the questions were asked under.
 *
 * The typed accessors -- noul(), choice(), score() -- are the ones to reach for: each returns the
 * matching answer object or throws, so a caller never has to check a type or unpack an array.
 */
final class Result
{
    /** @param array<string, Answer> $answers */
    public function __construct(
        public readonly string $model,
        public readonly array $answers,
        public readonly Usage $usage,
    ) {
    }

    /** @param array<string, mixed> $payload a decoded API response */
    public static function fromArray(array $payload): self
    {
        $answers = [];

        foreach ((array) ($payload['answers'] ?? []) as $name => $answer) {
            $answers[(string) $name] = self::answerFromArray((array) $answer);
        }

        return new self(
            (string) ($payload['model'] ?? ''),
            $answers,
            Usage::fromArray((array) ($payload['usage'] ?? [])),
        );
    }

    public function has(string $name): bool
    {
        return isset($this->answers[$name]);
    }

    public function get(string $name): Answer
    {
        return $this->answers[$name] ?? throw MissingAnswer::named($name, array_keys($this->answers));
    }

    public function noul(string $name): NoulAnswer
    {
        return $this->typed($name, NoulAnswer::class);
    }

    public function choice(string $name): ChoiceAnswer
    {
        return $this->typed($name, ChoiceAnswer::class);
    }

    public function score(string $name): ScoreAnswer
    {
        return $this->typed($name, ScoreAnswer::class);
    }

    /** Every answer reduced to its single value, for logging or writing a row. @return array<string, string|float> */
    public function values(): array
    {
        return array_map(static fn (Answer $answer): string|float => $answer->value(), $this->answers);
    }

    /**
     * The names of the answers the model was less sure about than $threshold -- the ones worth
     * sending to a person or a slower model.
     *
     * @return list<string>
     */
    public function uncertain(float $threshold = 0.8): array
    {
        $names = [];

        foreach ($this->answers as $name => $answer) {
            if ($answer->confidence() < $threshold) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @template T of Answer
     * @param  class-string<T>  $expected
     * @return T
     */
    private function typed(string $name, string $expected): Answer
    {
        $answer = $this->get($name);

        return $answer instanceof $expected
            ? $answer
            : throw MissingAnswer::wrongType($name, $expected, $answer);
    }

    /** @param array<string, mixed> $answer */
    private static function answerFromArray(array $answer): Answer
    {
        return match ($answer['type'] ?? null) {
            'choice' => new ChoiceAnswer(
                (string) ($answer['choice'] ?? ''),
                array_map('floatval', (array) ($answer['probabilities'] ?? [])),
                (float) ($answer['confidence'] ?? 0.0),
            ),
            'score' => new ScoreAnswer(
                (float) ($answer['score'] ?? 0.0),
                array_map('strval', (array) ($answer['legend'] ?? [])),
                array_map('floatval', (array) ($answer['probabilities'] ?? [])),
                (float) ($answer['confidence'] ?? 0.0),
            ),
            default => new NoulAnswer((float) ($answer['noul'] ?? 0.0)),
        };
    }
}
