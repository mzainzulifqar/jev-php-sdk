<?php

declare(strict_types=1);

namespace Zain\Jev\Exceptions;

use Throwable;

/** The API answered with an error status. Subclasses name the ones worth handling separately. */
class ApiException extends JevException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $body = null,
        /** Seconds the server asked us to wait, from its Retry-After header. */
        public readonly ?float $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function for(int $status, ?string $body, ?float $retryAfter = null, ?Throwable $previous = null): self
    {
        return match ($status) {
            401 => new AuthenticationException('The API key was missing or not accepted.', $status, $body, $retryAfter, $previous),
            422 => new ValidationException('The request was rejected as invalid: '.self::detail($body), $status, $body, $retryAfter, $previous),
            429 => new RateLimitException('Rate limited.', $status, $body, $retryAfter, $previous),
            529 => new OverloadedException('The service is overloaded.', $status, $body, $retryAfter, $previous),
            default => new self('The API returned HTTP '.$status.': '.self::detail($body), $status, $body, $retryAfter, $previous),
        };
    }

    /** True while retrying is worth it: the request was fine, the service just could not take it now. */
    public function isTransient(): bool
    {
        return $this->status === 429 || $this->status === 529 || $this->status >= 500;
    }

    private static function detail(?string $body): string
    {
        $decoded = json_decode((string) $body, true);

        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;

            if (is_string($message)) {
                return $message;
            }
        }

        return trim((string) $body) === '' ? 'no detail given' : substr((string) $body, 0, 500);
    }
}
