<?php

declare(strict_types=1);

namespace Zain\Jev;

/**
 * Tokens billed for one request.
 *
 * Output tokens are free and asking several questions about one state costs only the extra
 * question tokens, so cost tracks the size of the state far more than the number of questions.
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self((int) ($payload['input_tokens'] ?? 0), (int) ($payload['output_tokens'] ?? 0));
    }
}
