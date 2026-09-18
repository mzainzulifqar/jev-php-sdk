<?php

declare(strict_types=1);

namespace Zain\Jev;

use Zain\Jev\Exceptions\ApiException;
use Zain\Jev\Exceptions\ConnectionException;
use Zain\Jev\Exceptions\JevException;

/**
 * When to try again, and how long to wait first.
 *
 * Only rate limits, overload and connection failures are retried; a rejected request is a bug in
 * the caller and retrying it just wastes time. Waits back off exponentially with jitter, so a
 * fleet of workers that hit a limit together does not march back in lockstep.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 8.0,
    ) {
    }

    /** Never retry. */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function shouldRetry(JevException $e, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        return $e instanceof ConnectionException || ($e instanceof ApiException && $e->isTransient());
    }

    /**
     * Seconds to wait before attempt number $attempt + 1.
     *
     * A Retry-After from the server wins over the computed backoff: it knows when the limit
     * actually clears. Otherwise back off exponentially with up to 25% jitter either way.
     */
    public function delayFor(int $attempt, ?float $retryAfter = null): float
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, $this->maxDelay);
        }

        $delay = min($this->baseDelay * (2 ** max(0, $attempt - 1)), $this->maxDelay);
        $jitter = $delay * 0.25;

        return max(0.0, $delay + (mt_rand(-1000, 1000) / 1000) * $jitter);
    }
}
