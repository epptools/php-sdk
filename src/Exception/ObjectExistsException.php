<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The object is already registered (EPP 2302).
 *
 * On a create, someone holds the name — you, or another registrar. It is not a fault in your
 * request and retrying cannot help: check who holds it, or pick another name. Racing another
 * registrar for a dropping name is the one case where this is expected rather than exceptional.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class ObjectExistsException extends CommandException
{
}