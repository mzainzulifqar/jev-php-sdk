<?php

declare(strict_types=1);

namespace Zain\Jev\Tests;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** The PSR-18 failure a client raises when it never got a response at all. */
final class NetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message = 'Connection refused')
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
