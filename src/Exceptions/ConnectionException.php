<?php

declare(strict_types=1);

namespace Zain\Jev\Exceptions;

/** The request never got an answer: DNS, TLS, connection or timeout. */
final class ConnectionException extends JevException
{
}
