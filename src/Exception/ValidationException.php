<?php

declare(strict_types=1);

namespace EppTools\Exception;

/**
 * A value passed to this call cannot be used, and nothing was sent.
 *
 * An unknown option key, a fee amount that is not a decimal, an empty contact role, an operation
 * this registry does not implement. The fault is in the arguments of one call — the next call with
 * different arguments works.
 *
 * Deliberately NOT a ConfigException. That one means the client itself is set up wrong (no host, no
 * credentials, a password the server cannot accept) and every call will fail until someone changes
 * the deployment. The two need opposite responses: one is answered to whoever made the request, the
 * other is an alert for whoever runs the service. Sharing one class would leave a service guessing,
 * and guessing wrong reports an operator's own misconfiguration to a customer as their mistake.
 *
 * Both extend EppException, so `catch (EppException)` still catches everything.
 */
class ValidationException extends EppException
{
}
