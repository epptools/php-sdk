<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The object's current state forbids the operation (EPP 2304 / 2305).
 *
 * A `clientHold`, a pending transfer, a nameserver still used by a domain. Read the object with an
 * info command, clear the status or association that blocks you, then try again — the same request
 * will succeed once the state changes.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class ObjectStatusException extends CommandException
{
}