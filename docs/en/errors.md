# Errors

Every failure this library raises extends `EppTools\Exception\EppException`, so one `catch` handles
everything. Beyond that, **a class exists where the right next step differs — and nowhere else.**
The taxonomy is not a description of what went wrong; it is a description of what to do about it.

```php
use EppTools\Exception\EppException;

try {
    $client->connect();
    $client->login();
    $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
    $client->logout();
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
} finally {
    $client->disconnect();
}
```

`EppException` extends `\RuntimeException`, so a catch-all higher up your stack still sees it.

## The hierarchy

```
EppException
├── ValidationException     a value in THIS call is unusable; nothing was sent
├── ConfigException         the client is set up wrong; every call fails until it is fixed
├── ConnectionException     transport: TLS, socket, timeout, framing. The server never answered
└── CommandException        the registry refused, with a result code
    ├── InsufficientFundsException   2104
    ├── AuthenticationException      2200
    ├── AuthorizationException       2201, 2202
    ├── ObjectExistsException        2302
    ├── ObjectDoesNotExistException  2303
    ├── ObjectStatusException        2304, 2305
    ├── PolicyException              2306, 2308
    └── SessionException             2500, 2501, 2502
```

| Catch | Raised when | Codes | What to do next |
|---|---|---|---|
| `ValidationException` | a value in this call cannot be used; **nothing was sent** | — | fix the arguments and call again |
| `ConfigException` | the client itself is misconfigured | — | fix the deployment; every call fails until then |
| `ConnectionException` | TLS handshake, connect, read/write timeout, framing, malformed XML | — | the connection is closed. Reconnect, or fix the certificate bundle |
| `InsufficientFundsException` | the account cannot pay | `2104` | **stop the batch**, top up, resume |
| `AuthenticationException` | the credentials were rejected | `2200` | fix the clID/password. Do not retry with the same ones |
| `AuthorizationException` | the object is not yours, or the `authInfo` is wrong | `2201`, `2202` | check the sponsor, or the transfer code |
| `ObjectExistsException` | the name or handle is taken | `2302` | pick another, or find out who holds it |
| `ObjectDoesNotExistException` | no such name, handle or host | `2303` | a stale identifier or a typo |
| `ObjectStatusException` | a status or an association is in the way | `2304`, `2305` | read the object, clear the blocker, repeat |
| `PolicyException` | the registry's own rules refuse this value | `2306`, `2308` | change the request; retrying cannot help |
| `SessionException` | the server is ending the session | `2500`–`2502` | reconnect, log in again, then retry |
| `CommandException` | any other code ≥ 2000 | all the rest | branch on `$e->eppCode` |

Catch from the specific to the general — PHP takes the first matching `catch`, so a
`catch (CommandException)` written above `catch (InsufficientFundsException)` swallows the one you
wanted to treat differently.

---

## A bad argument is not a bad configuration

`ValidationException` and `ConfigException` both mean "nothing was sent". They are separate classes
because they need opposite responses, and sharing one class would leave a service guessing.

| | `ValidationException` | `ConfigException` |
|---|---|---|
| What is wrong | the arguments of **one call** | the **client**, for every call |
| Who caused it | whoever made this request | whoever deployed the service |
| Who should hear about it | the caller — answer them | your operators — alert them |
| Does the next call work? | yes, with different arguments | no, not until the deployment changes |

Guessing wrong here reports an operator's own misconfiguration to a customer as their mistake — "your
request was invalid" for a missing password in an environment file.

**`ValidationException` — the arguments of this call:**

```php
$client->domain()->create('example.com.ua', ['years' => 1, 'secdns' => [...]]);
// ValidationException: domain:create does not accept 'secdns' (did you mean 'secDNS'?).
// Accepted: authInfo, contacts, fee, license, nameServers, nameservers, registrant, secDNS, years.
```

Others of the same kind: a mixture of the two nameserver models in one command, a fee amount that is
not a plain decimal, an empty contact role, an empty e-mail on a contact create, a `newName` on a host
update (the registry does not implement renames), `authInfo` and `clearAuthInfo` in the same `chg`,
`removeAllDnssec()` combined with removing a named record, a fee query over twenty entries, and a
second `send()` on a builder.

The common thread is the one the class name states: **nothing was sent**. No frame was built, no
money moved, nothing at the registry changed.

**`ConfigException` — the client, for every call:**

```php
$client->connect();
// ConfigException: Config: host must not be empty

$client->login();
// ConfigException: login requires a non-empty clID and password (clID set, password EMPTY)
//                  — check your config/env
```

The password length rules also arrive here, and they arrive at two moments. The bounds that hold for
every server are checked before a socket is opened; whether a password longer than 16 characters is
usable depends on the server advertising RFC 8807, so that one is checked once the greeting has been
read:

```php
// ConfigException: Config::$password must be 6-128 characters long (got 4)
// ConfigException: Config::$password is 40 characters, but this server does not advertise
//                  urn:ietf:params:xml:ns:epp:loginSec-1.0 — the EPP <pw> schema type allows
//                  at most 16, so the server would answer a bare 2001
```

Both are deployment facts, not request facts. See [Session](session.md) for the whole of it.

---

## ConnectionException

The transport failed and **the server never answered** — or the link broke mid-exchange. There is no
result code to read, because no result arrived.

| Message | What happened |
|---|---|
| `Cannot connect to epp.registry.example:700 — …` | the socket or the TLS handshake failed. The tail is the real reason PHP reported, not a generic one |
| `Cannot connect to epp.registry.example:700 — … certificate verify failed` | `caFile` is unset or points at the wrong bundle |
| `Read timed out` / `Write timed out` | `readTimeout` elapsed with the command in flight |
| `Write failed (connection closed?)` | the peer went away before the frame was fully written |
| `Not connected` | the transport has no open socket — it was closed by an earlier failure |
| `Connection closed while reading` | the peer went away mid-frame |
| `Invalid EPP frame length: …` | the RFC 5734 length prefix was nonsense |
| `Server returned malformed XML` | the frame did not parse |
| `Not connected — call connect() first` | no socket; the session was never opened, or was closed by an earlier failure |
| `Response does not belong to this command (sent clTRID …, received …)` | the stream desynchronised |
| `First frame from … is not an EPP <greeting>` | something other than a greeting answered the connect |

**Most of these close the connection, deliberately.** A partial read leaves the byte stream at an
unknown offset, so the *next* command would read *this* command's reply — an off-by-one across
billable transforms, where a renew of `example2.com.ua` returns `1000` carrying `example1.com.ua`'s expiry date, and
the registrar books the wrong domain as renewed. Closing is what makes the failure terminal instead of
silent: `isConnected()` goes false and every later call refuses rather than reading from the middle of
a frame.

The `clTRID` echo check is the same idea one level up. The server echoes the transaction id you sent
(RFC 5730 §2.5), this client generates a unique one per command, and a mismatch means the reply
belongs to some other command. See [Commands](commands.md#the-echo-check).

**A `ConnectionException` during a transform is the one failure whose outcome you do not know.** It is
the whole of the [last section](#when-a-transform-fails-and-you-do-not-know-whether-it-happened) on
this page.

A certificate-verification failure is the commonest first-run problem, and the fix is never
`verifyPeer: false` — [Session](session.md#when-the-handshake-fails) has the diagnosis.

---

## CommandException

The registry answered, and the answer was a refusal: a result code of 2000 or more.

```php
class CommandException extends EppException
{
    public int $eppCode;         // the EPP result code
    public ?Response $response;  // the full parsed reply, when one was received
}
```

| Member | Returns |
|---|---|
| `$e->eppCode` | the result code as an `int`. `$e->getCode()` gives the same number |
| `$e->response` | the whole [`Response`](responses.md) — everything the reply carried |
| `$e->isRetryable(): bool` | whether sending the very same command again could succeed |
| `$e->subject(): ?string` | the object the registry objected to, when it named one |
| `$e->reasons(): array` | the extra diagnostic prose, as `string[]` |
| `CommandException::forCode(int $code, string $message, ?Response $response = null): self` | builds the most specific subclass for a code |

```php
use EppTools\Exception\CommandException;
use EppTools\ResultCode;

try {
    $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
} catch (CommandException $e) {
    $e->eppCode;                       // 2302
    $e->getMessage();                  // "EPP 2302: Object exists ('example.com.ua')"
    $e->subject();                     // 'example.com.ua'
    $e->reasons();                     // ['Domain is registered by another registrar']
    $e->response?->svTRID();           // the registry's transaction id — store it
    $e->eppCode === ResultCode::OBJECT_EXISTS;   // true
}
```

**`subject()` earns its keep on a command carrying several objects.** "EPP 2302: Object exists" after
a check of five names leaves you to work out which of the five, and the answer is sitting unread in
`<extValue>`. The library reads the first non-empty one into the exception message too, which is why
the message above ends in the name.

`forCode()` is the single place a code becomes a class, so the mapping cannot drift between commands.
It is public because it is also useful to you: with `throwOnFailure(false)` you can turn a `Response`
you decided to reject into the same exception the library would have raised.

`$e->response` is `null` only when no reply was parsed. Everywhere else it is the full response —
`errorReasons()`, `extValues()`, `svTRID()` and the raw frame are all there for a log.

### InsufficientFundsException (2104)

```php
use EppTools\Exception\InsufficientFundsException;

foreach ($namesToRegister as $name) {
    try {
        $client->domain()->createBuilder($name)->years(1)->registrant('C-0001')->send();
    } catch (InsufficientFundsException $e) {
        // Not this name's problem — the account's. Carrying on produces the same failure for
        // every remaining name, at one round trip each.
        alertBilling($e->getMessage());
        break;
    }
}
```

Nothing is wrong with the request, and **every subsequent billable command fails the same way** until
the balance is topped up. It is the one refusal that says something about the queue rather than about
the item, which is why it has its own class: a batch that treats it like any other error grinds
through the rest of its work producing identical failures, and the operator reading the log has to
work out that the first one was the only real event.

Nothing was registered and nothing was charged. Top up, then resume from where you stopped. See
[Balance](balance.md) for checking before you start.

### AuthenticationException (2200) and AuthorizationException (2201 / 2202)

They sound alike and mean opposite things.

- **`AuthenticationException` is the session failing to log in.** `2200`, and only `2200`. A server
  can refuse a login for other reasons — the account is at its session limit (`2502`), the server is
  closing (`2501`), a service you announced is not offered (`2307`), this connection is already logged
  in (`2002`), the protocol version (`2100`) — and each arrives as its own class with its own remedy.
  Calling all of them an authentication failure sends you to rotate a password that was never the
  problem.
- **`AuthorizationException` is one object being out of reach** while the session is perfectly fine.
  Either it belongs to another registrar (`2201`) or the `authInfo` you supplied does not match
  (`2202`). Never retry with the same credentials.

A login failure is raised **whatever `throwOnFailure` says**, because a login that failed is not a
session you can carry on in.

### The object-lifecycle classes

| Class | Codes | The situation | The fix |
|---|---|---|---|
| `ObjectExistsException` | `2302` | somebody holds the name or handle — you, or another registrar | pick another. Retrying cannot make it free |
| `ObjectDoesNotExistException` | `2303` | the name, handle or host is not in the registry | a stale identifier or a typo |
| `ObjectStatusException` | `2304`, `2305` | a `clientHold`, a pending transfer, a nameserver still used by a domain | read the object, clear the blocker, repeat — **the same request then succeeds** |
| `PolicyException` | `2306`, `2308` | the command is well-formed and you may send it; the registry will not accept this value | change the request |

`ObjectStatusException` is the only one of the four where the same frame is worth sending again,
and only after something has changed:

```php
use EppTools\Exception\ObjectStatusException;

try {
    $client->domain()->delete('example.com.ua');
} catch (ObjectStatusException $e) {
    $info = $client->domain()->info('example.com.ua');
    echo implode(', ', $info->statuses()), "\n";           // e.g. clientDeleteProhibited
    echo implode(', ', $info->subordinateHosts()), "\n";   // or hosts still living under it
}
```

Racing another registrar for a dropping name is the one case where `ObjectExistsException` is
expected rather than exceptional.

### SessionException (2500 / 2501 / 2502)

The server is ending the session: too many concurrent sessions, an idle timeout, or a shutdown. The
connection is gone or going.

**The command itself may be perfectly good**, so this is one of the few failures where retrying is
right — on a *new* connection, after a pause:

```php
use EppTools\Exception\SessionException;

try {
    $r = $client->domain()->info('example.com.ua');
} catch (SessionException $e) {
    $client->disconnect();
    $client->connect();
    $client->login();
    $r = $client->domain()->info('example.com.ua');   // a read: safe to repeat
}
```

Repeat a **read** freely. Before repeating a transform, read
[the unknown-outcome rule](#when-a-transform-fails-and-you-do-not-know-whether-it-happened): a `2502`
arriving before the reply and a `2502` arriving instead of it look the same from here.

---

## What is retryable, and why most things are not

```php
public function isRetryable(): bool
```

**True for exactly four codes**, and false for everything else:

| Code | Constant | Why retrying can help |
|---|---|---|
| `2400` | `COMMAND_FAILED` | the registry's own temporary failure. Nothing about the request is wrong |
| `2500` | `COMMAND_FAILED_SERVER_CLOSING` | the session ended, not the command. Reconnect first |
| `2501` | `AUTHENTICATION_SERVER_CLOSING` | likewise |
| `2502` | `SESSION_LIMIT_EXCEEDED_SERVER_CLOSING` | too many sessions at that moment. Reconnect, after a pause |

The rule behind the list: **retryable means the failure was about the moment, not about the request.**

It is deliberately false for everything else, including refusals you might be tempted to retry.
Retrying a `2302` cannot make the name free, a `2104` cannot pay for it, a `2306` cannot change the
registry's policy, and a `2303` cannot conjure the object. A loop that treats every failure as
transient turns one refusal into a rate-limit ban — which the registry answers with a `2502`, or with
a firewall.

```php
use EppTools\Exception\CommandException;

try {
    $client->domain()->info('example.com.ua');
} catch (CommandException $e) {
    if (!$e->isRetryable()) {
        throw $e;                      // retrying cannot change the answer
    }
    $retryLater[] = 'example.com.ua';  // and back off before you try
}
```

Back off between attempts, and cap them. Three tries with a growing pause is an integration; an
uncapped loop is an outage of your own making.

**`isRetryable()` speaks about the command, not about your bookkeeping.** For a `create`, a `renew` or
a `transfer`, "could this succeed if sent again" is not the same question as "is it safe to send
again" — see below.

---

## Turning throwing off

By default every code ≥ 2000 is raised. That is what makes a straight-line integration correct by
default: you cannot forget to check a code you never see.

```php
use EppTools\ResultCode;

$client->throwOnFailure(false);

$r = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
if ($r->code() === ResultCode::OBJECT_EXISTS) {
    $taken[] = 'example.com.ua';
} elseif (!$r->isSuccess()) {
    throw new RuntimeException($r->message() ?? 'create failed');
}
```

What it does **not** turn off:

- `ConnectionException` — the server never answered, so there is no code to read.
- `ValidationException` and `ConfigException` — nothing was sent at all.
- `AuthenticationException` and the other login refusals from `login()`.
- The `CommandException` `poll()->drain()` raises when a reply is neither a notice nor an empty queue.
  Reading a refusal as a drained queue would report success while nothing had been read.

Full treatment in [Commands](commands.md#the-throwonfailure-switch).

---

## Result codes

`EppTools\ResultCode` has a named constant for every code. Branch on those rather than on bare
numbers: `$e->eppCode === ResultCode::OBJECT_EXISTS` says what it means.

### Success — 1xxx

| Code | Constant | Meaning |
|---|---|---|
| `1000` | `SUCCESS` | done |
| `1001` | `SUCCESS_PENDING` | accepted; the registry completes it offline and reports back via [poll](poll.md) |
| `1300` | `SUCCESS_NO_MESSAGES` | poll: the queue is empty |
| `1301` | `SUCCESS_ACK_TO_DEQUEUE` | poll: a message is waiting |
| `1500` | `SUCCESS_END_SESSION` | the answer to `logout` |

**`1001` is the one that catches people out.** It is a success code, so `isSuccess()` is true and
nothing is raised — and the object is *not* yet created, renewed or transferred. Test `isPending()`
before you record anything as done, and match the verdict back by `svTRID` when the poll notice
arrives.

### Protocol and syntax — 2000–2099

| Code | Constant | Meaning | Exception |
|---|---|---|---|
| `2000` | `UNKNOWN_COMMAND` | the server does not recognise the command | `CommandException` |
| `2001` | `COMMAND_SYNTAX_ERROR` | the frame failed schema validation, usually naming nothing | `CommandException` |
| `2002` | `COMMAND_USE_ERROR` | the command is not valid in this state — e.g. already logged in | `CommandException` |
| `2003` | `REQUIRED_PARAMETER_MISSING` | something mandatory was absent | `CommandException` |
| `2004` | `PARAMETER_VALUE_RANGE_ERROR` | a value is out of range — **including a fee cap below the real price** | `CommandException` |
| `2005` | `PARAMETER_VALUE_SYNTAX_ERROR` | a value is syntactically invalid | `CommandException` |

### Unimplemented and billing — 2100–2199

| Code | Constant | Meaning | Exception |
|---|---|---|---|
| `2100` | `UNIMPLEMENTED_PROTOCOL_VERSION` | the login `<version>` must be 1.0 | `CommandException` |
| `2101` | `UNIMPLEMENTED_COMMAND` | this server does not implement the command | `CommandException` |
| `2102` | `UNIMPLEMENTED_OPTION` | e.g. a session language it does not offer | `CommandException` |
| `2103` | `UNIMPLEMENTED_EXTENSION` | the extension is not available here — e.g. DNSSEC on a zone that forbids it | `CommandException` |
| `2104` | `BILLING_FAILURE` | insufficient funds. Nothing was done | `InsufficientFundsException` |
| `2105` | `NOT_ELIGIBLE_FOR_RENEWAL` | including a `curExpDate` that does not match | `CommandException` |
| `2106` | `NOT_ELIGIBLE_FOR_TRANSFER` | the object cannot be moved | `CommandException` |

A `2105` on a renew means the expiry is not what you thought. Re-read it with `info()`; it is never a
reason to send the same frame again.

### Security — 2200–2299

| Code | Constant | Meaning | Exception |
|---|---|---|---|
| `2200` | `AUTHENTICATION_ERROR` | the login itself failed | `AuthenticationException` |
| `2201` | `AUTHORIZATION_ERROR` | the object is not yours to act on | `AuthorizationException` |
| `2202` | `INVALID_AUTHORIZATION` | the `authInfo` is wrong | `AuthorizationException` |

### Object lifecycle — 2300–2399

| Code | Constant | Meaning | Exception |
|---|---|---|---|
| `2300` | `OBJECT_PENDING_TRANSFER` | a transfer is already pending | `CommandException` |
| `2301` | `OBJECT_NOT_PENDING_TRANSFER` | nothing pending to approve, reject, cancel or query | `CommandException` |
| `2302` | `OBJECT_EXISTS` | already registered | `ObjectExistsException` |
| `2303` | `OBJECT_DOES_NOT_EXIST` | no such object | `ObjectDoesNotExistException` |
| `2304` | `OBJECT_STATUS_PROHIBITS_OPERATION` | a status is in the way | `ObjectStatusException` |
| `2305` | `OBJECT_ASSOCIATION_PROHIBITS_OPERATION` | an association is in the way — a linked contact, a subordinate host | `ObjectStatusException` |
| `2306` | `PARAMETER_VALUE_POLICY_ERROR` | registry policy refuses this value | `PolicyException` |
| `2307` | `UNIMPLEMENTED_OBJECT_SERVICE` | the object service, or the zone, is not served here | `CommandException` |
| `2308` | `DATA_MANAGEMENT_POLICY_VIOLATION` | the change would violate the registry's data policy | `PolicyException` |

### Server — 2400+

| Code | Constant | Meaning | Exception |
|---|---|---|---|
| `2400` | `COMMAND_FAILED` | the registry could not complete it. **Retryable** | `CommandException` |
| `2500` | `COMMAND_FAILED_SERVER_CLOSING` | failed, and the session is ending. **Retryable after reconnecting** | `SessionException` |
| `2501` | `AUTHENTICATION_SERVER_CLOSING` | likewise | `SessionException` |
| `2502` | `SESSION_LIMIT_EXCEEDED_SERVER_CLOSING` | too many concurrent sessions | `SessionException` |

---

## When a transform fails and you do not know whether it happened

A read timeout, a dropped connection, or a `2500` in the middle of a `create`, a `renew`, a
`transfer` or a `restore` leaves a genuinely **unknown outcome**: the registry may have carried the
command out and billed you before the reply was lost. This library cannot tell the difference, and
neither can you from the exception. The command either happened or it did not, and the frame that
would have said so never arrived.

**Do not simply retry. A blind retry is how a domain gets registered — and paid for — twice.**

Ask the registry what is true instead:

| The command that failed | How to find out what happened |
|---|---|
| `domain()->create()` | `domain()->info($name)`. It exists, or it draws `2303` |
| `contact()->create()` | `contact()->info($id)` |
| `contact()->createAuto()` | **you cannot look it up by id** — the handle only ever appeared in the reply you lost. Reconcile from your own records before calling it again; every call mints a fresh handle, so a retry is a second contact |
| `domain()->renew()` | compare `info()->expiryDate()` against what you expected. The renewal either moved it or it did not |
| `domain()->transfer('request')` | `domain()->transfer('query', $name)` — `2300` while one is pending, `2301` when none is |
| `domain()->restore()` | `info()->rgpStatus()`. A restored domain has left `redemptionPeriod` |
| `domain()->update()` / `delete()` | `info()` and compare with what you sent |

A reconciler, written once:

```php
use EppTools\Exception\ConnectionException;
use EppTools\Exception\ObjectDoesNotExistException;

try {
    $r = $client->domain()->create($name, ['years' => 1, 'registrant' => 'C-0001', 'fee' => $quote]);
    $orders->recordCreated($name, $r->svTRID(), $r->expiryDate(), $r->feeAmount());
} catch (ConnectionException $e) {
    // The reply was lost. The command may have succeeded, and may have been charged.
    $orders->markUnknown($name, $e->getMessage());

    $client->disconnect();
    $client->connect();
    $client->login();

    try {
        $info = $client->domain()->info($name);
        if ($info->sponsor() === 'EXAMPLE') {
            // It happened. Record it from the registry's own answer, not from your assumptions.
            $orders->recordCreated($name, $info->svTRID(), $info->expiryDate(), null);
        } else {
            // Registered, but not by us. This is not ours to retry.
            $orders->escalate($name, 'held by ' . $info->sponsor());
        }
    } catch (ObjectDoesNotExistException) {
        // 2303 — it genuinely did not happen. NOW a retry is safe.
        $orders->readyToRetry($name);
    }
}
```

Three things that make it correct rather than merely careful:

- **Reconnect before reconciling.** The connection that lost the reply was closed by the failure, and
  a stream at an unknown offset cannot be trusted to answer the question you are about to ask.
- **`2303` is the only proof of a non-event.** Anything else — a timeout on the `info`, a `2201`, a
  name held by somebody else — is still unknown, and unknown is not "no".
- **The fee is part of the unknown.** A create that succeeded was charged, whether or not you saw the
  reply. Reconcile the money from the registry's answer or from your statement, not from what your
  own code believed it had done.

The `1001` case is not this case: a pending action is a *known* outcome — accepted, and completing
offline. Store the `svTRID` and match the verdict when the [poll notice](poll.md#the-outcome-of-a-pending-action)
arrives. What must never happen is treating a `1001` as a failure and re-sending, which is the same
double registration by another route.

**A failure whose outcome you cannot determine deserves an operator's attention, not an automatic
second attempt.** Queue it for a human, keep the `clTRID` you sent, and stop.

---

## Reporting a problem

Include the **svTRID** from the response and the **clTRID** your client sent. Together they identify
the exact transaction in the registry's logs, which is what makes a report answerable without a round
trip.

```php
use EppTools\Exception\CommandException;

try {
    $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
} catch (CommandException $e) {
    $log->error('EPP refused', [
        'code'    => $e->eppCode,
        'subject' => $e->subject(),
        'reasons' => $e->reasons(),
        'svTRID'  => $e->response?->svTRID(),
        'clTRID'  => $e->response?->clTRID(),
    ]);
}
```

Send the frames too if you can, but **redact `<pw>`, `<newPW>` and `<authInfo>` first**: those are
live credentials, and the library masks them in its own logs for the same reason. `raw()` on a
response is the unmasked frame.

Questions about the library, a frame the registry rejected, or a bug: **https://github.com/epptools/php-sdk/issues**.
Account and billing questions go to your registry account manager.

---

See also: [Commands](commands.md) · [Domains](domains.md) · [Balance & prices](balance.md) ·
[Poll](poll.md) · [Responses](responses.md)

[← Manual index](README.md)
