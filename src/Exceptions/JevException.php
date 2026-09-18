<?php

declare(strict_types=1);

namespace Zain\Jev\Exceptions;

use RuntimeException;

/** Base class for everything this package throws, so one catch can cover the SDK. */
class JevException extends RuntimeException
{
}
