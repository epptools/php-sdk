# Contacts

Contact objects follow **RFC 5733**. A contact is a person or an organisation with an address, a
phone number and an e-mail; a [domain](domains.md) references contacts by handle for the registrant
and for each role the zone requires. So contacts come first: create them, keep their handles, then
register domains against them.

Every contact command is reached through `$client->contact()` and returns a
[`Response`](responses.md). Everything here assumes a connected, logged-in client — see
[Session](session.md).

## The methods

| Method | EPP command |
|---|---|
| `check(array $ids): Response` | `<check>` |
| `info(string $id, ?string $authInfo = null): Response` | `<info>` |
| `create(string $id, array $options = []): Response` | `<create>` |
| `createAuto(array $options = []): Response` | `<create>` with the reserved id |
| `createBuilder(string $id, string $email): ContactCreateBuilder` | builds a `<create>` |
| `update(string $id, array $options = []): Response` | `<update>` |
| `updateBuilder(string $id): ContactUpdateBuilder` | builds an `<update>` |
| `delete(string $id): Response` | `<delete>` |
| `transfer(string $op, string $id, ?string $authInfo = null): Response` | `<transfer op="…">` |

One constant: `Contact::AUTO_ID` — the reserved id that asks the registry to
[mint the handle](#letting-the-registry-choose-the-handle).

`create()` and `update()` take an options array, and **an option key this library does not
understand is refused with a `ValidationException` before any frame is built**, naming the closest
key it knows. A silently ignored key would send a command the registry answers `1000` to, with the
part you asked for missing.

---

## check

```php
public function check(array $ids): Response
```

**On the wire:** `<command><check><contact:check><contact:id>…` — RFC 5733 §3.1.1. This asks whether
an **identifier** is free, not whether a person exists.

```php
$r = $client->contact()->check(['acme-01', 'acme-02']);

$r->availability();               // ['acme-01' => false, 'acme-02' => true]
$r->isAvailable('acme-02');       // true | false | null ("the answer said nothing")
$r->unavailableReason('acme-01'); // 'In use', or null when the id is free
```

Handles are a shared namespace across the whole registry, so a scheme of your own with a prefix you
control is worth more than a check-then-create loop. If you have no scheme,
[let the registry mint the handle](#letting-the-registry-choose-the-handle) and skip the collision
question entirely.

**Result codes:** `1000` for any well-formed check; `2005` names a syntactically invalid id.

---

## info

```php
public function info(string $id, ?string $authInfo = null): Response
```

**On the wire:** `<command><info><contact:info><contact:id>` — RFC 5733 §3.1.2. Pass `$authInfo` and
it goes out as `<contact:authInfo><contact:pw>`, which is how a registrar that does not sponsor the
contact reads the full record.

```php
$c = $client->contact()->info('acme-01');

$c->objectName();     // 'acme-01' — the HANDLE, not the person's name
$c->roid();           // the registry's own object id
$c->statuses();       // ['linked'], ['ok'], ['clientUpdateProhibited'], …
$c->sponsor();        // clID
$c->createdBy();      // crID          $c->createdDate();   // crDate
$c->updatedBy();      // upID, or null $c->updatedDate();   // upDate
$c->authInfo();       // the transfer secret — never log it

$c->email();          // 'contact@example.com'
$c->voice();          // '+380.441234567' — the EPP +CC.NNNN form
$c->fax();            // likewise, or null

$c->postalInfo();     // ['int' => [...], 'loc' => [...]]
$c->disclose();       // ['flag' => false, 'elements' => ['email', 'voice']] or null
```

`objectName()` gives the handle. Reading the person's name means going into the postal block:

```php
$postal = $c->postalInfo();
$postal['int']['name'];      // 'ACME LLC'
$postal['int']['street'];    // ['1 Khreschatyk St'] — an array, up to 3 lines
$postal['int']['city'];      // 'Kyiv'
$postal['int']['cc'];        // 'UA'
$postal['loc']['name'] ?? '';// the local-script form, when the contact carries one
```

Each entry holds `name`, `org`, `street` (array), `city`, `sp`, `pc`, `cc`, with missing parts as
`''`. A contact may carry the `int` form, the `loc` form, or both — index defensively.

`disclose()` returns `null` when the contact expresses no preference and registry policy alone
applies. When it is present, `flag` decides what the list means: `true` says the listed elements may
be published, `false` says they must be withheld, and everything **not** listed takes the opposite.
The list is meaningless without the flag, so never read one without the other.

**Result codes:** `1000`; `2202` (wrong `authInfo` as a non-sponsor); `2303` (no such handle).

---

## create

```php
public function create(string $id, array $options = []): Response
```

**On the wire:** `<command><create><contact:create>` — RFC 5733 §3.2.1.

### Every option

| key | value | wire |
|---|---|---|
| `type` | `'int'` (default) or `'loc'` | the `type` attribute of the single flat block |
| `name` | string | `<contact:name>` |
| `org` | string | `<contact:org>` — sent only when non-empty |
| `street` | `string[]`, up to 3 lines | one `<contact:street>` per line |
| `city` | string | `<contact:city>` |
| `sp` | string | `<contact:sp>` — sent only when non-empty |
| `pc` | string | `<contact:pc>` — sent only when non-empty |
| `cc` | 2-letter country code | `<contact:cc>` |
| `postalInfos` | a list of blocks, each shaped like the flat keys above | one `<contact:postalInfo>` per entry |
| `voice` | `+CC.NNNN` | `<contact:voice>` — sent only when non-empty |
| `fax` | `+CC.NNNN` | `<contact:fax>` — sent only when non-empty |
| `email` | string | `<contact:email>` — **required** |
| `authInfo` | string | `<contact:authInfo><contact:pw>` |
| `disclose` | see [the disclose block](#the-disclose-block) | `<contact:disclose flag="0\|1">` |

`email` is required by RFC 5733 and an empty one raises a `ValidationException` here rather than
travelling to the registry as an empty element and coming back as an opaque `2005`.

`<contact:authInfo>` is always emitted. Give the `authInfo` option and your value travels in it;
leave it out and an empty `<contact:pw/>` goes instead, which hands the choice to the registry's own
policy.

### The two postal forms

The flat keys build **one** block. Pass `postalInfos` instead to send both forms in one command:

```php
$r = $client->contact()->create('acme-01', [
    'postalInfos' => [
        [
            // int: ASCII / Latin only. This is the form the registry can show to any party,
            // so at least one of these is needed. Cyrillic here is refused with 2005.
            'type'   => 'int',
            'name'   => 'Ivan Petrenko',
            'org'    => 'ACME LLC',
            'street' => ['1 Khreschatyk St'],
            'city'   => 'Kyiv',
            'pc'     => '01001',
            'cc'     => 'UA',
        ],
        [
            // loc: the local script, as the registrant actually wrote it.
            'type'   => 'loc',
            'name'   => 'Іван Петренко',
            'org'    => 'ТОВ «АКМЕ»',
            'street' => ['вул. Хрещатик 1'],
            'city'   => 'Київ',
            'pc'     => '01001',
            'cc'     => 'UA',
        ],
    ],
    'voice'    => '+380.441234567',
    'email'    => 'contact@example.com',
    'authInfo' => 'C0nt@ct-Pw',
]);

echo $r->objectName(), ' created ', $r->createdDate(), "\n";   // 'acme-01'
```

Send both forms whenever you have both. Nothing is discarded, and `info()` returns everything you
sent.

The short form, for an ASCII-only contact:

```php
$client->contact()->create('acme-02', [
    'name'   => 'ACME LLC',
    'city'   => 'Kyiv',
    'cc'     => 'UA',
    'email'  => 'contact@example.com',
]);
```

`type` defaults to `int`, which is what you want for a Latin-script address. Set `'type' => 'loc'`
when the single block you are sending is in the local script.

### The disclose block

RFC 5733 privacy preferences. `name`, `org` and `addr` are per-form, so they take a list of the
forms they apply to; `voice`, `fax` and `email` are bare flags.

```php
'disclose' => [
    'flag'  => false,      // false: withhold what is listed. true: consent to publish it.
    'addr'  => ['int'],    // the international address block
    'name'  => ['int', 'loc'],
    'voice' => true,
    'email' => true,
],
```

The flag is the whole meaning of the list. `['flag' => false, 'email' => true]` **withholds** the
e-mail address; `['flag' => true, 'email' => true]` consents to publishing it. Elements you do not
list take the opposite of the flag, so a block with a flag and nothing in it says something too.

**Result codes:** `1000`; `2003` (no postal block, or no e-mail); `2005` (bad syntax — a malformed
e-mail, or Cyrillic in an `int` block); `2302` (the id is taken); `2306` (policy, e.g. an `authInfo`
below the zone's strength rule).

---

## Letting the registry choose the handle

```php
public function createAuto(array $options = []): Response
public const AUTO_ID = 'autonic';
```

`createAuto()` sends the reserved id `autonic` in place of a handle, and the registry mints one for
you. The reply is the **only** place the minted handle appears, so store what `objectName()` gives
you:

```php
$handle = $client->contact()->createAuto([
    'name'  => 'ACME LLC',
    'city'  => 'Kyiv',
    'cc'    => 'UA',
    'email' => 'contact@example.com',
])->objectName();       // e.g. 'c-9f4b2ad10e' — persist this before doing anything else

$client->domain()->create('example.com.ua', ['years' => 1, 'registrant' => $handle]);
```

Use it when you have no naming scheme of your own, or when you would otherwise be retrying around
`2302` because somebody else took the handle first. **Every call mints a fresh handle**, so a repeat
is a second contact rather than a collision — which also means a retry after an unclear failure
creates a duplicate. If a `createAuto()` call ends in an unknown outcome, reconcile before calling
it again.

`Contact::AUTO_ID` is the constant behind it, and passing it to `create()` or `createBuilder()` does
the same thing:

```php
use EppTools\Command\Contact;

$client->contact()->create(Contact::AUTO_ID, ['name' => 'ACME LLC', 'city' => 'Kyiv',
    'cc' => 'UA', 'email' => 'contact@example.com']);
```

The reserved value is never stored as a handle, so it stays usable by everyone.

---

## update

```php
public function update(string $id, array $options = []): Response
```

**On the wire:** `<command><update><contact:update>` — RFC 5733 §3.2.5.

| key | value |
|---|---|
| `chg` | `['postalInfo' => [...], 'postalInfos' => [[...]], 'voice' => …, 'fax' => …, 'email' => …, 'authInfo' => …, 'disclose' => [...]]` |
| `addStatuses` | `string[]` — client-side statuses to set |
| `remStatuses` | `string[]` — client-side statuses to clear |

```php
$client->contact()->update('acme-01', [
    'chg'         => ['email' => 'new-contact@example.com', 'voice' => '+380.441234500'],
    'addStatuses' => ['clientUpdateProhibited'],
]);
```

All the statuses in `addStatuses` go into one `<contact:add>` block and all of `remStatuses` into
one `<contact:rem>`, which is what the RFC 5733 schema allows — one of each, holding up to seven
statuses.

### The partial-update rule: presence decides

Inside a postal block **presence is the whole semantics**, and there are three distinct states:

| what you write | what happens |
|---|---|
| the key is absent | the field is not sent, and the registry keeps what it holds |
| the key holds a value | the field is set to that value |
| the key holds `''` | the field is **cleared** — the only way to remove `org`, `sp` or `pc` |

```php
// Change the city, clear the organisation, leave the name and street exactly as they are.
$client->contact()->update('acme-01', [
    'chg' => ['postalInfo' => [
        'type' => 'int',
        'city' => 'Lviv',
        'cc'   => 'UA',
        'org'  => '',      // cleared
    ]],
]);
```

**Give `city` and `cc` whenever you touch the address.** `<contact:addr>` is a schema sequence with
both of them required, so it is emitted whole or not at all: the moment you mention `street`, `city`,
`sp`, `pc` or `cc`, the whole block goes out, and `city` and `cc` travel with it whether or not you
supplied them. Omitting them there sends them empty.

Change both forms in one command with `postalInfos`:

```php
$client->contact()->update('acme-01', [
    'chg' => ['postalInfos' => [
        ['type' => 'int', 'city' => 'Lviv', 'cc' => 'UA'],
        ['type' => 'loc', 'city' => 'Львів', 'cc' => 'UA'],
    ]],
]);
```

The form you do not mention is left alone.

### Changing the transfer secret

```php
$client->contact()->update('acme-01', ['chg' => ['authInfo' => 'Fresh-C0nt@ct-Pw']]);
```

A contact's transfer secret can be **replaced but not removed**: RFC 5731 gives a domain a nullable
form and RFC 5733 defines no equivalent for a contact. Do not reach for an empty password as a
substitute — an empty value is still a value the holder can present. Set a fresh secret instead.

### Changing disclosure

```php
$client->contact()->update('acme-01', [
    'chg' => ['disclose' => ['flag' => false, 'email' => true, 'voice' => true]],
]);
```

The block is sent whole, so state the complete preference each time rather than the difference from
the last one.

**Result codes:** `1000`; `2303` (no such handle); `2304` (a status prohibits it); `2306` (policy);
`2308` (the change would remove something the registry requires).

### Building an update step by step

```php
public function createBuilder(string $id, string $email): ContactCreateBuilder
public function updateBuilder(string $id): ContactUpdateBuilder
```

The id and the e-mail are arguments to `createBuilder()` rather than steps, because the registry
requires both and a step is something you can forget. On the update builder the same
presence-decides rule appears as "pass the argument to change it, pass `''` to clear it, omit it to
leave it alone". Every step is documented in [Builders](builders.md).

---

## delete

```php
public function delete(string $id): Response
```

**On the wire:** `<command><delete><contact:delete>` — RFC 5733 §3.2.2.

A contact still referenced by a domain cannot be deleted; the registry answers **`2305`**. The
`linked` status in `statuses()` is the registry saying so in advance:

```php
$c = $client->contact()->info('acme-01');

if (in_array('linked', $c->statuses(), true)) {
    // Still in use. Repoint the domains that reference it first — allContacts() on a
    // domain:info tells you which handles a domain holds.
    return;
}

$client->contact()->delete('acme-01');
```

**Result codes:** `1000`; `2303`; `2305` (still linked to a domain).

---

## transfer

```php
public function transfer(string $op, string $id, ?string $authInfo = null): Response
```

**On the wire:** `<command><transfer op="…"><contact:transfer>` — RFC 5733 §3.2.4 (and §3.1.3 for
`query`). `$op` is one of `request`, `query`, `approve`, `reject`, `cancel`, with the same meanings
as for a [domain transfer](domains.md#transfer) — `request` and `cancel` belong to the gaining
registrar, `approve` and `reject` to the current sponsor.

```php
$r = $client->contact()->transfer('request', 'acme-01', 'the-code');

$r->code();             // 1000, or 1001 when the sponsor has to answer
$r->transferStatus();   // 'pending'
$r->transfer();         // ['status','requestedBy','requestedAt','actingClient','actBy','expiryDate']
```

The request reaches the sponsor as a [poll notice](poll.md) carrying a `trnData`. As the sponsor:

```php
$client->contact()->transfer('approve', 'acme-01');
// or
$client->contact()->transfer('reject', 'acme-01');
```

`query` reports where a request has got to — `2300` while one is pending, `2301` when none is.

**Result codes:** `1000` / `1001`; `2201` (not yours to act on); `2202` (wrong `authInfo`); `2300`
(already pending); `2301` (nothing pending); `2303`; `2304`.

---

## Result codes on this page

| Code | Meaning | Exception |
|---|---|---|
| `1000` | done | — |
| `1001` | accepted, completing offline; the outcome arrives via [poll](poll.md) | — |
| `2003` | a required parameter is missing (postal block, e-mail) | `CommandException` |
| `2005` | a value is syntactically invalid (e-mail, Cyrillic in an `int` block) | `CommandException` |
| `2201` | not yours to act on | `AuthorizationException` |
| `2202` | wrong `authInfo` | `AuthorizationException` |
| `2300` / `2301` | already pending transfer / not pending transfer | `CommandException` |
| `2302` | the id is taken | `ObjectExistsException` |
| `2303` | no such handle | `ObjectDoesNotExistException` |
| `2304` / `2305` | a status prohibits it / still linked to a domain | `ObjectStatusException` |
| `2306` / `2308` | registry policy refuses this value | `PolicyException` |

`ResultCode` has a named constant for every one of them; the full taxonomy is in
[Errors](errors.md).

---

See also: [Domains](domains.md) · [Hosts](hosts.md) · [Poll](poll.md) ·
[Balance & prices](balance.md) · [Responses](responses.md) · [Builders](builders.md)

[← Manual index](README.md)
