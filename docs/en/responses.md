# Responses

Every command returns an `EppTools\Response`. It wraps the parsed reply and answers questions about
it through named accessors, so you never index into an array by a string you had to guess.

This page lists **every** accessor, grouped by what it answers, with its signature and what it gives
back when the reply carries nothing.

Two rules that hold across the whole class:

- **Dates come back as the registry's own string** — `2027-04-01T09:15:00Z`, or with an offset —
  never a `DateTime`. The registry decides which calendar day a renewal lands on, and reformatting
  through a local timezone is how a client ends up displaying, and renewing against, the day before.
- **Money comes back as an exact decimal string**, never a float. `0.1 + 0.2` is not `0.3` in
  binary floating point, and a balance summed that way drifts. Use `bcmath` or integer minor units.

An accessor asked about something the reply does not contain answers `null`, `[]`, `false` or `0`
as its signature allows. That is a legitimate answer — "the registry said nothing about this" —
not an error.

## Result and outcome

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `code(): int` | The EPP result code: 1000, 1001, 2303 … | `0` for a greeting or any frame with no `<result>` |
| `isSuccess(): bool` | `true` for any 1xxx code | `false` |
| `isPending(): bool` | `true` only for 1001 — accepted, completing offline | `false` |
| `isGreeting(): bool` | `true` when the frame is a `<greeting>` rather than a `<response>` | `false` |
| `message(): ?string` | The result `<msg>`, in the session language | `null` |
| `messageLang(): ?string` | The language of that message: `en`, `uk`, `ua`, `ru` | `null` |
| `errorReasons(): array` | The `<extValue><reason>` prose from a failed command, as `string[]` | `[]` |
| `extValues(): array` | The `<extValue>` blocks in full — see below | `[]` |
| `clTRID(): ?string` | The client transaction id the server echoed | `null` |
| `svTRID(): ?string` | The registry's transaction id. Store it against the object | `null` |

`isSuccess()` being true is not the same as the work being done: 1001 is a success code and means
the registry accepted the command and will finish it offline. Test `isPending()` before you record
anything as complete.

### `extValues(): array`

Where `errorReasons()` gives the prose, this gives **which element** the server objected to — which
is the part you can act on. A check for five domains that fails on one carries that one name here.

```php
foreach ($response->extValues() as $ext) {
    $ext['element'];    // 'name'  — the local name of the offending node, '' if the server named none
    $ext['namespace'];  // 'urn:ietf:params:xml:ns:domain-1.0'
    $ext['text'];       // 'bad..name' — the node's OWN character data
    $ext['values'];     // children by local name, when the node is a container
    $ext['xml'];        // the node serialised, for a log
    $ext['reason'];     // the server's explanation
    $ext['lang'];       // its language
}
```

`text` is the element's own character data rather than a recursive `textContent`, so a container
never comes back as its children fused into one string that reads like a value and is not.
`CommandException::subject()` reads the first non-empty `text` for you — see [Errors](errors.md).

## Object identity

These answer for a domain, a host or a contact alike.

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `objectName(): ?string` | The domain name, the host name, or the contact **handle** | `null` |
| `roid(): ?string` | The registry's own identifier for the object | `null` |
| `sponsor(): ?string` | `clID` — the registrar the object belongs to now | `null` |
| `registrarOfRecord(): ?string` | The handle the registry's WHOIS and RDAP publish as the registrar | `null` |
| `createdBy(): ?string` | `crID` — the registrar that created it | `null` |
| `createdDate(): ?string` | `crDate`, as the registry wrote it | `null` |
| `updatedBy(): ?string` | `upID` — the registrar that last changed it | `null`, including when it has never been changed |
| `updatedDate(): ?string` | `upDate` | `null` when never changed |
| `authInfo(): ?string` | The `<authInfo><pw>` transfer secret | `null` when the registry withheld it |
| `statuses(): array` | Status values from the `s` attribute: `['ok']`, `['clientHold', …]` | `[]` |

`objectName()` reads the direct child of the object block. That matters on a contact: a
document-wide search for a `<name>` element finds the person's full name inside the postal address
first, and feeding that back as an id draws a 2303.

`registrarOfRecord()` and `sponsor()` are not the same party for a reseller. `sponsor()` names the
account the object belongs to in your own hierarchy; `registrarOfRecord()` names who the registry
publishes.

`updatedBy()` is sent only to the sponsoring registrar. Pair it with `updatedDate()` when
reconciling: a change you did not make came from the registry side or from a support action, not
from your system.

`authInfo()` is a live credential — the secret that lets any registrar take the domain away from
you. Never log it, never put it in a support ticket, and roll it after you have passed it to a
customer.

## Domain

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `expiryDate(): ?string` | `exDate`, exactly as the registry wrote it | `null` |
| `registrant(): ?string` | The registrant handle | `null` |
| `contacts(): array` | Role contacts: `['admin' => ['c-1'], 'tech' => ['c-1','c-2']]`. The registrant is **not** included | `[]` |
| `contactsFor(string $role): array` | The handles in one role, matched case-insensitively | `[]` — a legitimate answer, since only the registrant is mandatory everywhere |
| `adminContacts(): array` | `contactsFor('admin')` | `[]` |
| `techContacts(): array` | `contactsFor('tech')` | `[]` |
| `billingContacts(): array` | `contactsFor('billing')` | `[]` |
| `allContacts(): array` | Every handle in any capacity, registrant included, de-duplicated | `[]` |
| `nameservers(): array` | The delegation, lower-cased, in the registry's order | `[]` |
| `nameserverAddresses(): array` | Inline glue keyed by nameserver name | `[]` |
| `subordinateHosts(): array` | Host objects living **under** this domain, lower-cased | `[]` |
| `transfer(): ?array` | A transfer in full — see below | `null` |
| `transferStatus(): ?string` | `trStatus`: `pending`, `serverApproved`, … | `null` |
| `transferDate(): ?string` | `trDate` — when it last changed hands | `null` if it never has |
| `license(): ?string` | A trademark or licence number, from the registry's own extension | `null` |
| `rgpStatus(): array` | RGP status values, e.g. `['redemptionPeriod']` (RFC 3915) | `[]` |
| `dsRecords(): array` | DNSSEC DS records (RFC 5910) | `[]` when unsigned |
| `keyRecords(): array` | DNSSEC public keys | `[]` |
| `isSigned(): bool` | Whether the domain carries any DNSSEC data | `false` |
| `prices(): array` | Price hints from the registry's price extension, keyed by operation | `[]` |
| `priceChannel(): ?string` | The price channel this domain is billed on | `null` |

`contactsFor()` matches the role case-insensitively, because registries are inconsistent about
`tech` versus `Tech` and a case-sensitive lookup silently reports "no technical contact" for a
domain that has one.

`allContacts()` is the one to use when you care **that** a contact is referenced rather than in
which role — before deleting a contact, or when working out which of your contact objects are still
in use.

`nameservers()` covers both EPP delegation models: `<domain:hostObj>`, a reference to a host object,
and `<domain:hostAttr>`, the name inlined with its glue. A client that reads only one of them sees
an empty list against a registry using the other, and concludes the domain has no nameservers at
all.

`nameserverAddresses()` returns the inline glue only:

```php
$info->nameserverAddresses();
// ['ns1.example.com.ua' => [['ip' => '192.0.2.1', 'version' => 'v4']]]
```

It is populated only where the registry answers with `hostAttr`. Against one that answers with
`hostObj` you get `[]` and fetch the addresses with a `host()->info()` per name — so an empty
result here does **not** mean the domain is undelegated. Use `nameservers()` for the list.

`subordinateHosts()` matters before a delete: the registry refuses to delete a domain while
nameserver objects live under it. Check the list, remove or repoint them, then delete.

`prices()` and `priceChannel()` are the registry's own price hints on a `domain:info`, distinct from
the RFC 8748 quotes in [`fees()`](#check-and-money):

```php
$info->prices();
// ['renewal' => ['value' => '180.00', 'currency' => 'UAH'], …]
$info->priceChannel();
// an opaque id matching a row of the registry's published catalogue
```

A domain registered long ago may sit on a different channel from the one a new registration in the
same zone would use, which is why the channel is per domain rather than per zone.

### `transfer(): ?array`

The whole transfer notice, from a transfer response or the `trnData` of a poll message:

```php
$t = $response->transfer();
// [
//   'status'       => 'pending',
//   'requestedBy'  => 'ACME',            // reID
//   'requestedAt'  => '2026-08-14T10:00:00Z',// reDate
//   'actingClient' => 'EXAMPLE',            // acID — who must answer
//   'actBy'        => '2026-08-19T10:00:00Z',// acDate — the deadline
//   'expiryDate'   => '2028-04-01T09:15:00Z',// exDate the transfer would produce
// ]
```

`transferStatus()` alone says a transfer is pending without saying whose or how long you have.
`actBy` is the moment after which the registry decides for you.

### DNSSEC records

```php
$info->dsRecords();
// [['keyTag' => 12345, 'alg' => 8, 'digestType' => 2, 'digest' => 'ABCD…']]

$info->keyRecords();
// [['flags' => 257, 'protocol' => 3, 'alg' => 8, 'pubKey' => 'AwEAAb…']]

$info->isSigned();   // true when either list is non-empty
```

## Host

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `hostAddresses(): array` | The host object's glue: `[['ip' => '192.0.2.1', 'version' => 'v4'], …]` | `[]` |

Only a host **inside** the zone it serves carries glue. For an external nameserver the registry
returns none, and that is normal rather than a missing answer.

The list is scoped to the host object itself, so it never fuses in the per-name glue of a domain's
inline delegation — that is `nameserverAddresses()`. A missing `ip` attribute means `v4`, which is
the host schema's own default, so an IPv4-only registry that omits it is reported correctly rather
than as something else.

## Contact

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `postalInfo(): array` | Addresses keyed by form: `'int'` (ASCII) and `'loc'` (local script) | `[]` |
| `email(): ?string` | The contact's e-mail address | `null` |
| `voice(): ?string` | The voice number, in the EPP `+CC.NNNN` form | `null` |
| `fax(): ?string` | The fax number, same form | `null` |
| `disclose(): ?array` | The disclosure preference | `null` when the contact carries none and registry policy alone applies |

```php
$c = $client->contact()->info('C-0001');

$c->postalInfo();
// ['int' => ['name' => 'ACME LLC', 'org' => 'ACME LLC', 'street' => ['1 Main St'],
//            'city' => 'Kyiv', 'sp' => '', 'pc' => '01001', 'cc' => 'UA'],
//  'loc' => [...]]
```

A contact may carry either form or both; parts it does not carry are `''` rather than absent. Read
`['int']` when you need something you can safely print anywhere, `['loc']` when you want the
address as the registrant actually wrote it.

```php
$c->disclose();
// ['flag' => false, 'elements' => ['email', 'voice']]
```

`flag` true means the listed elements **may** be published; false means they must be withheld. The
elements that are *not* listed take the opposite of the flag, so the list is meaningless without it.
An element that exists once per postal form appears with its type, as `name:int` or `addr:loc`.

## Check and money

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `availability(): array` | The whole `*:check` map: name or id ⇒ `bool` | `[]` |
| `isAvailable(string $name): ?bool` | One name, matched case-insensitively | `null` — "the answer said nothing about it" |
| `unavailableReason(string $name): ?string` | Why a name is not available: `In use`, `Reserved` | `null` when it is available or no reason was given |
| `fees(): array` | Per-name RFC 8748 quotes, plus `'_currency'` | `[]` |
| `feeFor(string $name, string $operation, int $years = 1): ?string` | One quote, as a decimal string | `null` when the answer carried no such quote |
| `feeClass(?string $name = null): ?string` | The registry's fee class: `premium`, `standard` | `null` |
| `isPremium(?string $name = null): bool` | Whether the name is priced outside the standard list | `false` |
| `chargedFee(): ?array` | What a transform actually charged: `['currency' => 'UAH', 'fee' => '100.00']` | `null` |
| `feeAmount(): ?string` | The amount from `chargedFee()` | `null` |
| `feeCurrency(): ?string` | The currency from `chargedFee()` | `null` |
| `balance(): ?array` | The whole balance block | `null` when this is not a balance response |
| `creditLimit(): ?string` | Your credit limit | `null` |
| `currentBalance(): ?string` | Your current balance | `null` |
| `availableCredit(): ?string` | What you can still spend: balance plus any credit limit | `null` |

`isAvailable()` returning `null` is the reason it exists. Indexing `availability()` by hand gives
`null` for both "taken" and "you misspelled the key", and those two answers must not look the same
on the line before a registration.

```php
$check = $client->domain()->check(['example.com.ua', 'taken.com.ua']);

$check->availability();                       // ['example.com.ua' => true, 'taken.com.ua' => false]
$check->isAvailable('EXAMPLE.com.ua');        // true
$check->isAvailable('never-asked.com.ua');    // null
$check->unavailableReason('taken.com.ua');    // 'In use'
```

### `fees()` and `feeFor()`

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => [1, 2, 5]], currency: 'UAH');

$r->fees();
// [
//   '_currency'       => 'UAH',
//   'example.com.ua'  => [
//       'avail'    => true,               // false ⇒ read 'reason' (zone not served, currency…)
//       'reason'   => null,
//       'class'    => 'premium',          // present only when the registry sent a class
//       'commands' => ['create' => ['years' => 1, 'fee' => '100.00']],
//       'periods'  => [['op' => 'create', 'years' => 1, 'fee' => '100.00'],
//                      ['op' => 'create', 'years' => 2, 'fee' => '200.00'],
//                      ['op' => 'create', 'years' => 5, 'fee' => '480.00']],
//   ],
// ]

$r->feeFor('example.com.ua', 'create', 5);    // '480.00'
```

Asking one operation at several periods brings back one quote per period. The `commands` map holds
one entry per operation — the first period you asked for — so **read `feeFor()` or `periods`
whenever you asked for more than one**. `transfer` and `restore` are one-year operations however
many years you ask for, so read those back at one year.

Amounts here are illustrative, not the registry's tariff. Everything about asking and capping
prices is on [Balance](balance.md).

### `isPremium()` and `feeClass()`

```php
$r->feeClass('example.com.ua');   // 'premium' | 'standard' | null
$r->isPremium('example.com.ua');  // true when the class is present and is not 'standard'
```

Both take an optional name; with none, they answer for the first name in the reply that carries a
class. A `false` from `isPremium()` is not a promise of the standard price — it means the answer
declared no special class. Charge from `fees()`, and cap the fee on the transform itself.

### What was actually charged

```php
$create = $client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => 'C-0001']);

$create->chargedFee();    // ['currency' => 'UAH', 'fee' => '100.00'] or null
$create->feeAmount();     // '100.00'
$create->feeCurrency();   // 'UAH'
```

The registry echoes this on a successful transform that carried a fee agreement. Record
`feeAmount()` against the order rather than the price you quoted from a `check`: between the two,
the tariff can have moved.

### The balance block

```php
$b = $client->balance();

$b->balance();           // ['creditLimit' => '…', 'balance' => '…', 'availableCredit' => '…']
$b->creditLimit();
$b->currentBalance();    // named for what it is, because balance() returns the whole block
$b->availableCredit();
```

All three are decimal strings in your account currency. See [Balance](balance.md).

## Poll

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `messageId(): ?string` | The queued message id, to pass to `poll()->ack()` | `null` — the queue is empty |
| `messageCount(): int` | How many messages remain in the queue | `0` |
| `queueMessage(): ?string` | The **notice** text, from `<msgQ><msg>` | `null` |
| `queueMessageLang(): ?string` | The notice's language: `uk`, `ru`, `en` | `null` |
| `queueDate(): ?string` | When the notice was queued | `null` |
| `pendingActionData(): ?array` | The outcome of an operation the registry processed offline | `null` for an ordinary notice or an empty queue |

**`queueMessage()`, not `message()`.** `message()` returns the command-result banner — "Command
completed successfully; ack to dequeue" — which is a constant string. The notice's real content is
in `queueMessage()`, and reading the wrong one hands you the banner while the content is discarded
and an ack destroys it at the registry irreversibly.

The notice carries its own language, set per registrar, independent of the session language that
`messageLang()` reports.

### `pendingActionData(): ?array`

This is how a deferred command finally reports back (RFC 5731 §3.3, RFC 5733 §3.3). You send a
create, get **1001** and a `svTRID`; later a poll message carries the verdict.

```php
$pan = $notice->pendingActionData();
// [
//   'object'  => 'example.com.ua',
//   'success' => true,                        // the paResult attribute
//   'clTRID'  => 'PHP-SDK-…-0007',            // from paTRID: the ORIGINAL command
//   'svTRID'  => 'SRV-…-00042',
//   'date'    => '2026-08-14T10:05:00Z',      // paDate: when the action completed
// ]
```

- **`success` is the only thing that says whether it worked.** The surrounding `<result
  code="1301">` means "here is a message", not "your operation succeeded". Reading that instead is
  the classic mistake: every poll answer then looks like a success. A missing verdict is treated as
  failure, because a missing verdict is not a yes.
- **`svTRID` matches it back** to the command you were given the 1001 for. Do not assume it is the
  most recent one; poll is a queue.
- **`date`** is when the action completed, not when you polled.

The block is matched by local name across every object namespace, so a `domain:panData` and a
`contact:panData` both come back.

## Session and security

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `securityEvents(): array` | RFC 8807 login security events about this session | `[]` — a healthy session |
| `serviceObjUris(): array` | Greeting: the object services the server advertises | `[]` |
| `serviceExtUris(): array` | Greeting: the extension services it advertises | `[]` |

```php
foreach ($client->login()->securityEvents() as $event) {
    $event['text'];              // always present
    $event['type']   ?? null;    // certificate | cipher | tlsProtocol | password | newPW | stat | custom
    $event['level']  ?? null;    // 'warning' | 'error'
    $event['exDate'] ?? null;    // a certificate's expiry, for type=certificate
}
```

The server returns these only to a client that took part in the extension. Full treatment in
[Session](session.md#login-security-rfc-8807).

## Raw access

For anything the named accessors do not model — an extension of your own, a field a future server
adds.

| Accessor | Returns | When the answer carries nothing |
|---|---|---|
| `value(string $localName): ?string` | The first element anywhere with that local name, trimmed, in any namespace | `null` |
| `values(string $localName): array` | Every element with that local name, trimmed | `[]` |
| `resData(): ?\DOMElement` | The `<resData>` element, for custom parsing | `null` |
| `raw(): string` | The response XML exactly as it arrived | the empty document you were handed |
| `dom(): \DOMDocument` | The parsed document | — |
| `xpath(): \DOMXPath` | An XPath object with the prefixes already registered | — |
| `Response::fromXml(string $xml): self` | Parses a frame into a `Response`. Raises `ConnectionException` on malformed XML | — |

`value()` and `values()` match on the **local** name, ignoring the namespace, which is what makes
them useful against an extension you have not modelled. Two traps worth naming:

- A status lives in the `s` **attribute**, so `values('status')` returns a row of blanks. Use
  `statuses()`.
- `ns` is only the wrapper around a delegation, so `values('ns')` returns one fused string. Use
  `nameservers()`, or `values('hostObj')`.

`xpath()` comes with these prefixes bound: `e` (base EPP), `domain`, `contact`, `host`, `secDNS`,
`rgp` and `fee` — the RFC namespaces, which are the same at every registry.

Your registry's own extension has no prefix bound, because its URI is not known until the greeting
is read. You do not need one: every accessor on this page finds extension data by LOCAL NAME, so a
licence or a price is read the same way whatever namespace it arrived under. If you do want to query
one by hand, bind it yourself from what the client discovered:

```php
$xpath = $response->xpath();
$xpath->registerNamespace('reg', $client->registryExtUri());
$xpath->query('//reg:license');
```

```php
$nodes = $response->xpath()->query('//domain:hostAttr/domain:hostName');
foreach ($nodes as $node) {
    echo $node->textContent, "\n";
}
```

`raw()` is the unmasked frame. If you store it, mask `<pw>`, `<newPW>` and `<authInfo>` yourself —
the library masks them in its own logs for the same reason.

`Response::fromXml()` is how the library builds every response, and it is public so you can parse a
frame you captured. Malformed XML raises `ConnectionException` rather than returning a half-built
object.

---

[← Manual index](README.md)
