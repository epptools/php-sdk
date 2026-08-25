<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * A client-side usage/configuration error caught BEFORE anything is sent to the server
 * (e.g. an empty clID/password/host). Distinct from CommandException, which carries an
 * actual EPP result code returned by the registry.
 */
final class ConfigException extends EppException
{
}

