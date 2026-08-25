<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The registry's own rules refuse the request (EPP 2306 / 2308).
 *
 * The command is well-formed and you are allowed to send it; the value is one this registry will
 * not accept — a period it does not offer, a name under a reservation, a change its data policy
 * forbids. Retrying is pointless until the request itself changes.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class PolicyException extends CommandException
{
}