<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The object is not in the registry (EPP 2303).
 *
 * The name, handle or nameserver you referenced does not exist. Usually a stale identifier or a
 * typo — including a contact handle you assumed the registry had kept. Retrying cannot help.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class ObjectDoesNotExistException extends CommandException
{
}