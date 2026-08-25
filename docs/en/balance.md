# Balance and prices

Two mechanisms live on this page. They are independent of each other and they have one thing in
common: both deal in money, and money in this library is always an exact decimal **string**.

- **The balance query** — what your account holds and what it can still spend. It uses the registry's
  own balance extension, whose namespace the client reads from the `<greeting>`. No RFC defines this
  command, so a registry may not offer one at all: where it does not, `balance()` throws
  `ConfigException` listing what the server did advertise, rather than sending a frame the server
  would ignore. See [Commands](commands.md#your-registrys-own-extensions).
- **The fee extension (RFC 8748)** — standard EPP. Ask what an operation would cost, and cap what a
  transform is allowed to charge you.

Everything here assumes a connected, logged-in client — see [Session](session.md).

**Money comes back as an exact decimal string, never a float.** `0.1 + 0.2` is not `0.3` in binary
floating point, and a balance summed that way drifts by a hundredth here and a hundredth there until
your ledger and the registry's disagree. Use `bcmath`, or integer minor units. The same rule holds
for every amount on this page: a quoted price, a cap, a charged fee, a credit limit.

Amounts in the examples are illustrative. They are not the registry's tariff.

## The surface

| Call | Sends | Answers |
|---|---|---|
| `$client->balance(): Response` | `<info><balance:info>` | credit limit, balance, available credit |
| `domain()->check(array $names, array $fee = [], ?string $currency = null): Response` | `domain:check` + `<fee:check>` | availability **and** prices |
| `domain()->create($name, ['fee' => …])` | `domain:create` + `<fee:create>` | the create, capped |
| `domain()->renew($name, $curExpDate, $years, $fee)` | `domain:renew` + `<fee:renew>` | the renewal, capped |
| `domain()->transfer('request', $name, $authInfo, $years, $fee)` | `domain:transfer` + `<fee:transfer>` | the transfer, capped |
| `domain()->restore($name, $fee)` | `domain:update` + `<fee:update>` | the restore, capped |

| Accessor | Reads |
|---|---|
| `balance(): ?array` | the whole balance block, or `null` |
| `creditLimit(): ?string` · `currentBalance(): ?string` · `availableCredit(): ?string` | one figure each |
| `fees(): array` | every quote in a check reply, keyed by name |
| `feeFor(string $name, string $operation, int $years = 1): ?string` | one quote |
| `feeClass(?string $name = null): ?string` · `isPremium(?string $name = null): bool` | which price list a name sits on |
| `chargedFee(): ?array` · `feeAmount(): ?string` · `feeCurrency(): ?string` | what a transform actually charged |

---

## balance

```php
public function balance(): Response
```

**On the wire:** `<command><info><balance:info/></info>` in the registry's balance namespace
(`http://registry.example/epp/balance-1.0`). It is a read: nothing is charged and nothing changes.

It hangs off the client itself rather than off a command handler, because it is about the account
rather than about an object.

```php
$b = $client->balance();

$b->creditLimit();       // '5000.00'  — how far below zero the account may go
$b->currentBalance();    // '1240.50'  — what is in it now
$b->availableCredit();   // '6240.50'  — what you can still spend: balance plus the limit
```

All three are decimal strings in your account currency. The whole block in one call:

```php
$b->balance();
// ['creditLimit' => '5000.00', 'balance' => '1240.50', 'availableCredit' => '6240.50']
```

`currentBalance()` exists because `balance()` is the block and `balance` is one figure inside it.
Reading `$b->balance()['balance']` is the same value; the named accessor is there so a line about
money does not read like a typo.

**`balance()` returns `null` when the reply carries no balance block.** That is what to test, rather
than assuming the figures are there:

```php
$b = $client->balance();

if ($b->balance() === null) {
    // Not a balance answer. With throwOnFailure(false) a refusal arrives here as an ordinary
    // Response instead of an exception, and it carries no figures.
    throw new RuntimeException($b->message() ?? 'no balance in the reply');
}
```

### Checking before a batch

The reason to call it is to decide something before spending. Compare with `bcmath`, never with
`<` on floats:

```php
$available = (string) $client->balance()->availableCredit();
$needed    = '2400.00';                     // 24 registrations at an illustrative 100.00

if (bccomp($available, $needed, 2) < 0) {
    // Stop here rather than at name 13, half-way through a batch.
    alertBilling("available {$available}, need {$needed}");
    return;
}
```

A batch that runs out mid-way is not a disaster — the registry refuses each remaining billable
command with `2104` and charges nothing — but it leaves you reconciling a half-finished order. See
[Errors](errors.md#insufficientfundsexception-2104) for why `2104` means stop the batch rather than
skip the name.

### The low-balance notice

The registry can also push the figures at you. A low-balance [poll notice](poll.md#a-low-balance-notice)
carries the same block, so the same accessors read it:

```php
$client->poll()->drain(function (EppTools\Response $notice): void {
    if ($notice->balance() !== null) {
        alertBilling((string) $notice->currentBalance());   // a decimal string — never cast it
    }
});
```

**Result codes:** `1000`, with the figures in the frame. A refusal — the service not offered to this
session, an account that may not read it — arrives as a `CommandException` like any other; if you
see one, check that the balance URI was announced at login. By default the login advertises exactly
the services the greeting offered, so this is usually a matter of `Config::$extUris` naming a list of
its own — see [Session](session.md).

---

## Prices: the fee extension (RFC 8748)

One extension, two entirely separate uses. Keep them apart in your head and the rest follows:

| Use | Where | What it does |
|---|---|---|
| **Ask** | `domain()->check()` | quotes a price. Changes nothing, costs nothing |
| **Cap** | `create`, `renew`, `transfer`, `restore` | states the most you consent to pay. A higher real price refuses the command |

The cap is **not** a price you set. The registry charges its own tariff. What the cap buys you is
that the tariff cannot exceed what you agreed to without the command failing instead of billing you.

Both are optional. A command with no fee block proceeds normally and the registry's own price is
charged.

---

### Asking a price on check

```php
public function check(array $names, array $fee = [], ?string $currency = null): Response
```

`$fee` is `operation => years`. The operations are `create`, `renew`, `transfer`, `restore`,
`update` and `delete`.

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1, 'renew' => 1]);

$r->isAvailable('example.com.ua');                  // true — the name is free
$r->feeFor('example.com.ua', 'create', 1);          // '100.00'
$r->feeFor('example.com.ua', 'renew', 1);           // '90.00'
$r->fees()['_currency'];                            // 'UAH'
```

One round trip answers both questions — is it free, and what would it cost. The fee block applies to
every name in the command, and the reply carries one entry per name:

```php
$r = $client->domain()->check(
    ['one.com.ua', 'two.com.ua', 'three.com.ua'],
    fee: ['create' => 1],
);

foreach ($r->availability() as $name => $free) {
    if ($free) {
        printf("%-16s %s %s\n", $name, $r->feeFor($name, 'create', 1) ?? '-', $r->fees()['_currency']);
    }
}
```

An availability answer is a snapshot and so is a price. Between the check and the create the name can
be taken and the tariff can move — which is exactly what the [cap](#capping-what-you-agree-to-pay) is
for.

---

### Several periods in one command

A **list** of years asks the same operation at each period, so a whole price table costs one round
trip instead of five:

```php
$table = $client->domain()->check(['example.com.ua'], fee: ['create' => [1, 2, 3, 5, 10]], currency: 'UAH');

$table->feeFor('example.com.ua', 'create', 1);    // '100.00'
$table->feeFor('example.com.ua', 'create', 5);    // '480.00'
$table->feeFor('example.com.ua', 'create', 10);   // '950.00'
```

Scalars and lists mix freely, and several operations can travel together:

```php
$client->domain()->check(['example.com.ua'], fee: [
    'create'  => [1, 2, 5],
    'renew'   => [1, 2],
    'restore' => 1,
]);
```

**A frame carries at most 20 fee entries.** An entry is one *(operation, period)* pair, so the
example above is six: three creates, two renews, one restore. The number of names does not enter into
it — twenty entries is twenty entries whether you ask about one name or fifty.

```php
$client->domain()->check(['example.com.ua'], fee: ['create' => range(1, 30)]);
// ValidationException: a fee query carries at most 20 entries; this one has 30
```

That is refused here, before a frame is built, rather than at the registry — where an over-long query
comes back as a `2306` naming nothing in particular. Split it across two calls.

A period below one is sent as one, so `0` asks for a one-year price rather than for nothing.

---

### Naming a currency

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1], currency: 'UAH');
```

The currency travels as `<fee:currency>` and is upper-cased for you. Omit it and the registry quotes
in its own.

**A currency the registry does not price in comes back as unavailable with a reason, not as a
converted guess.** That distinction is the point: a converted figure would look like a quote you could
cap against, and it would be wrong.

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1], currency: 'JPY');

$entry = $r->fees()['example.com.ua'] ?? null;
if ($entry !== null && $entry['avail'] === false) {
    echo $entry['reason'], "\n";     // e.g. 'Currency not supported'
}
```

Passing a currency with no operations at all sends a `<fee:check>` carrying only the currency. Name
the operations you actually want quoted instead — the answer to a query that asks for nothing is
registry policy, not something you can rely on.

---

### transfer and restore are one-year operations

**However many years you ask for, `transfer` and `restore` are priced as a single year**, and the
reply echoes the period that would actually be charged. So quote them at one year and read them back
at one year:

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['transfer' => 1, 'restore' => 1]);

$r->feeFor('example.com.ua', 'transfer', 1);   // '120.00'
$r->feeFor('example.com.ua', 'restore', 1);    // '1000.00'
$r->feeFor('example.com.ua', 'restore', 3);    // null — nothing was quoted at three years
```

Asking `['restore' => 3]` is not an error; the answer comes back describing one year, because
that is what the operation is. Reading it back at three gives `null`, and a `null` treated as "free"
is how a restore gets booked at nothing. Read the `periods` list if you want to see the period the
registry actually priced:

```php
foreach ($r->fees()['example.com.ua']['periods'] as $quote) {
    // ['op' => 'restore', 'years' => 1, 'fee' => '1000.00'] — years is what was PRICED
}
```

The same holds for a transfer that carries a mandatory renewal: the renewal is a separate line in the
catalogue, so quote `transfer` and `renew` together if you want the total.

---

### Reading the answer

```php
public function fees(): array
public function feeFor(string $name, string $operation, int $years = 1): ?string
public function feeClass(?string $name = null): ?string
public function isPremium(?string $name = null): bool
```

`fees()` is the whole reply, keyed by name, with the currency alongside:

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => [1, 2, 5]], currency: 'UAH');

$r->fees();
// [
//   '_currency'      => 'UAH',
//   'example.com.ua' => [
//       'avail'    => true,              // could the registry PRICE it — see below
//       'reason'   => null,              // why not, when avail is false
//       'class'    => 'premium',         // present only when the registry sent a class
//       'commands' => ['create' => ['years' => 1, 'fee' => '100.00']],
//       'periods'  => [
//           ['op' => 'create', 'years' => 1, 'fee' => '100.00'],
//           ['op' => 'create', 'years' => 2, 'fee' => '200.00'],
//           ['op' => 'create', 'years' => 5, 'fee' => '480.00'],
//       ],
//   ],
// ]
```

Three things to know about that shape:

- **`commands` holds one entry per operation** — the first period you asked for. When you asked for
  several periods, read `feeFor()` or `periods`. A loop over `commands` after asking `[1, 2, 5]`
  quietly reports the one-year price for all three.
- **`avail` here is about pricing, not about the name.** `false` means the registry could not quote
  it — a zone it does not serve, a currency it does not price in — and `reason` says which. Whether
  the *name* is free is `isAvailable()`, which is a different question with a different answer.
- **A quote can be `null` inside a period** with a `reason` beside it: the registry priced the name
  but not that operation. `null` is "no quote", never "free".

```php
$fee = $r->feeFor('example.com.ua', 'create', 1);
if ($fee === null) {
    throw new RuntimeException('no create quote for example.com.ua — do not assume a price');
}
```

`feeClass()` and `isPremium()` say which price list a name sits on:

```php
$r->feeClass('example.com.ua');    // 'premium' | 'standard' | null
$r->isPremium('example.com.ua');   // true when a class is present and is not 'standard'
```

Both take an optional name; with none they answer for the first name in the reply that carries a
class. **Take the price from `fees()`, not from the class.** The class says which list applies, not
what it costs, and a `false` from `isPremium()` is only "the answer declared no special class" — not
a promise of the standard price.

A different thing with a similar name: `prices()` and `priceChannel()` on a `domain:info` are the
registry's own price hints for a domain you already hold, not RFC 8748 quotes. They are in
[Responses](responses.md#domain).

---

## Capping what you agree to pay

The same extension, pointed the other way. On a transform you state the most you consent to pay, and
the registry refuses the command rather than charging more.

| Command | How the cap is passed | Wire |
|---|---|---|
| `create` | the `'fee'` option | `<fee:create>` |
| `renew` | the fourth argument | `<fee:renew>` |
| `transfer` | the fifth argument (on `request`) | `<fee:transfer>` |
| `restore` | the second argument | `<fee:update>` — a restore *is* an update |
| `update` | the `'fee'` option | `<fee:update>` |

Two shapes, everywhere:

```php
'fee' => '100.00',                                     // the amount, in the registry's own currency
'fee' => ['amount' => '100.00', 'currency' => 'UAH'],  // …and the currency it is in
```

```php
// Create
$client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'C-0001',
    'fee'        => '100.00',
]);

// Renew
$client->domain()->renew('example.com.ua', '2027-04-01', 1, ['amount' => '90.00', 'currency' => 'UAH']);

// Transfer in
$client->domain()->transfer('request', 'example.com.ua', 'the-code', 1, '120.00');

// Restore
$client->domain()->restore('example.com.ua', '1000.00');
```

The [builders](builders.md) spell it `maxFee()`, which is what it is:

```php
$client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->maxFee('100.00', 'UAH')
    ->send();
```

`maxFee()` also checks the amount is a plain decimal — `100`, `100.5`, `100.00` — and raises a
`ValidationException` before anything is sent if it is not. Passing the option directly hands your
string to the registry as it stands, and a malformed one comes back as a bare `2004`/`2005` naming no
field, after the command has been attempted.

### What a refusal at 2004 means

**`2004` on a command that carried a cap means the real price is higher than the cap. Nothing was
done and nothing was charged.**

```php
use EppTools\Exception\CommandException;
use EppTools\ResultCode;

try {
    $client->domain()->create('rare.com.ua', [
        'years'      => 1,
        'registrant' => 'C-0001',
        'fee'        => '100.00',
    ]);
} catch (CommandException $e) {
    if ($e->eppCode === ResultCode::PARAMETER_VALUE_RANGE_ERROR) {
        // The domain is NOT registered and you were NOT charged. Re-quote and decide again —
        // do not widen the cap in a loop until it passes, which is how a premium name gets
        // bought at a price nobody approved.
        $quote = $client->domain()->check(['rare.com.ua'], fee: ['create' => 1])
                        ->feeFor('rare.com.ua', 'create', 1);
        askAHumanAbout('rare.com.ua', $quote);
    }
}
```

That is the whole value of the cap: a tariff change, a premium name you did not know was premium, or
a stale price in your own cache becomes a refusal you can look at instead of an invoice you find
later. `2004` is also the generic "value out of range" code, so it can mean a period the zone does not
offer — the `reasons()` on the exception say which, and the cap is the first thing to check when the
command carried one.

Widening the cap automatically defeats it. If a create fails at `2004`, re-quote with `check()` and
either accept the new price deliberately or leave the name alone.

---

## Reading what a transform actually charged

```php
public function chargedFee(): ?array
public function feeAmount(): ?string
public function feeCurrency(): ?string
```

A successful transform that carried a fee agreement echoes what it charged:

```php
$r = $client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'C-0001',
    'fee'        => '100.00',
]);

$r->chargedFee();     // ['currency' => 'UAH', 'fee' => '100.00']
$r->feeAmount();      // '100.00'
$r->feeCurrency();    // 'UAH'
```

**Record `feeAmount()` against the order, not the figure you quoted from a `check`.** The quote was a
statement about a moment; this is what the registry billed. They agree almost always, and the whole
point of storing the second one is the case where they do not.

`null` means the reply carried no fee block — an ordinary answer for a command sent without a cap
against a registry that does not echo prices unasked. It never means "free".

The echo is read from whichever transform block the reply carries (`creData`, `renData`, `trnData`,
`updData`, `delData`), so the same three accessors work after a create, a renew, a transfer, a
restore and a delete.

---

## A price-checked registration, end to end

```php
use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\CommandException;
use EppTools\Exception\EppException;
use EppTools\Exception\InsufficientFundsException;
use EppTools\ResultCode;

$client = new Client(Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => 'your-secret',
    'caFile'   => '/path/to/registry-ca.pem',
]));

try {
    $client->connect();
    $client->login();

    $name = 'example.com.ua';

    // 1. Is there money to do this at all?
    $available = (string) $client->balance()->availableCredit();

    // 2. Ask availability and the price in one round trip.
    $check = $client->domain()->check([$name], fee: ['create' => [1, 2]], currency: 'UAH');

    if ($check->isAvailable($name) !== true) {
        echo $name, ' is not available: ', $check->unavailableReason($name) ?? 'no reason given', "\n";
        $client->logout();
        return;
    }

    $quote = $check->feeFor($name, 'create', 1);
    if ($quote === null) {
        throw new RuntimeException('no create quote — refusing to register at an unknown price');
    }
    if ($check->isPremium($name)) {
        echo "premium name, class {$check->feeClass($name)}\n";
    }
    if (bccomp($available, $quote, 2) < 0) {
        echo "available {$available} is short of {$quote}\n";
        $client->logout();
        return;
    }

    // 3. Register, capped at the price we were quoted a moment ago.
    $r = $client->domain()->create($name, [
        'years'      => 1,
        'registrant' => 'C-0001',
        'contacts'   => ['admin' => 'C-0001', 'tech' => 'C-0001'],
        'authInfo'   => 'D0main-Pw',
        'fee'        => $quote,
    ]);

    // 4. Store what was actually charged, and the registry's own dates and ids.
    echo 'registered ', $r->objectName(), ' until ', $r->expiryDate() ?? '-', "\n";
    echo 'charged    ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";
    echo 'svTRID     ', $r->svTRID(), "\n";

    if ($r->isPending()) {
        // 1001: queued. It is not registered yet; the verdict arrives as a poll notice.
        $orders->markPending((string) $r->svTRID());
    }

    $client->logout();
} catch (InsufficientFundsException $e) {
    alertBilling($e->getMessage());          // stop; every later billable command fails the same way
} catch (CommandException $e) {
    if ($e->eppCode === ResultCode::PARAMETER_VALUE_RANGE_ERROR) {
        echo "the price moved above the cap — nothing was registered or charged\n";
    } else {
        echo 'EPP ', $e->eppCode, ': ', $e->getMessage(), "\n";
    }
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
} finally {
    $client->disconnect();
}
```

Four habits in that program worth keeping: quote and cap with the **same** figure; store what was
charged rather than what was quoted; test `isPending()` before recording anything as done; and treat
`2104` as a reason to stop rather than to move on to the next name.

---

## Result codes on this page

| Code | Meaning | Exception |
|---|---|---|
| `1000` | done — the figures, or the quotes, are in the frame | — |
| `1001` | the transform was accepted and completes offline; the fee follows it | — |
| `2004` | the real price is above the cap you agreed, or a period is out of range. **Nothing charged** | `CommandException` |
| `2005` | a fee amount the registry cannot read as a number | `CommandException` |
| `2103` | the fee extension is not offered for this zone | `CommandException` |
| `2104` | insufficient funds; nothing was done | `InsufficientFundsException` |
| `2306` | the registry's policy refuses the query or the agreement | `PolicyException` |

A fee query with more than 20 entries, and a `maxFee()` amount that is not a plain decimal, are both
refused by this library with a `ValidationException` before anything is sent.

---

See also: [Domains](domains.md) · [Poll](poll.md) · [Responses](responses.md) ·
[Builders](builders.md) · [Errors](errors.md)

[← Manual index](README.md)
