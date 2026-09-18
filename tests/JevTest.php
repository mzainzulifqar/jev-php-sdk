<?php

declare(strict_types=1);

namespace Zain\Jev\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Zain\Jev\Exceptions\AuthenticationException;
use Zain\Jev\Exceptions\ConnectionException;
use Zain\Jev\Exceptions\InvalidQuestion;
use Zain\Jev\Exceptions\MissingAnswer;
use Zain\Jev\Exceptions\RateLimitException;
use Zain\Jev\Exceptions\ValidationException;
use Zain\Jev\Jev;
use Zain\Jev\Question;
use Zain\Jev\RetryPolicy;

final class JevTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function jev(RetryPolicy $retry = new RetryPolicy(maxAttempts: 1)): Jev
    {
        $psr17 = new Psr17Factory();

        return new Jev('test-key', retry: $retry, http: $this->http, requests: $psr17, streams: $psr17);
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'model' => 'jev-latest',
            'answers' => [
                'is_complaint' => ['type' => 'noul', 'noul' => 0.93],
                'team' => ['type' => 'choice', 'choice' => 'billing',
                    'probabilities' => ['billing' => 0.82, 'technical' => 0.13, 'other' => 0.05], 'confidence' => 0.88],
                'urgency' => ['type' => 'score', 'score' => 1.4,
                    'legend' => ['0' => 'can wait', '1' => 'this week', '2' => 'today'],
                    'probabilities' => ['0' => 0.1, '1' => 0.5, '2' => 0.4], 'confidence' => 0.62],
            ],
            'usage' => ['input_tokens' => 412, 'output_tokens' => 0],
        ];
    }

    /** @return array<string, Question> */
    private function questions(): array
    {
        return [
            'is_complaint' => Question::noul('Is the writer complaining?'),
            'team' => Question::choice('Which team?', ['billing' => 'Invoices', 'technical' => 'Bugs', 'other' => 'Anything else']),
            'urgency' => Question::score('How urgent?', ['can wait', 'this week', 'today']),
        ];
    }

    public function test_it_sends_the_documented_request(): void
    {
        $this->http->willReturn($this->response());

        $this->jev()->ask('My invoice is wrong.', $this->questions());

        $request = $this->http->sent[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.typesafe.ai/v1/systemone', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        self::assertSame([
            'model' => 'jev-latest',
            'state' => 'My invoice is wrong.',
            'questions' => [
                'is_complaint' => ['type' => 'noul', 'instructions' => 'Is the writer complaining?'],
                'team' => ['type' => 'choice', 'instructions' => 'Which team?',
                    'criteria' => ['billing' => 'Invoices', 'technical' => 'Bugs', 'other' => 'Anything else']],
                'urgency' => ['type' => 'score', 'instructions' => 'How urgent?',
                    'criteria' => ['can wait', 'this week', 'today']],
            ],
        ], $this->http->bodyOf());
    }

    public function test_it_reads_each_answer_type(): void
    {
        $this->http->willReturn($this->response());

        $result = $this->jev()->ask('My invoice is wrong.', $this->questions());

        self::assertSame('jev-latest', $result->model);
        self::assertSame(0.93, $result->noul('is_complaint')->probability);
        self::assertTrue($result->noul('is_complaint')->isTrue());
        self::assertSame('billing', $result->choice('team')->choice);
        self::assertSame(0.82, $result->choice('team')->probabilityOf('billing'));
        self::assertSame(0.0, $result->choice('team')->probabilityOf('nonexistent'));
        self::assertSame(1.4, $result->score('urgency')->score);
        self::assertSame('this week', $result->score('urgency')->nearestLevel());
        self::assertSame(412, $result->usage->inputTokens);
    }

    public function test_a_yes_no_answer_is_least_confident_at_one_half(): void
    {
        $this->http->willReturn(['answers' => ['sure' => ['type' => 'noul', 'noul' => 0.5]]]);

        // A yes/no answer carries no confidence field, so 0.5 -- no information -- must read as
        // no confidence rather than as a positive answer.
        $answer = $this->jev()->ask('x', ['sure' => Question::noul('?')])->noul('sure');
        self::assertSame(0.0, $answer->confidence());
        self::assertFalse($answer->isTrue(0.51));
    }

    public function test_it_ranks_choices_and_reports_which_answers_were_uncertain(): void
    {
        $this->http->willReturn($this->response());

        $result = $this->jev()->ask('x', $this->questions());

        self::assertSame(['billing', 'technical', 'other'], array_keys($result->choice('team')->ranked()));
        // team is 0.88 and urgency 0.62, so only urgency falls below the 0.8 line.
        self::assertSame(['urgency'], $result->uncertain(0.8));
        self::assertSame(['is_complaint' => 0.93, 'team' => 'billing', 'urgency' => 1.4], $result->values());
    }

    public function test_it_names_the_available_answers_when_one_is_read_by_the_wrong_name(): void
    {
        $this->http->willReturn($this->response());

        $result = $this->jev()->ask('x', $this->questions());

        $this->expectException(MissingAnswer::class);
        $this->expectExceptionMessage('No answer named [nope]. This result has: is_complaint, team, urgency.');
        $result->get('nope');
    }

    public function test_it_refuses_to_read_an_answer_as_the_wrong_type(): void
    {
        $this->http->willReturn($this->response());

        $result = $this->jev()->ask('x', $this->questions());

        $this->expectException(MissingAnswer::class);
        $this->expectExceptionMessage('Answer [team] is a ChoiceAnswer, not a NoulAnswer');
        $result->noul('team');
    }

    public function test_it_maps_error_statuses_to_their_own_exceptions(): void
    {
        foreach ([401 => AuthenticationException::class, 422 => ValidationException::class, 429 => RateLimitException::class] as $status => $expected) {
            $this->http = new FakeHttpClient();
            $this->http->willReturn(['error' => ['message' => 'nope']], $status);

            try {
                $this->jev()->ask('x', ['q' => Question::noul('?')]);
                self::fail("HTTP {$status} should have thrown {$expected}.");
            } catch (\Zain\Jev\Exceptions\ApiException $e) {
                self::assertInstanceOf($expected, $e);
                self::assertSame($status, $e->status);
            }
        }
    }

    public function test_it_retries_a_rate_limit_and_then_succeeds(): void
    {
        $this->http->willReturn(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '0']);
        $this->http->willReturn($this->response());

        $result = $this->jev(new RetryPolicy(maxAttempts: 2, baseDelay: 0.0))->ask('x', $this->questions());

        self::assertSame(2, $this->http->requestCount());
        self::assertSame('billing', $result->choice('team')->choice);
    }

    public function test_it_does_not_retry_a_request_the_api_rejected(): void
    {
        // A 422 means the request itself is wrong, so sending it again can only fail the same way.
        $this->http->willReturn(['error' => ['message' => 'bad question']], 422);

        $this->expectException(ValidationException::class);

        try {
            $this->jev(new RetryPolicy(maxAttempts: 3, baseDelay: 0.0))->ask('x', ['q' => Question::noul('?')]);
        } finally {
            self::assertSame(1, $this->http->requestCount());
        }
    }

    public function test_it_reports_a_network_failure_as_a_connection_exception(): void
    {
        $psr17 = new Psr17Factory();
        $this->http->willFail(new NetworkFailure($psr17->createRequest('POST', 'https://api.typesafe.ai/v1/systemone')));

        $this->expectException(ConnectionException::class);
        $this->jev()->ask('x', ['q' => Question::noul('?')]);
    }

    public function test_it_rejects_a_request_with_no_questions_before_sending_it(): void
    {
        $this->expectException(InvalidQuestion::class);

        try {
            $this->jev()->ask('x', []);
        } finally {
            self::assertSame(0, $this->http->requestCount());
        }
    }

    public function test_it_explains_a_body_that_is_not_json(): void
    {
        $this->http->willReturnRaw('<html>gateway error</html>', 200);

        $this->expectExceptionMessage('The API returned a body that is not JSON.');
        $this->jev()->ask('x', ['q' => Question::noul('?')]);
    }

    public function test_it_asks_the_same_questions_about_many_states_keeping_the_input_keys(): void
    {
        $this->http->willReturn($this->response());
        $this->http->willReturn($this->response());

        $results = $this->jev()->askEach(['first' => 'a', 'second' => 'b'], $this->questions());

        self::assertSame(['first', 'second'], array_keys($results));
        self::assertSame('a', $this->http->bodyOf(0)['state']);
        self::assertSame('b', $this->http->bodyOf(1)['state']);
    }

    public function test_with_model_changes_the_model_without_touching_the_original(): void
    {
        $this->http->willReturn($this->response());
        $this->http->willReturn($this->response());

        $jev = $this->jev();
        $jev->withModel('jev-1.13')->ask('x', ['q' => Question::noul('?')]);
        $jev->ask('x', ['q' => Question::noul('?')]);

        self::assertSame('jev-1.13', $this->http->bodyOf(0)['model']);
        self::assertSame('jev-latest', $this->http->bodyOf(1)['model']);
    }

    public function test_it_rejects_questions_that_cannot_be_answered(): void
    {
        $this->expectException(InvalidQuestion::class);
        Question::choice('Which one?', ['only' => 'the only option']);
    }

    public function test_a_score_needs_at_least_two_levels(): void
    {
        $this->expectException(InvalidQuestion::class);
        Question::score('How urgent?', ['urgent']);
    }
}
