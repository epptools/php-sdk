# Builders

The commands that take an options array can also be assembled one named step at a time.

```php
$response = $client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->adminContact('C-0001')
    ->techContact('C-0002')
    ->nameservers('ns1.acme.example', 'ns2.acme.example')
    ->authInfo('D0main-Pw')
    ->maxFee('100.00', 'UAH')
    ->send();
```

**Same command, same frame, same result.** A builder builds no XML of its own: `send()` hands its
options to the ordinary method, so a builder and the equivalent array produce an identical frame and
every check that applies to one applies to the other.

What changes is where a mistake surfaces. An options array accepts any key, so `'yeras' => 1` is
caught only because the library was taught the whole list of keys and refuses what is not on it. A
builder has no key to misspell: `->yeras(1)` is a method that does not exist, and your editor says so
as you type it.

Everything here assumes a connected, logged-in client — see [Session](session.md).

## The five builders

| Class | Obtained from | Sends |
|---|---|---|
| `DomainCreateBuilder` | `$client->domain()->createBuilder(string $name)` | `domain:create` |
| `DomainUpdateBuilder` | `$client->domain()->updateBuilder(string $name)` | `domain:update` |
| `ContactCreateBuilder` | `$client->contact()->createBuilder(string $id, string $email)` | `contact:create` |
| `ContactUpdateBuilder` | `$client->contact()->updateBuilder(string $id)` | `contact:update` |
| `HostUpdateBuilder` | `$client->host()->updateBuilder(string $name)` | `host:update` |

They live in `EppTools\Builder\`. You never construct one directly — the handler does, so the
builder already knows which client to send through.

There is deliberately no builder for `check`, `info`, `renew`, `transfer`, `delete` or `restore`.
Those take positional arguments, which the language already checks; a builder would add ceremony
without removing a class of mistake.

---

## Four rules that hold for every builder

### 1. Every list step accumulates

Passing several at once, calling the step again, or both, are the same thing:

```php
->techContact('C-0002', 'C-0003')
->techContact('C-0002')->techContact('C-0003')   // identical
```

That is what makes a builder read the way it behaves inside a loop or behind a condition:

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

foreach ($nameservers as $host) {
    $builder->nameserver($host);        // each call adds one
}
if ($needsDnssec) {
    $builder->dsRecord(12345, 13, 2, $digest);
}

$builder->send();
```

Single-valued steps **replace** instead: calling `->years(1)->years(2)` leaves `2`, the way assigning
a variable twice does. The tables below say which is which.

The steps that take names, handles and addresses — nameservers, contacts, domain and host statuses,
glue addresses — drop an empty or whitespace-only value rather than sending it as an empty element,
so a loop over a list with a blank in it does not produce `<domain:hostObj/>`. The two contact
status steps, `ContactUpdateBuilder::addStatus()` and `remStatus()`, pass what you give them
through, so filter that list yourself if it can contain a blank.

### 2. Nothing is sent until `send()`

Until then a builder is an ordinary value. Keep it, pass it to another function, build it in one
place and dispatch it in another.

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');
// …nothing has reached the registry…
$response = $builder->send();     // now it has
```

`send()` returns the [`Response`](responses.md), exactly as the direct call does.

### 3. `toOptions()` gives back exactly what the direct call takes — as a copy

```php
public function toOptions(): array
```

Available on every builder.

```php
$builder = $client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->techContact('C-0002');

$builder->toOptions();
// ['years' => 1, 'registrant' => 'C-0001', 'contacts' => ['tech' => ['C-0002']]]

// So this is the same command, by another road:
$client->domain()->create('example.com.ua', $builder->toOptions());
```

Two properties matter:

- **It is exactly the array the direct method takes.** That makes a builder queueable: serialise
  `toOptions()`, put it on a queue, and have a worker call `create()` with it.
- **It is a deep copy.** Handing back the live array would let it change under the caller every time
  another step was added, so what you logged and what you sent could differ. What you get is a value
  that is finished.

Calling it sends nothing and does not spend the builder.

### 4. A builder sends once

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

$builder->send();
$builder->send();
// ValidationException: EppTools\Builder\DomainCreateBuilder has already been sent.
//                      A builder carries one command; build another rather than re-sending this one.
```

A second `send()` on a create is a second registration and a second charge, and it is never what the
caller meant — a retry after a failure is not the same thing as replaying an object that has already
gone out. Build a new builder; they cost nothing. If the first `send()` failed in a way that leaves
the outcome unknown, read [Errors](errors.md#when-a-transform-fails-and-you-do-not-know-whether-it-happened)
before doing anything at all.

---

## DomainCreateBuilder

```php
$client->domain()->createBuilder(string $name): DomainCreateBuilder
```

Sends `domain:create` (RFC 5731 §3.2.1). Every option of [`domain()->create()`](domains.md#create)
has a step here.

| Step | Sets | Accumulates? |
|---|---|---|
| `years(int $years): self` | `years` — `<domain:period unit="y">`. Omit it and the registry applies its own default term | replaces |
| `registrant(string $handle): self` | `registrant` — the holder of the domain | replaces |
| `contact(string $role, string ...$handles): self` | `contacts[$role][]` — one `<domain:contact type="…">` per handle | accumulates |
| `adminContact(string ...$handles): self` | `contacts['admin'][]` | accumulates |
| `techContact(string ...$handles): self` | `contacts['tech'][]` | accumulates |
| `billingContact(string ...$handles): self` | `contacts['billing'][]` | accumulates |
| `nameserver(string $host): self` | `nameservers[]` — one host-object reference (`<domain:hostObj>`) | accumulates |
| `nameservers(string ...$hosts): self` | `nameservers[]` — the same, several at a time | accumulates |
| `nameserverWithGlue(string $host, string ...$addresses): self` | `nameservers[]` as `['name' => …, 'addresses' => [...]]` — inline glue (`<domain:hostAttr>`) | accumulates |
| `authInfo(string $password): self` | `authInfo` — the transfer secret | replaces |
| `license(string $number): self` | `license` — a trademark or licence number, where the registry requires one | replaces |
| `maxFee(string $amount, ?string $currency = null): self` | `fee` — the most you agree to pay (RFC 8748) | replaces |
| `dsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS['dsData'][]` | accumulates |
| `dsRecordWithKey(int $keyTag, int $alg, int $digestType, string $digest, int $flags, int $protocol, int $keyAlg, string $pubKey): self` | `secDNS['dsData'][]` with the DNSKEY it was computed from | accumulates |
| `keyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS['keyData'][]` | accumulates |
| `maxSigLife(int $seconds): self` | `secDNS['maxSigLife']` | replaces |
| `send(): Response` | calls `domain()->create($name, $options)` | terminal |

### A registration

```php
use EppTools\Exception\EppException;

try {
    $r = $client->domain()->createBuilder('example.com.ua')
        ->years(1)
        ->registrant('C-0001')
        ->adminContact('C-0001')
        ->techContact('C-0002', 'C-0003')     // one role, two handles
        ->nameservers('ns1.acme.example', 'ns2.acme.example')
        ->authInfo('D0main-Pw')
        ->maxFee('100.00', 'UAH')
        ->send();

    echo $r->objectName(), ' created ', $r->createdDate(), "\n";
    echo 'expires: ', $r->expiryDate() ?? '-', "\n";
    echo 'charged: ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";

    if ($r->isPending()) {
        // 1001 — the registry queued it. Not registered yet; the verdict arrives via poll.
        $orders->markPending((string) $r->svTRID());
    }
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
}
```

`contact()` takes any role name the registry recognises, so a zone with a role beyond
admin/tech/billing is reachable without a new method:

```php
->contact('reseller', 'C-0009')
```

An empty role raises a `ValidationException` — `contacts['' => …]` would emit
`<domain:contact type="">`, which the registry refuses with a `2005` naming nothing useful.

### The two delegation models

```php
// Host-object references: create the host objects first (see hosts.md).
->nameserver('ns1.acme.example')->nameserver('ns2.acme.example')

// Inline glue: the addresses travel with the name. IPv4 and IPv6 are told apart from the literal.
->nameserverWithGlue('ns1.example.com.ua', '203.0.113.1', '2001:db8::1')
->nameserverWithGlue('ns2.example.com.ua', '203.0.113.2')
```

RFC 5731 makes `<domain:ns>` a choice between the two, so one command uses one model or the other.
Mixing them is refused when the frame is built — a `ValidationException` naming the problem, rather
than a bare `2001` from the registry naming no field. Ask your registry which model it takes.

`nameserverWithGlue()` with an empty name raises immediately: a nameserver with no name is not
something to discover from the reply.

### DNSSEC on a create

```php
$client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->dsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->dsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->maxSigLife(1209600)
    ->send();
```

`dsRecordWithKey()` sends the DNSKEY the digest was computed from alongside the DS record. A registry
that accepts it can verify the digest against the key for you, catching a mistyped digest before it
reaches the zone; one that does not accept key data refuses the command rather than ignoring the
extra element, so trying costs nothing but a `2306`.

```php
->dsRecordWithKey(12345, 13, 2, '49FD46…', flags: 257, protocol: 3, keyAlg: 13, pubKey: 'AwEAA…')
```

`keyRecord()` signs with a bare public key instead of a DS record, where the registry takes those.
An empty digest or an empty public key raises a `ValidationException`.

`maxSigLife()` is only meaningful alongside a DS or key record.

---

## DomainUpdateBuilder

```php
$client->domain()->updateBuilder(string $name): DomainUpdateBuilder
```

Sends `domain:update` (RFC 5731 §3.2.5).

**An EPP update is a delta, not a replacement.** What you do not mention is left exactly as it is,
and *which block a change lands in is the whole semantics of the command*:

| Block | Means |
|---|---|
| `add` | keep what is there and add these |
| `rem` | take these away, leave the rest |
| `chg` | replace this single-valued field |

Sending a nameserver in `add` when you meant `rem` does not fail — it delegates the domain to a
server you were trying to remove, and the registry answers `1000`. That is why each step names its
block. Read the prefix of the method and you have read the semantics.

| Step | Block | Sets |
|---|---|---|
| `addNameserver(string $host): self` | `add` | `add['ns'][]` — delegate to one more, accumulates |
| `addNameservers(string ...$hosts): self` | `add` | `add['ns'][]` — several at a time, accumulates |
| `remNameserver(string $host): self` | `rem` | `rem['ns'][]` — stop delegating to one, accumulates |
| `remNameservers(string ...$hosts): self` | `rem` | `rem['ns'][]`, accumulates |
| `addContact(string $role, string ...$handles): self` | `add` | `add['contacts'][$role][]`, accumulates |
| `remContact(string $role, string ...$handles): self` | `rem` | `rem['contacts'][$role][]`, accumulates |
| `addStatus(string ...$statuses): self` | `add` | `add['statuses'][]`, accumulates |
| `remStatus(string ...$statuses): self` | `rem` | `rem['statuses'][]`, accumulates |
| `changeRegistrant(string $handle): self` | `chg` | `chg['registrant']`, replaces |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — replace the transfer secret, replaces |
| `clearAuthInfo(): self` | `chg` | `chg['clearAuthInfo'] = true` — **remove** the transfer secret |
| `restore(): self` | — | `restore = true` — RGP restore request (RFC 3915) |
| `license(string $number): self` | — | `license` — a trademark or licence number |
| `maxFee(string $amount, ?string $currency = null): self` | — | `fee` — the cap, when the change is billable |
| `addDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.add` | `secDNS['add']['dsData'][]`, accumulates |
| `remDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.rem` | `secDNS['rem']['dsData'][]`, accumulates |
| `addKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.add` | `secDNS['add']['keyData'][]`, accumulates |
| `remKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.rem` | `secDNS['rem']['keyData'][]`, accumulates |
| `removeAllDnssec(): self` | `secDNS.rem` | `secDNS['remAll'] = true` — unsign the domain entirely |
| `maxSigLife(int $seconds): self` | `secDNS.chg` | `secDNS['maxSigLife']`, replaces |
| `send(): Response` | — | calls `domain()->update($name, $options)` |

### Changing a delegation

```php
$r = $client->domain()->updateBuilder('example.com.ua')
    ->addNameserver('ns3.acme.example')
    ->remNameserver('ns2.acme.example')
    ->addStatus('clientTransferProhibited')
    ->remStatus('clientHold')
    ->changeRegistrant('C-0009')
    ->send();

echo $r->code(), ' ', $r->message(), "\n";       // 1000, or 1001 when the registry queues it

// An update answers with a result, not with the object. Read the new state back if you store it:
$after = $client->domain()->info('example.com.ua');
echo implode(', ', $after->nameservers()), "\n";
```

The statuses you may set are the `client*` family. The `server*` ones belong to the registry and an
attempt on them comes back `2304`.

`changeRegistrant()` is a change of holder, which many registries treat as its own procedure with its
own paperwork — a refusal there is usually policy rather than a malformed command.

### Revoking a leaked transfer code

```php
// The code has gone somewhere it should not have:
$client->domain()->updateBuilder('example.com.ua')->clearAuthInfo()->send();

// Later, when the customer needs one again:
$client->domain()->updateBuilder('example.com.ua')->changeAuthInfo('Fresh-D0main-Pw')->send();
```

`clearAuthInfo()` sends `<domain:authInfo><domain:null/></domain:authInfo>`, which **removes** the
secret. It is not the same as setting an empty one: an empty password is still a value the holder can
present, so the domain would stay exactly as movable as it was. The two are mutually exclusive — the
schema has no way to express both — so asking for both raises a `ValidationException` before anything
is sent.

### DNSSEC on an update

```php
// Rotate a key with no window in which the domain is unsigned:
$client->domain()->updateBuilder('example.com.ua')
    ->remDsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->addDsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->send();

// Unsign entirely:
$client->domain()->updateBuilder('example.com.ua')->removeAllDnssec()->send();

// Replace the whole key set in one operation:
$client->domain()->updateBuilder('example.com.ua')
    ->removeAllDnssec()
    ->addDsRecord(54321, 13, 2, 'A1B2C3…')
    ->send();
```

A record named in `remDsRecord()` must match what the registry holds in **every** field, not just the
key tag.

`removeAllDnssec()` and `remDsRecord()`/`remKeyRecord()` are mutually exclusive: the protocol cannot
express "remove everything and also remove this one", and a frame carrying both is refused. The
builder refuses the combination itself, in whichever order you write it, with a message that says
which two steps are in conflict.

### A restore through the update builder

```php
$client->domain()->updateBuilder('example.com.ua')
    ->restore()
    ->maxFee('1000.00', 'UAH')       // your cap, not a published price
    ->send();
```

Identical to [`domain()->restore('example.com.ua', '1000.00')`](domains.md#restore). No `add`, `rem`
or `chg` may travel with a restore — change the domain afterwards, in a second command.

---

## ContactCreateBuilder

```php
$client->contact()->createBuilder(string $id, string $email): ContactCreateBuilder
```

Sends `contact:create` (RFC 5733 §3.2.1).

**The id and the e-mail are constructor arguments, not steps**, because the registry requires both. A
builder that lets you forget a mandatory field has moved the error from your editor to the wire.

Pass `EppTools\Command\Contact::AUTO_ID` as the id to have the registry
[mint the handle](contacts.md#letting-the-registry-choose-the-handle) and read it back with
`objectName()`.

| Step | Sets | Accumulates? |
|---|---|---|
| `internationalAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` with `type => 'int'` | accumulates |
| `localizedAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` with `type => 'loc'` | accumulates |
| `voice(string $number): self` | `voice` — the EPP `+CC.NNNNNNNNN` form, optionally `x` and an extension | replaces |
| `fax(string $number): self` | `fax` — same form | replaces |
| `authInfo(string $password): self` | `authInfo` — the contact's transfer secret | replaces |
| `publish(string ...$fields): self` | `disclose` with `flag => true` — consent to publish these | replaces |
| `withhold(string ...$fields): self` | `disclose` with `flag => false` — withhold these | replaces |
| `send(): Response` | calls `contact()->create($id, $options)` | terminal |

```php
$r = $client->contact()->createBuilder('C-0001', 'contact@example.com')
    ->internationalAddress(
        name: 'Ivan Petrenko',
        city: 'Kyiv',
        countryCode: 'UA',
        street: ['1 Khreschatyk St'],
        org: 'ACME LLC',
        postalCode: '01001',
    )
    ->localizedAddress(
        name: 'Іван Петренко',
        city: 'Київ',
        countryCode: 'UA',
        street: ['вул. Хрещатик 1'],
        org: 'ТОВ «АКМЕ»',
        postalCode: '01001',
    )
    ->voice('+380.441234567')
    ->authInfo('C0nt@ct-Pw')
    ->withhold('voice', 'email')
    ->send();

echo $r->objectName(), "\n";      // 'C-0001' — the handle
```

Named arguments suit these two steps: the four optional parameters are all strings, and
`internationalAddress('ACME', 'Kyiv', 'UA', [], 'ACME LLC', null, '01001')` is a row of values whose
meaning you have to count out.

At least one address form is required. Give `internationalAddress()` unless you have a reason not to:
it is the form that survives being printed, e-mailed and read by a system that knows no Cyrillic, and
Cyrillic inside an `int` block is refused with `2005`. The localized form is additional, not
alternative — send both when you have both, and nothing is discarded.

### publish and withhold

RFC 5733 disclosure. The field names are `name`, `org`, `addr`, `voice`, `fax` and `email`; anything
else raises a `ValidationException` naming the six.

```php
->withhold('voice', 'email')     // these are withheld; everything else takes the opposite treatment
->publish('name', 'org')         // these may be published; everything else is withheld
```

**They are two ways of saying the same thing, and the second call replaces the first.** Pick the one
that matches how you think about the preference and do not call both — the flag is the whole meaning
of the list, so a block assembled from both halves says something neither call intended.

`name`, `org` and `addr` exist once per postal form, so naming one of them covers **both** forms.
Withholding only the ASCII form while the local one stayed public would be a privacy setting that
reads as applied and is not.

---

## ContactUpdateBuilder

```php
$client->contact()->updateBuilder(string $id): ContactUpdateBuilder
```

Sends `contact:update` (RFC 5733 §3.2.5). What you do not mention is left alone.

| Step | Block | Sets |
|---|---|---|
| `changeInternationalAddress(?string $name = null, ?string $city = null, ?string $countryCode = null, ?array $street = null, ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `chg` | `chg['postalInfos'][]` with `type => 'int'` — only the arguments you pass |
| `changeLocalizedAddress(…same parameters…): self` | `chg` | `chg['postalInfos'][]` with `type => 'loc'` |
| `changeVoice(string $number): self` | `chg` | `chg['voice']` |
| `changeFax(string $number): self` | `chg` | `chg['fax']` |
| `changeEmail(string $email): self` | `chg` | `chg['email']` |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — replace the transfer secret |
| `publish(string ...$fields): self` | `chg` | `chg['disclose']` with `flag => true` |
| `withhold(string ...$fields): self` | `chg` | `chg['disclose']` with `flag => false` |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, accumulates |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, accumulates |
| `send(): Response` | — | calls `contact()->update($id, $options)` |

```php
$client->contact()->updateBuilder('C-0001')
    ->changeEmail('new-contact@example.com')
    ->changeVoice('+380.441234500')
    ->addStatus('clientUpdateProhibited')
    ->send();
```

### An address is REPLACED, not merged

The block you pass **replaces** the one the registry holds. It is not merged field by field, so
anything you leave out is deleted:

| What you write | What happens |
|---|---|
| pass a value | the field is set to it |
| pass `''` | the field is **cleared** — the way to remove `org`, `stateProvince` or `postalCode` |
| omit the argument, or pass `null` | the field is not sent — and the registry deletes what it held |

RFC 5733 can be read as "leave it out and the registry keeps its value", since every child of
`chgPostalInfoType` is optional, but that reading is not safe. Against a registry that replaces —
**every command answering 1000** — a block sent without its `org` comes back with the organisation
gone, and a block carrying only an `org` leaves the contact with no postal address at all: name,
street, city, postal code and country.

`name`, `city` and `countryCode` are required in every address change for that reason, and the
builder refuses the call without them. They keep the frame valid; they cannot restore a field you did
not pass. **Read the block first and pass it back with your change applied:**

```php
$current = $client->contact()->info('C-0001')->postalInfo()['int'];

// Move the contact to Lviv and clear the organisation, keeping everything else as it was.
$client->contact()->updateBuilder('C-0001')
    ->changeInternationalAddress(
        name: $current['name'],
        city: 'Lviv',
        countryCode: 'UA',
        street: $current['street'] ?? null,
        org: '',
    )
    ->send();
```

The form you do not mention — local or international — is untouched: the two are addressed
separately.

### There is no clearAuthInfo() here

RFC 5731 gives a domain a nullable form, `<domain:authInfo><domain:null/>`; RFC 5733 defines no
equivalent for a contact. So a contact's transfer secret can be **replaced but not removed**. Do not
reach for an empty password as a substitute: an empty value is still a value the holder can present.
Set a fresh secret with `changeAuthInfo()` instead.

---

## HostUpdateBuilder

```php
$client->host()->updateBuilder(string $name): HostUpdateBuilder
```

Sends `host:update` (RFC 5732 §3.2.5).

| Step | Block | Sets |
|---|---|---|
| `addAddress(string $ip): self` | `add` | `addAddresses[]` — one glue address, accumulates |
| `addAddresses(string ...$ips): self` | `add` | `addAddresses[]` — several, accumulates |
| `remAddress(string $ip): self` | `rem` | `remAddresses[]`, accumulates |
| `remAddresses(string ...$ips): self` | `rem` | `remAddresses[]`, accumulates |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, accumulates |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, accumulates |
| `send(): Response` | — | calls `host()->update($name, $options)` |

```php
$client->host()->updateBuilder('ns1.example.com.ua')
    ->addAddresses('192.0.2.10', '2001:db8::10')
    ->remAddress('192.0.2.9')
    ->send();
```

IPv4 and IPv6 are told apart from the literal, so `v4` and `v6` end up correctly labelled without you
saying which is which.

**There is no rename step.** The registry does not implement `host:chg`, so a rename is not something
this builder can express — see [Hosts](hosts.md#there-is-no-rename) for the three commands that do the
job instead.

Adding and removing the same address in one command is a contradiction the registry resolves however
it chooses. Send one or the other.

---

## What a builder does not change

A builder is a façade over the options array, so everything the array is subject to still applies:

- **The same validation.** `send()` calls the ordinary method, which checks its options exactly as it
  would for a hand-written array. A builder cannot produce an unknown key, but it can produce a
  combination the command refuses.
- **The same result codes.** A `2302`, a `2104`, a `1001` mean what they mean; see
  [Errors](errors.md).
- **The same `throwOnFailure` behaviour.** With throwing off, `send()` returns the refusal as a
  `Response` instead of raising.
- **The same secret handling.** `authInfo()` sets a live credential. It is masked in the library's own
  logs — but `toOptions()` is your array, and if you log or queue it, it carries the password in
  clear. Mask it yourself before it reaches a log.

## Which steps raise before anything is sent

All of these are a `ValidationException`, and in every case no frame was built:

| Step | Raises when |
|---|---|
| any `contact(…)` / `addContact(…)` / `remContact(…)` | the role is empty or whitespace |
| `nameserverWithGlue(…)` | the nameserver name is empty |
| `dsRecord(…)`, `dsRecordWithKey(…)`, `addDsRecord(…)`, `remDsRecord(…)` | the digest is empty or whitespace |
| `keyRecord(…)`, `addKeyRecord(…)`, `remKeyRecord(…)`, `dsRecordWithKey(…)` | the public key is empty or whitespace |
| `removeAllDnssec()` after `remDsRecord()`/`remKeyRecord()`, or either after it | the two are mutually exclusive |
| `maxFee(…)` | the amount is not a plain decimal like `100.00` |
| `publish(…)` / `withhold(…)` | a field is not one of name, org, addr, voice, fax, email |
| `send()` | the builder has already been sent |

A malformed fee agreement is checked here rather than on the wire because it otherwise draws a bare
`2001` that names no field — and it arrives after the command has been attempted.

---

## When the array is the better tool

The builders and the arrays are the same thing, so use whichever fits:

- Building from a config file, a database row or a queue payload that is already an array: pass it
  straight to `create()`/`update()`. Turning it into a chain of calls only to have the builder turn it
  back into an array adds nothing.
- Writing a command out in code, especially an update: use the builder. `->remStatus('clientHold')`
  says which block the change lands in, in a place you cannot get wrong.

`toOptions()` is the bridge between the two, and it works in both directions: build with the fluent
API, store the array, replay it later with the direct call.

---

See also: [Domains](domains.md) · [Contacts](contacts.md) · [Hosts](hosts.md) ·
[Balance & prices](balance.md) · [Responses](responses.md) · [Errors](errors.md)

[← Manual index](README.md)
