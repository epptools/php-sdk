<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The credentials were rejected: EPP 2200, and only 2200.
 *
 * A server can refuse a login for other reasons — the account is at its session limit, the server
 * is closing, a service you announced is not offered, this connection is already logged in — and
 * each arrives as its own class with its own remedy. Catching those as an authentication failure
 * would send you to change a password that was never the problem.
 *
 * Carries the EPP result code and the response via CommandException.
 */
final class AuthenticationException extends CommandException
{
}
