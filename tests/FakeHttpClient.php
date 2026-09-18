<?php

declare(strict_types=1);

namespace Zain\Jev\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that replays queued responses and records what it was asked to send, so the
 * tests exercise the real request building and error handling without a network.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @param array<string, mixed> $payload */
    public function willReturn(array $payload, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], (string) json_encode($payload));

        return $this;
    }

    public function willReturnRaw(string $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function willFail(ClientExceptionInterface $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException('The fake HTTP client ran out of queued responses after '.count($this->sent).' request(s).');
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function requestCount(): int
    {
        return count($this->sent);
    }

    /** @return array<string, mixed> The JSON body of request number $index, zero based. */
    public function bodyOf(int $index = 0): array
    {
        return (array) json_decode((string) $this->sent[$index]->getBody(), true);
    }
}
