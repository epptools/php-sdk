<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The account cannot pay for this operation (EPP 2104).
 *
 * Different from every other failure and worth its own catch: nothing is wrong with the request,
 * and every subsequent billable command will fail the same way until the balance is topped up. A
 * batch that treats this like any other error will grind through the rest of its queue producing
 * identical failures. Stop, alert whoever funds the account, and resume afterwards.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class InsufficientFundsException extends CommandException
{
}