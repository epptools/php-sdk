<?php

declare(strict_types=1);

namespace EppTools\Exception;

use EppTools\Response;
use EppTools\ResultCode;

/**
 * The server answered with an EPP error result code (>= 2000).
 *
 * Catch this to handle any refusal. Catch one of its subclasses when a particular refusal needs a
 * different response from your code — the ones that do are the ones that exist:
 *
 *   InsufficientFundsException    2104            top up; every later billable command fails too
 *   AuthorizationException        2201, 2202      not yours, or the wrong authInfo
 *   ObjectExistsException         2302            already registered
 *   ObjectDoesNotExistException   2303            no such name/handle/host
 *   ObjectStatusException         2304, 2305      a status or association is in the way
 *   PolicyException               2306, 2308      the registry's rules refuse this value
 *   SessionException              2500–2502       reconnect and log in again
 *   AuthenticationException       2200            the LOGIN itself failed
 *
 * Everything else arrives as a plain CommandException; branch on eppCode() for those.
 */
class CommandException extends EppException
{
    /** The EPP result code (>= 2000). */
    public int $eppCode;

    /** The full parsed response, if one was received. */
    public ?Response $response;

    public function __construct(int $eppCode, string $message, ?Response $response = null)
    {
        $this->eppCode = $eppCode;
        $this->response = $response;
        parent::__construct($message, $eppCode);
    }

    /**
     * Builds the most specific exception for a result code.
     *
     * One place decides, so the mapping cannot drift between the commands that use it.
     */
    public static function forCode(int $code, string $message, ?Response $response = null): self
    {
        $class = match ($code) {
            ResultCode::BILLING_FAILURE => InsufficientFundsException::class,
            ResultCode::AUTHORIZATION_ERROR, ResultCode::INVALID_AUTHORIZATION => AuthorizationException::class,
            ResultCode::OBJECT_EXISTS => ObjectExistsException::class,
            ResultCode::OBJECT_DOES_NOT_EXIST => ObjectDoesNotExistException::class,
            ResultCode::OBJECT_STATUS_PROHIBITS_OPERATION,
            ResultCode::OBJECT_ASSOCIATION_PROHIBITS_OPERATION => ObjectStatusException::class,
            ResultCode::PARAMETER_VALUE_POLICY_ERROR,
            ResultCode::DATA_MANAGEMENT_POLICY_VIOLATION => PolicyException::class,
            ResultCode::COMMAND_FAILED_SERVER_CLOSING,
            ResultCode::AUTHENTICATION_SERVER_CLOSING,
            ResultCode::SESSION_LIMIT_EXCEEDED_SERVER_CLOSING => SessionException::class,
            default => self::class,
        };

        return new $class($code, $message, $response);
    }

    /**
     * Whether sending the very same command again could succeed.
     *
     * True only for failures that are about the moment rather than the request: the registry's own
     * temporary failure, and a session the server closed (reconnect first — see SessionException).
     *
     * False for everything else, including a refusal you might be tempted to retry. Retrying
     * a 2302 cannot make the name free, and retrying a 2104 cannot pay for it; a loop that treats
     * every failure as transient turns one refusal into a rate-limit ban.
     */
    public function isRetryable(): bool
    {
        return in_array($this->eppCode, [
            ResultCode::COMMAND_FAILED,
            ResultCode::COMMAND_FAILED_SERVER_CLOSING,
            ResultCode::AUTHENTICATION_SERVER_CLOSING,
            ResultCode::SESSION_LIMIT_EXCEEDED_SERVER_CLOSING,
        ], true);
    }

    /**
     * The object the registry objected to, when it said which — the name in a batch of five that
     * was rejected, for instance.
     *
     * Null when the answer named nothing, which is common: not every refusal identifies a subject.
     */
    public function subject(): ?string
    {
        foreach ($this->response?->extValues() ?? [] as $ext) {
            if (($ext['text'] ?? '') !== '') {
                return $ext['text'];
            }
        }

        return null;
    }

    /**
     * Extra diagnostic text the registry attached, beyond the one-line message.
     *
     * @return string[]
     */
    public function reasons(): array
    {
        return $this->response?->errorReasons() ?? [];
    }
}
