<?php

declare(strict_types=1);

namespace EppTools;

/**
 * EPP result codes (RFC 5730 §3). Branch on Response::code() / CommandException::$eppCode
 * with these instead of bare numbers, e.g. `if ($e->eppCode === ResultCode::OBJECT_EXISTS)`.
 */
final class ResultCode
{
    // 1xxx — success
    public const SUCCESS = 1000;
    public const SUCCESS_PENDING = 1001;            // action queued; resolved later via poll
    public const SUCCESS_NO_MESSAGES = 1300;        // poll: queue empty
    public const SUCCESS_ACK_TO_DEQUEUE = 1301;     // poll: a message is waiting
    public const SUCCESS_END_SESSION = 1500;        // logout

    // 2000–2099 — protocol / syntax
    public const UNKNOWN_COMMAND = 2000;
    public const COMMAND_SYNTAX_ERROR = 2001;
    public const COMMAND_USE_ERROR = 2002;          // e.g. already logged in
    public const REQUIRED_PARAMETER_MISSING = 2003;
    public const PARAMETER_VALUE_RANGE_ERROR = 2004;
    public const PARAMETER_VALUE_SYNTAX_ERROR = 2005;

    // 2100–2199 — unimplemented / usage / billing
    public const UNIMPLEMENTED_PROTOCOL_VERSION = 2100; // login <version> must be 1.0
    public const UNIMPLEMENTED_COMMAND = 2101;
    public const UNIMPLEMENTED_OPTION = 2102;           // e.g. an unsupported login <lang>
    public const UNIMPLEMENTED_EXTENSION = 2103;        // extension not supported here (e.g. secDNS on a zone that forbids it)
    public const BILLING_FAILURE = 2104;            // insufficient funds
    public const NOT_ELIGIBLE_FOR_RENEWAL = 2105;
    public const NOT_ELIGIBLE_FOR_TRANSFER = 2106;

    // 2200–2299 — security
    public const AUTHENTICATION_ERROR = 2200;       // bad login
    public const AUTHORIZATION_ERROR = 2201;
    public const INVALID_AUTHORIZATION = 2202;      // wrong authInfo

    // 2300–2399 — object lifecycle
    public const OBJECT_PENDING_TRANSFER = 2300;
    public const OBJECT_NOT_PENDING_TRANSFER = 2301;
    public const OBJECT_EXISTS = 2302;
    public const OBJECT_DOES_NOT_EXIST = 2303;
    public const OBJECT_STATUS_PROHIBITS_OPERATION = 2304;
    public const OBJECT_ASSOCIATION_PROHIBITS_OPERATION = 2305;
    public const PARAMETER_VALUE_POLICY_ERROR = 2306;
    public const UNIMPLEMENTED_OBJECT_SERVICE = 2307;
    public const DATA_MANAGEMENT_POLICY_VIOLATION = 2308;

    // 2400+ — server
    public const COMMAND_FAILED = 2400;
    public const COMMAND_FAILED_SERVER_CLOSING = 2500;
    public const AUTHENTICATION_SERVER_CLOSING = 2501;
    public const SESSION_LIMIT_EXCEEDED_SERVER_CLOSING = 2502;
}

