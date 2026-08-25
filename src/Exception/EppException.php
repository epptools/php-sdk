<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * Base class for every exception thrown by the SDK. Catch this to handle any SDK
 * failure; catch the subclasses to distinguish a transport problem from a command
 * rejection.
 */
class EppException extends \RuntimeException
{
}

