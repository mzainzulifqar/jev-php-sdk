<?php

declare(strict_types=1);

namespace Zain\Jev;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Zain\Jev\Exceptions\ApiException;
use Zain\Jev\Exceptions\ConnectionException;
use Zain\Jev\Exceptions\InvalidQuestion;
use Zain\Jev\Exceptions\JevException;

/**
 * A client for TypeSafe's System One API.
 *
 *     $jev = new Jev('your-api-key');
 *
 *     $result = $jev->ask($email->body, [
 *         'is_complaint' => Question::noul('Is the writer complaining?'),
 *         'team'         => Question::choice('Which team should answer?', [
 *             'billing'   => 'Invoices, refunds and payment problems',
 *             'technical' => 'The product not working as expected',
 *             'other'     => 'Anything else',
 *         ]),
 *         'urgency'      => Question::score('How urgent is this?', ['can wait', 'this week', 'today']),
 *     ]);
 *
 *     $result->choice('team')->choice;          // 'billing'
 *     $result->choice('team')->confidence();    // 0.88
 *     $result->noul('is_complaint')->isTrue();  // true
 *
 * Questions asked together are answered together, in parallel, and cost only their own tokens --
 * so ask everything you want to know about a state in one call rather than one call per question.
 *
 * Any PSR-18 HTTP client will do. Pass one to use the client your application already configures
 * (and to test without a network); leave it out and one is discovered from what you have installed.
 */
final class Jev
{
    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai/v1';

    public const DEFAULT_MODEL = 'jev-latest';

    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private StreamFactoryInterface $streams;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly RetryPolicy $retry = new RetryPolicy(),
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /** The same client against a different model, leaving this one untouched. */
    public function withModel(string $model): self
    {
        return new self($this->apiKey, $model, $this->baseUrl, $this->retry, $this->http, $this->requests, $this->streams);
    }

    /**
     * Ask questions about one state.
     *
     * @param  string|array<mixed>      $state      what to judge: text, or a structure of text
     * @param  array<string, Question>  $questions  the name to read each answer back under
     *
     * @throws InvalidQuestion      when the request could not be built
     * @throws ApiException         when the API rejects or fails the request
     * @throws ConnectionException  when the request never reached the API
     */
    public function ask(string|array $state, array $questions): Result
    {
        if ($questions === []) {
            throw new InvalidQuestion('Ask at least one question.');
        }

        return Result::fromArray($this->send([
            'model' => $this->model,
            'state' => $state,
            'questions' => array_map(static fn (Question $q): array => $q->toArray(), $questions),
        ]));
    }

    /**
     * Ask the same questions about many states, one request each.
     *
     * Results come back under the input keys, so a failure on one row can be matched back to it.
     * Requests are sequential: the useful parallelism for a large job belongs in your queue or
     * worker pool, where you can also control how much of the API you are allowed to use at once.
     *
     * @param  iterable<array-key, string|array<mixed>>  $states
     * @param  array<string, Question>                   $questions
     * @return array<array-key, Result>
     */
    public function askEach(iterable $states, array $questions): array
    {
        $results = [];

        foreach ($states as $key => $state) {
            $results[$key] = $this->ask($state, $questions);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->decode($this->post($payload));
            } catch (JevException $e) {
                if (! $this->retry->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                $seconds = $this->retry->delayFor($attempt, $e instanceof ApiException ? $e->retryAfter : null);
                usleep((int) ($seconds * 1_000_000));
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): ResponseInterface
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            throw new InvalidQuestion('The state could not be encoded as JSON: '.$e->getMessage(), 0, $e);
        }

        $request = $this->requests
            ->createRequest('POST', rtrim($this->baseUrl, '/').'/systemone')
            ->withHeader('Authorization', 'Bearer '.$this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException('The request never reached the API: '.$e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw ApiException::for($status, $body, self::retryAfter($response));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('The API returned a body that is not JSON.', $status, $body, null, $e);
        }

        return is_array($decoded)
            ? $decoded
            : throw new ApiException('The API returned JSON that is not an object.', $status, $body);
    }

    /** Retry-After, given either as a number of seconds or as an HTTP date. */
    private static function retryAfter(ResponseInterface $response): ?float
    {
        $header = $response->getHeaderLine('Retry-After');

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return (float) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0.0, (float) ($at - time()));
    }
}
