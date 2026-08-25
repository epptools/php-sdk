<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * A transport-level failure: TLS handshake, socket connect, read/write, or a
 * truncated/over-long frame. Not an EPP result code — the server never answered (or
 * the link broke mid-exchange).
 */
final class ConnectionException extends EppException
{
}

