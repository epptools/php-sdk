<?php

declare(strict_types=1);

namespace EppTools;

use Psr\Log\LoggerInterface;
use EppTools\Command\Contact;
use EppTools\Command\Domain;
use EppTools\Command\Host;
use EppTools\Command\Poll;
use EppTools\Exception\AuthenticationException;
use EppTools\Exception\CommandException;
use EppTools\Exception\ConfigException;
use EppTools\Exception\ConnectionException;

/**
 * EPP client for the Registry service. Open a connection, log in, then reach the
 * object commands through resource handlers — $client->domain(), ->contact(), ->host(),
 * ->poll(). Each command returns a {@see Response}. By default any EPP error code
 * (>= 2000) is thrown as a {@see CommandException}; call throwOnFailure(false) to inspect
 * codes yourself instead.
 *
 *     $client = new Client(Config::fromArray(['host' => 'epp.registry.example', 'clid' => 'your-clid', 'password' => '...']));
 *     $client->connect();
 *     $client->login();
 *     $avail = $client->domain()->check(['example.com.ua'])->availability();
 *     $client->logout();
 *     $client->disconnect();
 */
final class Client
{
    /**
     * Bounds of the RFC 5730 <pw> / <newPW> schema type (epp:pwType, minLength 6 / maxLength 16).
     * The login frame is always schema-validated, so anything outside this range is rejected as
     * an opaque 2001 with no hint about which field was wrong.
     */
    private const PW_MIN = 6;
    private const PW_MAX = 16;

    /**
     * Maximum password length when the server supports the Login Security extension (RFC 8807),
     * which carries the password outside the 16-character <pw> element. Applies only when the
     * server's <greeting> advertises the extension; otherwise PW_MAX applies.
     */
    private const PW_MAX_LOGINSEC = 128;

    /** Client version, sent to the server as <loginSec:app> when RFC 8807 is in use. */
    public const VERSION = '1.0.1';

    private Config $config;
    private Transport $connection;
    private ?LoggerInterface $logger;
    private ?Response $greeting = null;
    private bool $loggedIn = false;
    private bool $throwOnFailure = true;
    private int $tridCounter = 0;
    /** Middle clTRID segment — the OS process id, or a fallback token (set once in the ctor). */
    private string $processToken;

    private ?Domain $domain = null;
    private ?Contact $contact = null;
    private ?Host $host = null;
    private ?Poll $poll = null;

    public function __construct(Config $config, ?Transport $connection = null, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->connection = $connection ?? new Connection($config);
        $this->logger = $logger;
        // Middle clTRID segment: the OS process id. It matches the server's svTRID shape and
        // gives per-process uniqueness — ideal on shared/virtual hosting, where each request is
        // its own process — with NO CSPRNG dependency. If a host disables getmypid() via
        // disable_functions, fall back to a microtime-derived token (still no CSPRNG) so the SDK
        // degrades gracefully and never errors out.
        $pid = function_exists('getmypid') ? @getmypid() : false;
        $this->processToken = ($pid !== false && $pid !== null)
            ? (string) $pid
            : dechex(crc32(uniqid('', true)));
    }

    /** Open the connection in one step and log in. */
    public static function connectAndLogin(Config $config): self
    {
        $client = new self($config);
        $client->connect();
        $client->login();

        return $client;
    }

    /** Toggle automatic CommandException throwing on EPP error codes. */
    public function throwOnFailure(bool $throw = true): self
    {
        $this->throwOnFailure = $throw;

        return $this;
    }

    /** Attach (or clear) a PSR-3 logger; passwords/authInfo are masked before logging. */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function __destruct()
    {
        try {
            $this->connection->close();
        } catch (\Throwable $e) {
            // Never throw from a destructor.
        }
    }

    // --- session ---------------------------------------------------------------

    /** Open the TLS socket and read the unsolicited <greeting>. */
    public function connect(): Response
    {
        if ($this->config->host === '') {
            throw new ConfigException('Config: host must not be empty');
        }
        if (!$this->connection->isOpen()) {
            $this->connection->open();
        }
        $raw = $this->connection->readFrame();
        if ($this->logger !== null) {
            $this->logger->debug('EPP << greeting', ['frame' => $raw]);
        }
        $greeting = Response::fromXml($raw);
        if (!$greeting->isGreeting()) {
            // The first frame MUST be the <greeting> (RFC 5730). Accepting whatever arrives instead
            // would let a <response> left over from a half-open session — or a middlebox banner —
            // stand in for the service list, downgrading login() to the DEFAULT services:
            // advertising services this server does not offer and losing the extensions it does.
            throw new ConnectionException(sprintf(
                'First frame from %s:%d is not an EPP <greeting> (result %d: %s)',
                $this->config->host,
                $this->config->port,
                $greeting->code(),
                $greeting->message() ?? 'no message'
            ));
        }
        $this->greeting = $greeting;

        return $this->greeting;
    }

    public function greeting(): ?Response
    {
        return $this->greeting;
    }

    /** Send <hello>; the server replies with a fresh <greeting>. */
    public function hello(): Response
    {
        $this->connection->writeFrame(
            '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="' . Namespaces::EPP . '"><hello/></epp>'
        );
        $this->greeting = Response::fromXml($this->connection->readFrame());

        return $this->greeting;
    }

    /**
     * Authenticate. By default the login advertises exactly the services the server's
     * greeting offered (so it is never rejected for an unsupported service); override
     * with Config::$objUris / $extUris.
     *
     * Pass $newPassword to rotate the EPP password during login (RFC 5730 <newPW>); use
     * the new password for subsequent sessions once this returns success.
     */
    public function login(?string $newPassword = null): Response
    {
        // Catch the obvious config mistakes here, with a clear message, instead of letting
        // the server reject an empty <clID>/<pw> with a cryptic schema-validation 2001.
        if ($this->config->clid === '' || $this->config->password === '') {
            throw new ConfigException(sprintf(
                'login requires a non-empty clID and password (clID %s, password %s) — check your config/env',
                $this->config->clid !== '' ? 'set' : 'EMPTY',
                $this->config->password !== '' ? 'set' : 'EMPTY'
            ));
        }
        // Bounds that hold for every server are checked before connecting, so a misconfigured
        // password never opens a socket. Whether a password longer than PW_MAX is usable depends on
        // the server advertising RFC 8807, so that check happens once the greeting has been read.
        $this->assertPasswordBounds($this->config->password, 'Config::$password');
        if ($newPassword !== null) {
            $this->assertPasswordBounds($newPassword, 'the new password');
        }
        if ($this->greeting === null) {
            $this->connect();
        }

        $greetingObj = $this->greeting !== null ? $this->greeting->serviceObjUris() : [];
        $greetingExt = $this->greeting !== null ? $this->greeting->serviceExtUris() : [];
        $objUris = $this->config->objUris ?? ($greetingObj ?: Namespaces::DEFAULT_OBJ_URIS);
        $extUris = $this->config->extUris ?? ($greetingExt ?: Namespaces::DEFAULT_EXT_URIS);
        // The epp-1.0 base URI is not an object service and is never listed in <login>.
        $objUris = array_values(array_filter($objUris, static fn (string $u): bool => $u !== Namespaces::EPP));

        // Two separate decisions about the Login Security extension (RFC 8807), both needing the
        // server to advertise it: whether to TAKE PART (see Config::$loginSecurity — that is what
        // makes the server's security events come back) and whether a password has to TRAVEL in it,
        // which is forced only by one longer than the 16 characters the base <pw> element allows.
        $loginSecAvailable = in_array(Namespaces::LOGINSEC, $greetingExt, true);
        $this->assertPasswordFitsServer($this->config->password, 'Config::$password', $loginSecAvailable);
        if ($newPassword !== null) {
            $this->assertPasswordFitsServer($newPassword, 'the new password', $loginSecAvailable);
        }
        // Decided PER ELEMENT, not once for the frame. The sentinel means "the real value is in the
        // matching loginSec element", so it belongs only in an element whose value really was
        // relocated; putting it in one that still carries its own value points the server at
        // something that is not there. Rotation across the 16-character boundary is where the two
        // elements differ: changing a short password to a long one relocates newPW alone, and <pw>
        // must still carry the short password itself.
        $relocatePw = $loginSecAvailable && self::isOverBaseLimit($this->config->password);
        $relocateNewPw = $loginSecAvailable && $newPassword !== null && self::isOverBaseLimit($newPassword);
        // The block is also sent when nothing has to be relocated, because that is what makes the
        // server's security events reach you: it returns them only to a client that took part in
        // the extension, since announcing a URI proves nothing. Config::$loginSecurity turns this
        // off; a relocated password still sends the block, having no other way to travel.
        $useLoginSec = $relocatePw || $relocateNewPw || ($loginSecAvailable && $this->config->loginSecurity);

        $frame = $this->frame();
        $login = $frame->verb('login');
        $frame->epp($login, 'clID', $this->config->clid);
        $frame->epp($login, 'pw', $relocatePw ? Namespaces::LOGINSEC_SENTINEL : $this->config->password);
        if ($newPassword !== null) {
            $frame->epp($login, 'newPW', $relocateNewPw ? Namespaces::LOGINSEC_SENTINEL : $newPassword);
        }
        $options = $frame->epp($login, 'options');
        $frame->epp($options, 'version', '1.0');
        $frame->epp($options, 'lang', $this->config->lang);
        $svcs = $frame->epp($login, 'svcs');
        foreach ($objUris as $uri) {
            $frame->epp($svcs, 'objURI', $uri);
        }
        if ($extUris !== []) {
            $svcExt = $frame->epp($svcs, 'svcExtension');
            foreach ($extUris as $uri) {
                $frame->epp($svcExt, 'extURI', $uri);
            }
        }
        if ($useLoginSec) {
            // <extension> is a sibling of <login> within <command>. The password travels in
            // <loginSec:pw>; the <pw> element above carries the sentinel that points at it.
            $ext = $frame->extension();
            $block = $frame->ns($ext, Namespaces::LOGINSEC, 'loginSec:loginSec');
            $ua = $frame->ns($block, Namespaces::LOGINSEC, 'loginSec:userAgent');
            // app, tech and os in that order — the schema's userAgentType is a sequence, and a
            // registry support desk asked "which client, on what" answers both from one login.
            $frame->ns($ua, Namespaces::LOGINSEC, 'loginSec:app', 'EppTools PHP SDK ' . self::VERSION);
            $frame->ns($ua, Namespaces::LOGINSEC, 'loginSec:tech', 'PHP ' . PHP_VERSION);
            $frame->ns($ua, Namespaces::LOGINSEC, 'loginSec:os', PHP_OS_FAMILY);
            if ($relocatePw) {
                $frame->ns($block, Namespaces::LOGINSEC, 'loginSec:pw', $this->config->password);
            }
            if ($relocateNewPw) {
                $frame->ns($block, Namespaces::LOGINSEC, 'loginSec:newPW', (string) $newPassword);
            }
        }

        $response = $this->transact($frame->toXml());
        if ($response->code() !== 1000) {
            $message = sprintf('Login failed (EPP %d): %s', $response->code(), $response->message() ?? 'no message');
            // Only 2200 means the credentials are wrong. A login can also be refused because the
            // account is already at its session limit (2502), because the server is closing (2501),
            // because a service in <svcs> is not offered (2307), because this connection is already
            // logged in (2002), or over the protocol version (2100) — each with its own class and
            // its own remedy. Calling them all an authentication failure sends the reader to rotate
            // a password that was never the problem, and hides that the answer is to reconnect.
            throw $response->code() === ResultCode::AUTHENTICATION_ERROR
                ? new AuthenticationException($response->code(), $message, $response)
                : CommandException::forCode($response->code(), $message, $response);
        }
        $this->loggedIn = true;

        return $response;
    }

    public function logout(): Response
    {
        $frame = $this->frame();
        $frame->verb('logout');
        $response = $this->transact($frame->toXml()); // 1500; the server then closes the link
        $this->loggedIn = false;

        return $response;
    }

    public function disconnect(): void
    {
        $this->connection->close();
        $this->loggedIn = false;
    }

    public function isConnected(): bool
    {
        return $this->connection->isOpen();
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    // --- resource handlers -----------------------------------------------------

    public function domain(): Domain
    {
        return $this->domain ??= new Domain($this);
    }

    public function contact(): Contact
    {
        return $this->contact ??= new Contact($this);
    }

    public function host(): Host
    {
        return $this->host ??= new Host($this);
    }

    public function poll(): Poll
    {
        return $this->poll ??= new Poll($this);
    }

    /**
     * The registry's own extension namespace for this connection, or null if it offers none.
     *
     * Configuration first, then the greeting. Resolved here rather than in each command builder so
     * that one connection cannot end up sending two different answers — and so the whole question is
     * asked in exactly one place when a registry changes its URIs.
     */
    public function registryExtUri(): ?string
    {
        return $this->config->registryExtUri
            ?? Namespaces::registryExtension($this->greeting?->serviceExtUris() ?? []);
    }

    /** The registry's account-balance extension namespace for this connection, or null. */
    public function registryBalanceUri(): ?string
    {
        return $this->config->registryBalanceUri
            ?? Namespaces::registryBalance($this->greeting?->serviceExtUris() ?? []);
    }

    /**
     * The registry extension namespace, or an exception naming what the server actually offered.
     *
     * Called by the operations that CANNOT be expressed without it. Guessing a URI instead would
     * send an extension the server does not recognise, and an unrecognised extension is not
     * rejected — it is ignored, so the command half-succeeds and the part that mattered is missing
     * from the response with nothing to explain it.
     */
    public function requireRegistryExtUri(string $what): string
    {
        $uri = $this->registryExtUri();
        if ($uri !== null) {
            return $uri;
        }

        throw new ConfigException(sprintf(
            '%s needs the registry extension, which %s does not advertise. Advertised: %s. '
            . 'Set registryExtUri in the config if this registry uses a namespace this library '
            . 'cannot recognise.',
            $what,
            $this->config->host,
            implode(', ', $this->greeting?->serviceExtUris() ?? []) ?: '(no greeting read yet)',
        ));
    }

    /** Query the registrar account balance (creditLimit / balance / availableCredit). */
    public function balance(): Response
    {
        $uri = $this->registryBalanceUri();
        if ($uri === null) {
            throw new ConfigException(sprintf(
                'The balance extension is not advertised by %s. Advertised: %s. Set '
                . 'registryBalanceUri in the config if this registry uses a namespace this library '
                . 'cannot recognise.',
                $this->config->host,
                implode(', ', $this->greeting?->serviceExtUris() ?? []) ?: '(no greeting read yet)',
            ));
        }

        $frame = $this->frame();
        $frame->ns($frame->verb('info'), $uri, 'balance:info');

        return $this->request($frame);
    }

    // --- low-level (used by the resource handlers, and for bespoke frames) ------

    /** A new command frame with an auto-generated clTRID already stamped. */
    public function frame(): Frame
    {
        return Frame::command($this->nextClTrid());
    }

    /**
     * Send a frame (a {@see Frame} or raw XML string) and return the parsed response.
     * Throws CommandException on an EPP error code unless throwOnFailure(false) is set.
     *
     * @param string|Frame $frame
     */
    public function request($frame): Response
    {
        return $this->execute($frame instanceof Frame ? $frame->toXml() : $frame);
    }

    // --- internals -------------------------------------------------------------

    private function transact(string $xml): Response
    {
        if (!$this->connection->isOpen()) {
            throw new ConnectionException('Not connected — call connect() first');
        }
        if ($this->logger !== null) {
            $this->logger->debug('EPP >> request', ['frame' => $this->redact($xml)]);
        }
        $this->connection->writeFrame($xml);
        $raw = $this->connection->readFrame();
        if ($this->logger !== null) {
            $this->logger->debug('EPP << response', ['frame' => $this->redact($raw)]);
        }

        $response = Response::fromXml($raw);

        // The server echoes the clTRID (RFC 5730 §2.5) and this client generates a unique one per
        // command, so comparing them turns any stream desynchronisation from a SILENT
        // mis-attribution into a loud failure. Without it, a reply belonging to the previous command
        // is indistinguishable from this one's — and for a renew or a create that means booking the
        // wrong domain as done.
        //
        // The connection is closed, not just reported: once the offsets disagree, every later frame
        // on this stream is suspect too.
        // Compared against the CLAMPED form of what was sent: epp-1.0's trIDStringType is 3..64
        // characters, so a server that echoes a caller-supplied clTRID legitimately returns a
        // truncated or padded value. Comparing raw equality would reject those valid replies — the
        // check must catch a WRONG transaction, not a normalised one.
        $sentTrid = preg_match('~<clTRID>([^<]*)</clTRID>~', $xml, $m) === 1 ? $m[1] : null;
        $echoed = $response->clTRID();
        if ($sentTrid !== null && $echoed !== null && $echoed !== '') {
            $clean = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $sentTrid)), 0, 64);
            $expected = mb_strlen($clean) >= 3 ? $clean : str_pad($clean, 3, '-');
            if ($echoed !== $sentTrid && $echoed !== $expected) {
                $this->connection->close();

                throw new ConnectionException(
                    "Response does not belong to this command (sent clTRID {$sentTrid}, received {$echoed})"
                    . ' — the connection was desynchronised and has been closed.',
                );
            }
        }
        if ($this->logger !== null) {
            $this->logger->log(
                $response->isSuccess() ? 'info' : 'warning',
                'EPP result ' . $response->code(),
                ['code' => $response->code(), 'svTRID' => $response->svTRID(), 'clTRID' => $response->clTRID()]
            );
        }

        return $response;
    }

    private function execute(string $xml): Response
    {
        $response = $this->transact($xml);
        if ($this->throwOnFailure && !$response->isSuccess()) {
            // The message names the SUBJECT when the registry identified one. On a command carrying
            // five names, "EPP 2302: Object exists" leaves the reader to work out which of the five,
            // and the answer is sitting in <extValue> unread.
            $subject = null;
            foreach ($response->extValues() as $ext) {
                if (($ext['text'] ?? '') !== '') {
                    $subject = $ext['text'];
                    break;
                }
            }
            $message = sprintf('EPP %d: %s', $response->code(), $response->message() ?? 'command failed');
            if ($subject !== null) {
                $message .= sprintf(" ('%s')", $subject);
            }

            throw CommandException::forCode($response->code(), $message, $response);
        }

        return $response;
    }

    /** Mask passwords / authInfo (any namespace) before a frame is logged. */
    private function redact(string $xml): string
    {
        // Matches <pw> and <newPW> in any namespace prefix, including <loginSec:pw>. The opening
        // tag may carry attributes (`<domain:pw roid="D1-EXAMPLE">`), so the pattern accepts
        // anything up to '>': every such element is masked whatever attributes it carries.
        return (string) preg_replace(
            '~(<(?:[\w.-]+:)?(?:pw|newPW)(?:\s[^>]*)?>)(.*?)(</(?:[\w.-]+:)?(?:pw|newPW)\s*>)~s',
            '$1***$3',
            $xml
        );
    }

    /** True when the password is too long for the base <pw> element and needs RFC 8807. */
    private static function isOverBaseLimit(string $password): bool
    {
        return self::charLength($password) > self::PW_MAX;
    }

    /** Length in characters — the EPP schema counts characters, not bytes. */
    private static function charLength(string $password): int
    {
        return function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    }

    /**
     * Validates the bounds that apply to every server. Called before connecting.
     *
     * @throws ConfigException when the password is shorter than PW_MIN or longer than PW_MAX_LOGINSEC
     */
    private function assertPasswordBounds(string $password, string $label): void
    {
        $length = self::charLength($password);
        if ($length < self::PW_MIN || $length > self::PW_MAX_LOGINSEC) {
            throw new ConfigException(sprintf(
                '%s must be %d-%d characters long (got %d)',
                $label,
                self::PW_MIN,
                self::PW_MAX_LOGINSEC,
                $length
            ));
        }
    }

    /**
     * Validates the password against what this server supports. Called after the greeting is read
     * and before the login frame is sent.
     *
     * @throws ConfigException when the password exceeds PW_MAX and the server does not advertise
     *                         the Login Security extension
     */
    private function assertPasswordFitsServer(string $password, string $label, bool $loginSecAvailable): void
    {
        if (!$loginSecAvailable && self::isOverBaseLimit($password)) {
            throw new ConfigException(sprintf(
                '%s is %d characters, but this server does not advertise %s — the EPP <pw> schema type '
                . 'allows at most %d, so the server would answer a bare 2001',
                $label,
                self::charLength($password),
                Namespaces::LOGINSEC,
                self::PW_MAX
            ));
        }
    }

    private function nextClTrid(): string
    {
        $this->tridCounter++;

        // A clTRID is a human-correlatable transaction label (not a secret), so it mirrors the
        // server's svTRID shape: a UTC timestamp for "when", the process token to group one
        // process's commands, and a monotonic counter for order. Prefix is Config::$clTRIDPrefix.
        return sprintf(
            '%s-%s-%s-%04d',
            $this->config->clTRIDPrefix,
            gmdate('YmdHis'),
            $this->processToken,
            $this->tridCounter
        );
    }
}



