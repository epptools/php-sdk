<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The object exists, but not for you (EPP 2201 / 2202).
 *
 * Either it belongs to another registrar, or the authInfo you supplied does not match. Distinct
 * from AuthenticationException, which is the SESSION failing to log in — here the session is fine
 * and this one object is out of reach. Never retry with the same credentials.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class AuthorizationException extends CommandException
{
}