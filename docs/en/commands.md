# Commands

Everything after the login is a command and its response. This page is the command surface as a
whole: how you reach a command, what one gives back, how transactions are labelled, how to turn
exceptions off, and how to send a frame the library does not model.

The per-object detail lives on its own page: [Domains](domains.md), [Contacts](contacts.md),
[Hosts](hosts.md), [Poll](poll.md), [Balance](balance.md).

## Reaching a command

Four handlers hang off the client, plus the balance query, which is a single method:

```php
$client->domain();    // EppTools\Command\Domain   — RFC 5731
$client->contact();   // EppTools\Command\Contact  — RFC 5733
$client->host();      // EppTools\Command\Host     — RFC 5732
$client->poll();      // EppTools\Command\Poll     — RFC 5730 §2.9.2.3
$client->balance();   // the registry's balance extension — a Response, not a handler
```

Each handler is created once per client and returned again on every call, so
`$client->domain()->check(…)` costs nothing extra inside a loop.

## The whole surface

| Method | Sends | Documented in |
|---|---|---|
| `domain()->check(array $names, array $fee = [], ?string $currency = null): Response` | `domain:check`, optionally with `fee:check` | [Domains](domains.md), [Balance](balance.md) |
| `domain()->info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response` | `domain:info` | [Domains](domains.md) |
| `domain()->create(string $name, array $options = []): Response` | `domain:create` | [Domains](domains.md) |
| `domain()->update(string $name, array $options = []): Response` | `domain:update` | [Domains](domains.md) |
| `domain()->renew(string $name, string $curExpDate, int $years = 1, string\|array\|null $fee = null): Response` | `domain:renew` | [Domains](domains.md) |
| `domain()->restore(string $name, string\|array\|null $fee = null): Response` | `domain:update` with `rgp:restore` (RFC 3915) | [Domains](domains.md) |
| `domain()->delete(string $name): Response` | `domain:delete` | [Domains](domains.md) |
| `domain()->transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null, string\|array\|null $fee = null): Response` | `domain:transfer` | [Domains](domains.md) |
| `domain()->createBuilder(string $name): DomainCreateBuilder` | nothing until `send()` | [Builders](builders.md) |
| `domain()->updateBuilder(string $name): DomainUpdateBuilder` | nothing until `send()` | [Builders](builders.md) |
| `contact()->check(array $ids): Response` | `contact:check` | [Contacts](contacts.md) |
| `contact()->info(string $id, ?string $authInfo = null): Response` | `contact:info` | [Contacts](contacts.md) |
| `contact()->create(string $id, array $options = []): Response` | `contact:create` | [Contacts](contacts.md) |
| `contact()->createAuto(array $options = []): Response` | `contact:create` with the reserved id `Contact::AUTO_ID` | [Contacts](contacts.md) |
| `contact()->update(string $id, array $options = []): Response` | `contact:update` | [Contacts](contacts.md) |
| `contact()->delete(string $id): Response` | `contact:delete` | [Contacts](contacts.md) |
| `contact()->transfer(string $op, string $id, ?string $authInfo = null): Response` | `contact:transfer` | [Contacts](contacts.md) |
| `contact()->createBuilder(string $id, string $email): ContactCreateBuilder` | nothing until `send()` | [Builders](builders.md) |
| `contact()->updateBuilder(string $id): ContactUpdateBuilder` | nothing until `send()` | [Builders](builders.md) |
| `host()->check(array $names): Response` | `host:check` | [Hosts](hosts.md) |
| `host()->info(string $name): Response` | `host:info` | [Hosts](hosts.md) |
| `host()->create(string $name, array $addresses = []): Response` | `host:create` | [Hosts](hosts.md) |
| `host()->update(string $name, array $options = []): Response` | `host:update` | [Hosts](hosts.md) |
| `host()->delete(string $name, bool $force = false): Response` | `host:delete`, optionally with the registry's forced-delete extension | [Hosts](hosts.md) |
| `host()->updateBuilder(string $name): HostUpdateBuilder` | nothing until `send()` | [Builders](builders.md) |
| `poll()->request(): Response` | `<poll op="req">` | [Poll](poll.md) |
| `poll()->ack(string $messageId): Response` | `<poll op="ack">` | [Poll](poll.md) |
| `poll()->drain(callable $handler, int $limit = 0): int` | request/ack in a loop | [Poll](poll.md) |
| `balance(): Response` | `balance:info` | [Balance](balance.md) |
| `request(string\|Frame $frame): Response` | whatever you built | [below](#custom-frames) |
| `frame(): Frame` | nothing; returns a stamped frame | [below](#custom-frames) |

The session methods — `connect()`, `hello()`, `login()`, `logout()`, `disconnect()` — are in
[Session](session.md).

## What a command returns

**Every command returns a [`Response`](responses.md).** Not `null`, not an array, not a bool.
The object wraps the parsed reply and every accessor reads out of it.

```php
$response = $client->domain()->check(['example.com.ua']);

$response->code();        // int:  1000
$response->isSuccess();   // bool: true for any 1xxx
$response->message();     // string: the server's <msg>, in your session language
$response->svTRID();      // string: the registry's transaction id — store it
```

The three outcomes to write code for:

| Code | Meaning | What to do |
|---|---|---|
| `1000` | done | continue |
| `1001` | accepted, completing offline | **do not resend.** The object carries a `pending*` status and the outcome arrives later as a poll notice |
| `2xxx` | refused; nothing was changed | read the code — by default it has already been raised as an exception |

`1001` is the one that catches people out. It is a success code, so `isSuccess()` is `true` and
nothing is raised. Test it explicitly:

```php
$response = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);

if ($response->isPending()) {
    // Store svTRID() against the order. The verdict arrives in the poll queue as
    // pendingActionData(), and its paTRID svTRID is what matches it back to this command.
    $orders->markPending($response->svTRID());
}
```

See [Poll](poll.md) for the other half of that exchange.

Two other codes are success and must not be read as failure: **1300** (poll: the queue is empty)
and **1500** (the answer to `logout`).

## Client transaction ids

Every command carries a `clTRID` that you choose and every response carries a `svTRID` that the
registry assigns.

| Identifier | Who sets it | What it is for |
|---|---|---|
| `clTRID` | this client | matching a reply to the request that caused it |
| `svTRID` | the registry | the registry's own record of that operation |

The library stamps a unique `clTRID` on every frame it builds. The shape is

```
PHP-SDK-20260816103000-24191-0007
   │            │          │    └── a counter, monotonic within this client instance
   │            │          └──────── the OS process id, so two workers never collide
   │            └─────────────────── a UTC timestamp, YYYYMMDDHHMMSS: when
   └──────────────────────────────── Config::$clTRIDPrefix
```

Where the process id is not available to PHP, the middle segment is a short generated token instead,
which keeps two workers apart just the same.

Set `clTRIDPrefix` to something that identifies your system. It is a human-correlatable label, not
a secret, and it is what the registry's support desk will read back to you.

**Store the `svTRID` against the object the command was about.** It is the one value support can
look an operation up by; a `clTRID` means nothing to anyone but you. Log both, on every command,
including the ones that succeeded — those are what you compare against when a later one does not.

### The echo check

The server echoes your `clTRID` back (RFC 5730 §2.5). Because this client generates a unique one per
command, comparing them turns any desynchronisation of the stream from a silent mis-attribution
into a loud failure: without it, a reply belonging to the previous command is indistinguishable
from this one's, and for a renew or a create that means booking the wrong domain as done.

A mismatch raises `ConnectionException` **and closes the connection** — once the offsets disagree,
every later frame on that stream is suspect too. The comparison allows for a server that normalises
the value, since the schema's transaction-id type is 3–64 characters: a legitimately truncated or
padded echo is accepted, a wrong one is not.

## The `throwOnFailure` switch

By default any result code of 2000 or more is raised as a `CommandException` (or the subclass that
fits the code). That is what makes a straight-line integration correct by default: you cannot
forget to check a code you never see.

```php
$client->throwOnFailure(false);   // and (true) to turn it back on
```

With it off, a refusal comes back as an ordinary `Response` and you branch on `code()` yourself:

```php
use EppTools\ResultCode;

$client->throwOnFailure(false);

$response = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);
if ($response->code() === ResultCode::OBJECT_EXISTS) {
    $taken[] = 'example.com.ua';
} elseif (!$response->isSuccess()) {
    throw new RuntimeException($response->message() ?? 'create failed');
}
```

The switch returns the client, so it chains, and it applies to every later command on that client
until you change it back.

What it does **not** turn off:

- `ConnectionException` — the server never answered, so there is no code to read.
- `ValidationException` and `ConfigException` — nothing was sent at all.
- `AuthenticationException` from `login()`. A login that failed is not a session you can carry on
  in, so it is raised whatever the switch says.
- The refusal `poll()->drain()` raises when a poll reply is neither a notice nor an empty queue.
  With throwing off, that reply reaches the loop instead of being raised, and the loop raises it
  explicitly rather than reading a refusal as a drained queue.

`EppTools\ResultCode` has a named constant for every code — see the table in
[Errors](errors.md#result-codes).

## Option keys are checked before the frame is built

The commands that take an options array refuse a key they do not understand, naming the closest one
they do:

```php
$client->domain()->create('example.com.ua', ['years' => 1, 'secdns' => [...]]);
// ValidationException: domain:create does not accept 'secdns' (did you mean 'secDNS'?).
// Accepted: authInfo, contacts, fee, license, nameServers, nameservers, registrant, secDNS, years.
```

This is a deliberate trade. An options array otherwise accepts anything: a key that is misspelled,
in the wrong case, or left over from an older integration is simply never read. The command still
goes out, the registry still answers 1000, and the part you asked for is missing — `'secdns'` for
`'secDNS'` registers the domain **unsigned**, a misspelled `'nameservers'` registers it with **no
delegation**. Nothing in the response says so, because as far as the registry is concerned you
never asked.

Where two spellings are both defensible, both are accepted: `nameservers` and `nameServers` on a
domain create are the same option. Nested blocks are checked too — the `add`, `rem`, `chg` and
`secDNS` blocks of an update each have their own list.

The [builders](builders.md) remove the class of mistake entirely: a misspelled step is a method that
does not exist, and your editor says so as you type it.

## Custom frames

Anything the high-level API does not cover can be assembled with `EppTools\Frame` and sent through
`Client::request()`.

```php
use EppTools\Namespaces;

$frame = $client->frame();                       // a <command> with a clTRID already stamped
$check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
$frame->ns($check, Namespaces::DOMAIN, 'domain:name', 'example.com.ua');

$response = $client->request($frame);
$response->availability();
```

`Client::frame()` is the entry point you want: it returns a `Frame` with a generated `clTRID`, so
the [echo check](#the-echo-check) still protects the exchange. `Frame::command($clTRID)` builds one
with an id of your own, and then the uniqueness is your responsibility.

### The `Frame` API

| Method | What it does |
|---|---|
| `Frame::command(string $clTRID): self` | Start a `<command>` frame with this transaction id. |
| `verb(string $name): \DOMElement` | Append the command verb — `check`, `info`, `create`, `update`, `renew`, `transfer`, `delete`, `poll`, `login`, `logout` — and return it to hang content on. |
| `extension(): \DOMElement` | The `<extension>` element, created on first call and returned again afterwards, so several extensions share one block. |
| `epp(\DOMElement $parent, string $name, ?string $text = null, array $attrs = []): \DOMElement` | Append an element in the base `epp-1.0` namespace, with no prefix. |
| `ns(\DOMElement $parent, string $ns, string $qname, ?string $text = null, array $attrs = []): \DOMElement` | Append a namespaced element carrying its prefix, e.g. `domain:name`. |
| `document(): \DOMDocument` | The underlying document, for anything the two append helpers cannot express. |
| `toXml(): string` | Serialise. The `clTRID` is written as the final child of `<command>`, which is the order RFC 5730 fixes. |

Text passed to `epp()` and `ns()` is added as a text node, never string-concatenated, so `&` and
`<` in a value are escaped for you. `toXml()` is safe to call more than once — log the frame, then
send it — and the result carries exactly one `clTRID`, because a `<command>` holding two draws a
bare 2001.

### `request(string|Frame $frame): Response`

Sends a frame and returns the parsed response. It accepts a `Frame` or raw XML:

```php
$response = $client->request($frame);
$response = $client->request('<?xml version="1.0" encoding="UTF-8"?><epp …>…</epp>');
```

Everything an ordinary command gets, a custom frame gets: the length framing, the logging with
secrets masked, the `clTRID` echo check, and the `throwOnFailure` behaviour.

### An extension the library does not model

The pattern for riding an extension along with a standard command — here a made-up namespace on a
`domain:info`:

```php
use EppTools\Namespaces;

$frame = $client->frame();
$info  = $frame->ns($frame->verb('info'), Namespaces::DOMAIN, 'domain:info');
$frame->ns($info, Namespaces::DOMAIN, 'domain:name', 'example.com.ua', ['hosts' => 'all']);

$ext = $frame->extension();
$block = $frame->ns($ext, 'urn:example:params:xml:ns:thing-1.0', 'thing:info');
$frame->ns($block, 'urn:example:params:xml:ns:thing-1.0', 'thing:detail', 'full');

$response = $client->request($frame);
```

Two things to get right. Announce the extension's URI at login — through `Config::$extUris`, or by
letting the greeting supply it — or the server has no reason to return that extension's data. And
read the answer through `xpath()` or `values()`, since the named accessors only know the extensions
the library models; see [Responses](responses.md#raw-access).

### Namespace constants

`EppTools\Namespaces` holds the exact strings that go on the wire:

| Constant | URI | Defined by |
|---|---|---|
| `EPP` | `urn:ietf:params:xml:ns:epp-1.0` | RFC 5730 |
| `DOMAIN` | `urn:ietf:params:xml:ns:domain-1.0` | RFC 5731 |
| `HOST` | `urn:ietf:params:xml:ns:host-1.0` | RFC 5732 |
| `CONTACT` | `urn:ietf:params:xml:ns:contact-1.0` | RFC 5733 |
| `SECDNS` | `urn:ietf:params:xml:ns:secDNS-1.1` | RFC 5910 (DNSSEC) |
| `RGP` | `urn:ietf:params:xml:ns:rgp-1.0` | RFC 3915 (restore) |
| `FEE` | `urn:ietf:params:xml:ns:epp:fee-1.0` | RFC 8748 (prices) |
| `LOGINSEC` | `urn:ietf:params:xml:ns:epp:loginSec-1.0` | RFC 8807 (login security) |
| `XSI` | `http://www.w3.org/2001/XMLSchema-instance` | XML Schema |

Two more are values rather than namespaces: `LOGINSEC_SENTINEL` is the reserved `[LOGIN-SECURITY]`
string that RFC 8807 puts in `<pw>` when the real password travels in the extension, and
`DEFAULT_OBJ_URIS` / `DEFAULT_EXT_URIS` are the service lists used only if a greeting arrived with
no service menu at all.

### Your registry's own extensions

Every URI above is defined by an RFC and is the same string at every registry on earth. A registry's
OWN extensions — a trademark licence, a price, an account balance — are not, and there is no constant
for them here, because there is no value that would be right for more than one registry.

They are **discovered from the `<greeting>`**. Every server lists what it supports before you send
anything, so after `connect()` the client already knows:

```php
$client->connect();

$client->registryExtUri();      // e.g. 'http://registry.example/epp/registry-1.0', or null
$client->registryBalanceUri();  // e.g. 'http://registry.example/epp/balance-1.0', or null
```

`null` means that server advertises no such extension — a fact about the server, not an error. The
commands that need one say so instead of guessing: `domain:create` with a `license`, `host:delete`
with `force` and `balance()` all throw `ConfigException` naming what was wanted and listing what the
server did offer. That refusal is the point. An extension sent under a namespace a server does not
recognise is **ignored, not rejected**, so a guess would come back `1000 OK` with the licence
silently unset.

Discovery matches the last segment of an advertised URI — `.../registry-1.0`, `urn:…:balance` —
which is the convention registries follow, not a rule anyone enforces. For a registry that names its
extensions something else, set them yourself and the greeting is not consulted:

```php
$config = Config::fromArray([
    'host' => 'epp.registry.example', 'clid' => 'EXAMPLE', 'password' => '...',
    'registryExtUri'     => 'urn:example:params:xml:ns:myreg-1.0',
    'registryBalanceUri' => 'urn:example:params:xml:ns:myreg-balance-1.0',
]);
```

## One command at a time

Send a command, read its reply, then send the next. Do not pipeline commands on one connection
expecting the replies to line up. If you need throughput, open more sessions rather than
overlapping commands inside one — and mind the concurrent-session limit, which arrives as a 2502.

---

[← Manual index](README.md)
