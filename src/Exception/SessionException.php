<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * The server is ending the session (EPP 2500 / 2501 / 2502).
 *
 * Too many concurrent sessions, an idle timeout, or the server shutting down. The connection is
 * gone or going; reconnect and log in again. The command itself may be perfectly good, so this is
 * one of the few failures where retrying — on a NEW connection, after a pause — is right.
 *
 * Extends CommandException, so `catch (CommandException)` catches it too.
 */
final class SessionException extends CommandException
{
}