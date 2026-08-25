# Poll

The registry does not call you back. Everything that happens outside your own command flow — a
transfer somebody requested, the outcome of an operation the registry processed offline, an
approaching expiry, a low balance — is put in a per-registrar **message queue**, and you read it with
the `<poll>` command (RFC 5730 §2.9.2.3).

Reached through `$client->poll()`. Everything here assumes a connected, logged-in client — see
[Session](session.md).

**Drain the queue on a schedule.** A queue nobody reads is a transfer request nobody answered, and
past its deadline the registry decides for you.

## The methods

| Method | EPP command |
|---|---|
| `request(): Response` | `<poll op="req"/>` |
| `ack(string $messageId): Response` | `<poll op="ack" msgID="…"/>` |
| `drain(callable $handler, int $limit = 0): int` | `req` → your callback → `ack`, repeated |

---

## request

```php
public function request(): Response
```

**On the wire:** `<command><poll op="req"/>`. It reads the head of the queue **without removing it**.
Calling it twice returns the same notice twice.

Two success codes answer it, and the difference is the whole protocol:

| code | meaning |
|---|---|
| `1301` | a message is waiting; the notice is in this frame |
| `1300` | the queue is empty |

```php
$msg = $client->poll()->request();

if ($msg->messageId() !== null) {
    $msg->messageId();          // '10021' — the id you pass to ack()
    $msg->messageCount();       // 3 — how many remain, this one included
    $msg->queueMessage();       // 'Transfer requested.' — the NOTICE text
    $msg->queueMessageLang();   // 'uk' | 'ru' | 'en'
    $msg->queueDate();          // when the registry queued it
}
```

**Read `queueMessage()`, not `message()`.** They are different elements. `message()` is
`<result><msg>` — the command-result banner, which on every waiting notice reads "Command completed
successfully; ack to dequeue". `queueMessage()` is `<msgQ><msg>`, the notice itself. A client that
logs `message()` records that constant string for every event it ever received and discards the
content, and an ack then destroys the original.

`queueMessageLang()` reports the language the notice was rendered in. It is a property of the notice,
not of your session, so do not assume it matches the language you logged in with.

`messageCount()` counts what is in the queue including the notice you are holding, so it reaches `1`
on the last one and the following `request()` answers `1300`.

---

## ack

```php
public function ack(string $messageId): Response
```

**On the wire:** `<command><poll op="ack" msgID="…"/>`.

**An ack deletes the notice at the registry permanently. There is no way to get it back.** The reply
carries the new queue head: `1301` with the next `messageId()` and `messageCount()` while messages
remain, `1300` once the queue is empty.

```php
$next = $client->poll()->ack('10021');
echo $next->messageCount(), " remaining\n";
```

The order is the whole point:

```php
// Correct: the notice is safe on your side before it stops existing on theirs.
$msg = $client->poll()->request();
if ($msg->messageId() !== null) {
    store($msg->queueMessage(), $msg->raw());   // if this throws, the notice is still queued
    $client->poll()->ack($msg->messageId());
}
```

A loop that acks first and processes second loses every notice whose processing fails — a transfer
request, the outcome of a pending create — with nothing left to retry from and no record that
anything was lost. Store first, acknowledge second.

There is no hurry about the second half. A message you have been given stays acknowledgeable even if
the registry's delivery-retention window elapses while you hold it: retention governs delivery, not
your acknowledgement. Read-then-store-then-ack-later is the contract, and "later" is allowed to fall
outside the window.

---

## drain

```php
public function drain(callable $handler, int $limit = 0): int
```

The loop above, written once and correctly. Each notice is handed to your callback and acknowledged
**only after the callback returns**. Returns the number of notices your callback processed
successfully.

```php
use EppTools\Response;

$processed = $client->poll()->drain(function (Response $notice): void {
    store($notice->messageId(), $notice->queueMessage(), $notice->pendingActionData());
});

echo "processed {$processed} notices\n";
```

Four things this guarantees, each of which is a decision:

- **If your callback throws, the notice is not acked.** It stays at the head of the queue and the
  exception reaches you. Fix the cause and drain again; nothing was lost. The corollary is that a
  callback which always throws sees the same notice every time — deliberately, because the
  alternative is discarding it.
- **Delivery is at least once.** If the acknowledgement itself fails — the connection drops between
  your callback returning and the ack landing — the notice is still in the queue and the next drain
  hands it to you again. Make the callback idempotent and use `messageId()` as the de-duplication
  key: it is the registry's own identifier for that notice.
- **Only `1300` ends the loop.** Inferring "empty" from the absence of a notice would make a refusal
  — the session closed, the account suspended — look exactly like a drained queue, and the loop would
  report success while nothing had been read. A reply that is neither a notice nor `1300` raises a
  `CommandException`. That holds even with `throwOnFailure(false)`.
- **`$limit` bounds the work.** `drain($handler, 50)` stops after fifty notices. `0` means "until the
  queue is empty", which is right for a queue you keep up with and wrong for one that fills faster
  than you drain it — that call would never return.

```php
// A cron-friendly pass: bounded work, and a de-duplication key that survives a redelivery.
$client->poll()->drain(function (Response $notice): void {
    if (alreadySeen($notice->messageId())) {
        return;                     // returning normally still acks it — which is what you want
    }
    handle($notice);
    markSeen($notice->messageId());
}, 200);
```

---

## What the notices carry

Every notice has the `<msgQ>` envelope — id, count, date, text — and most carry a structured payload
in `<resData>` as well. The payload is read with the same accessors as the equivalent command
response, so one parser serves both.

| Notice | Payload | Read it with |
|---|---|---|
| Transfer requested / approved / rejected / cancelled | `trnData` | `transfer()`, `transferStatus()`, `objectName()` |
| Domain registered, renewed, deleted; restore outcome | `infData` | `objectName()`, `expiryDate()`, `statuses()`, `rgpStatus()` |
| The outcome of a deferred (`1001`) operation | `panData` | `pendingActionData()` |
| Low balance | `balance:infData` | `balance()`, `currentBalance()` |

### A transfer request

```php
$t = $notice->transfer();
// ['status' => 'pending', 'requestedBy' => 'DELTA', 'requestedAt' => '2026-04-01T09:15:00Z',
//  'actingClient' => 'EXAMPLE', 'actBy' => '2026-04-06T09:15:00Z', 'expiryDate' => '2028-04-01T09:15:00Z']
```

`actBy` is the deadline, and it is the field that costs money: **silence completes a transfer.** Past
that date the registry approves it. Treat a transfer notice as something to answer — see
[Domains → transfer](domains.md#transfer).

### The outcome of a pending action

This is how an operation that answered `1001` finally reports back. You send a create, get `1001`
and an `svTRID`; some time later a notice carries the verdict.

```php
$pan = $notice->pendingActionData();
// ['object' => 'example.com.ua', 'success' => true,
//  'clTRID' => 'SRV-20260401091500-24191-0007', 'svTRID' => 'SRV-…', 'date' => '2026-04-01T10:00:00Z']

if ($pan !== null) {
    if (!$pan['success']) {
        markFailed($pan['svTRID']);   // the ORIGINAL command's svTRID, not this notice's
        return;
    }
    markCompleted($pan['svTRID'], $pan['object'], $pan['date']);
}
```

Three things about it:

- **`success` is the only field that says whether the operation worked.** The surrounding
  `<result code="1301">` means "here is a message", not "your operation succeeded". Reading the
  result code instead is the classic mistake: every poll answer then looks like a success. A missing
  verdict is treated as failure — a missing yes is not a yes.
- **`svTRID` identifies which of your pending operations this is about.** Match it against the one
  you were given with the `1001`. Do not assume it is the most recent: poll is a queue, and notices
  arrive in the order the registry finished the work.
- **`date` is when the action completed**, not when you polled.

### A low-balance notice

```php
if ($notice->balance() !== null) {
    alertBilling($notice->currentBalance());   // an exact decimal string — never cast it to float
}
```

Same element as the [balance query](balance.md), so the same accessors read it. Act on it: once the
balance runs out, chargeable commands are refused with `2104` and a registration you were about to
make fails for want of funds rather than for anything wrong with the request.

### A payload from an extension you did not announce (RFC 9038)

A notice is written into your queue before the registry knows which session will collect it, so it
can hold an element from an extension namespace. If your login listed `<svcExtension>` URIs and that
namespace was not among them, the registry moves the element out of `<resData>` and into an
`<extValue>` inside `<result>` instead of dropping it.

The frame still parses, and **you can still ack it**, so the queue keeps draining. The data is still
there:

```php
foreach ($notice->extValues() as $ext) {
    $ext['element'];     // e.g. 'infData' — which element the registry relocated
    $ext['namespace'];   // the namespace you would announce to receive it as resData
    $ext['values'];      // its children by local name
    $ext['reason'];      // the registry's explanation
}
```

Announce the extensions you parse if you want the typed form; a login that sends no `<svcExtension>`
at all is read as "no restriction" and receives every payload as `<resData>`. See
[Session](session.md) for how the login services are chosen.

---

## A complete poller

```php
use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\EppException;
use EppTools\Response;

$client = new Client(Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => 'your-secret',
    'caFile'   => '/path/to/registry-ca.pem',
]));

try {
    $client->connect();
    $client->login();

    $count = $client->poll()->drain(function (Response $notice) use ($client): void {
        $id = (string) $notice->messageId();

        // Idempotent by messageId: a redelivery after a dropped ack must not double-handle.
        if (alreadySeen($id)) {
            return;
        }

        $transfer = $notice->transfer();
        $pending  = $notice->pendingActionData();

        if ($transfer !== null && $transfer['status'] === 'pending') {
            // Answer it before actBy, or the registry decides.
            $client->domain()->transfer(
                customerAuthorisedTheMove($notice->objectName()) ? 'approve' : 'reject',
                (string) $notice->objectName(),
            );
        } elseif ($pending !== null) {
            recordOutcome($pending['svTRID'], $pending['success'], $pending['object']);
        } elseif ($notice->balance() !== null) {
            alertBilling($notice->currentBalance());
        } else {
            store($id, $notice->queueMessage(), $notice->queueDate(), $notice->raw());
        }

        markSeen($id);
        // Returning normally is the ack. Throwing keeps the notice queued.
    }, 200);

    echo "drained {$count}\n";
    $client->logout();
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
} finally {
    $client->disconnect();
}
```

Storing `raw()` alongside the parsed fields is cheap and worth it: a notice is the only copy of an
event, and once acked the registry has none.

---

## Result codes on this page

| Code | Meaning | Exception |
|---|---|---|
| `1301` | a message is waiting (`req`), or messages remain (`ack`) | — |
| `1300` | the queue is empty | — |
| `2303` | `ack` for a message id that does not exist, or was already acknowledged | `ObjectDoesNotExistException` |
| `2400` | the registry could not complete it; may be transient | `CommandException` (`isRetryable()`) |
| `2500`–`2502` | the session ended; reconnect and log in again | `SessionException` |

`1300` and `1301` are both success codes, so `isSuccess()` is true for either — which is why
"empty" is decided by the code `1300` and never by the absence of a payload. `ResultCode` names them
`SUCCESS_NO_MESSAGES` and `SUCCESS_ACK_TO_DEQUEUE`; the full taxonomy is in [Errors](errors.md).

---

See also: [Domains](domains.md) · [Contacts](contacts.md) · [Balance & prices](balance.md) ·
[Responses](responses.md) · [Errors](errors.md)

[← Manual index](README.md)
