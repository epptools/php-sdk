# Hosts

Host (nameserver) objects follow **RFC 5732**. Where a registry delegates domains by reference, a
nameserver has to exist as a host object before a [domain](domains.md) can point at it with
`<domain:hostObj>`. Where a registry takes the glue inlined instead, you may never create a host
object at all — see [the two nameserver models](domains.md#nameservers-the-two-models).

Every host command is reached through `$client->host()` and returns a [`Response`](responses.md).
Everything here assumes a connected, logged-in client — see [Session](session.md).

## The methods

| Method | EPP command |
|---|---|
| `check(array $names): Response` | `<check>` |
| `info(string $name): Response` | `<info>` |
| `create(string $name, array $addresses = []): Response` | `<create>` |
| `update(string $name, array $options = []): Response` | `<update>` |
| `updateBuilder(string $name): HostUpdateBuilder` | builds an `<update>` |
| `delete(string $name, bool $force = false): Response` | `<delete>`, optionally with the forced-detach extension |

There is no `renew` and no `transfer` for a host: RFC 5732 defines neither. A host follows the domain
it lives under, and there is nothing to bill for it.

## Subordinate and external hosts

The distinction decides whether a host may carry addresses at all, and it is the source of most
first-run refusals:

| | lives under | glue addresses |
|---|---|---|
| **subordinate** | a domain in a zone this registry serves (`ns1.example.com.ua` under `example.com.ua`) | **required** — without one the create is `2003` |
| **external** | a domain elsewhere (`ns1.acme.example`) | **refused** — its addresses live at its own registry, so sending one is `2306` |

A client that always emits an address must omit it for external hosts. Addresses must be public
Internet addresses, and there is a per-host limit (13 at this registry) beyond which the frame is
refused.

---

## check

```php
public function check(array $names): Response
```

**On the wire:** `<command><check><host:check><host:name>…` — RFC 5732 §3.1.1.

```php
$r = $client->host()->check(['ns1.example.com.ua', 'ns2.example.com.ua']);

$r->availability();                       // ['ns1.example.com.ua' => false, 'ns2.example.com.ua' => true]
$r->isAvailable('ns2.example.com.ua');    // true | false | null
$r->unavailableReason('ns1.example.com.ua');
```

`avail => false` means the host object already exists at the registry — which is frequently what you
want, since a host you were about to create is one you can simply reference. Host objects are a
registry-wide namespace: a nameserver another registrar created is visible to you and referenced by
name.

**Result codes:** `1000` for any well-formed check; `2005` names a syntactically invalid host name.

---

## info

```php
public function info(string $name): Response
```

**On the wire:** `<command><info><host:info><host:name>` — RFC 5732 §3.1.2. There is no `authInfo`
argument: a host object carries no transfer secret of its own.

```php
$h = $client->host()->info('ns1.example.com.ua');

$h->objectName();       // 'ns1.example.com.ua'
$h->roid();             // the registry's own object id
$h->statuses();         // ['ok'], ['linked'], ['clientUpdateProhibited'], …
$h->sponsor();          // clID
$h->createdBy();        // crID          $h->createdDate();  // crDate
$h->updatedBy();        // upID, or null $h->updatedDate();  // upDate

foreach ($h->hostAddresses() as $addr) {
    echo $addr['version'], ' ', $addr['ip'], "\n";   // 'v4 203.0.113.10'
}
```

`hostAddresses()` returns `[['ip' => '203.0.113.10', 'version' => 'v4'], …]`. **An empty list is a
normal answer for an external host**, not a missing one: only a host inside a zone the registry
serves carries glue.

The `linked` status means at least one domain uses this host as a nameserver. That is what stands
between you and a [delete](#delete).

**Result codes:** `1000`; `2303` (no such host).

---

## create

```php
public function create(string $name, array $addresses = []): Response
```

**On the wire:** `<command><create><host:create>` with one `<host:addr ip="v4|v6">` per address —
RFC 5732 §3.2.1.

The IP version is detected from the literal, so you pass a flat list and `v4` and `v6` end up
correctly labelled:

```php
// Subordinate host: glue is required.
$r = $client->host()->create('ns1.example.com.ua', ['203.0.113.10', '2001:db8::10']);

echo $r->objectName(), ' created ', $r->createdDate(), "\n";

// External host: no addresses at all.
$client->host()->create('ns1.acme.example');
```

A complete first delegation — create the hosts, then point the domain at them:

```php
foreach (['ns1.example.com.ua' => '203.0.113.10', 'ns2.example.com.ua' => '203.0.113.11'] as $ns => $ip) {
    if ($client->host()->check([$ns])->isAvailable($ns) === true) {
        $client->host()->create($ns, [$ip]);
    }
}

$client->domain()->update('example.com.ua', [
    'add' => ['ns' => ['ns1.example.com.ua', 'ns2.example.com.ua']],
]);

echo implode(', ', $client->domain()->info('example.com.ua')->nameservers()), "\n";
```

**Result codes:** `1000`; `2001` (more addresses than the per-host limit); `2003` (a subordinate host
with no address); `2005` (a malformed address or name); `2302` (the host already exists); `2306` (an
address on an external host).

---

## update

```php
public function update(string $name, array $options = []): Response
```

**On the wire:** `<command><update><host:update>` — RFC 5732 §3.2.5. Like every EPP update this is a
**delta**: what you do not mention is left alone.

| key | value | wire |
|---|---|---|
| `addAddresses` | `string[]` | `<host:addr>` inside `<host:add>` |
| `remAddresses` | `string[]` | `<host:addr>` inside `<host:rem>` |
| `addStatuses` | `string[]` | `<host:status s="…">` inside `<host:add>` |
| `remStatuses` | `string[]` | `<host:status s="…">` inside `<host:rem>` |

```php
// Renumber a nameserver: add the new address and remove the old one in one command, so the
// host is never left without glue.
$client->host()->update('ns1.example.com.ua', [
    'addAddresses' => ['203.0.113.20'],
    'remAddresses' => ['203.0.113.10'],
]);

echo implode(', ', array_column($client->host()->info('ns1.example.com.ua')->hostAddresses(), 'ip')), "\n";
```

An address you remove must match what the registry holds. The block for a side you do not use is not
sent at all, so `['addAddresses' => ['203.0.113.20']]` emits an `<host:add>` and nothing else.

The statuses you may set are the `client*` family — `clientUpdateProhibited` and
`clientDeleteProhibited`. `linked`, `ok` and the `server*` statuses belong to the registry.

The same address rules as on a create apply here: an external host may not gain addresses (`2306`),
and a subordinate one may not be left with none (`2003`).

**Result codes:** `1000`; `2001` (more addresses than the per-host limit); `2003`; `2303`; `2304` (a
status prohibits it); `2306`.

### Building an update step by step

```php
public function updateBuilder(string $name): HostUpdateBuilder
```

`addAddress` / `addAddresses`, `remAddress` / `remAddresses`, `addStatus`, `remStatus`, then
`send()`. Every step is documented in [Builders](builders.md).

---

## There is no rename

**A host object cannot be renamed.** The registry reads only the `add` and `rem` blocks of
`host:update`; a name change has nowhere to go, and a frame that carried one alongside an address
change would apply the addresses, drop the rename, and still answer `1000` — leaving you believing a
nameserver moved when it did not.

So `update()` refuses a `newName` option outright:

```php
$client->host()->update('ns1.example.com.ua', ['newName' => 'ns9.example.com.ua']);
// ValidationException: host:update cannot rename a nameserver at this registry — create the
// new host, repoint the domains that use it, then delete the old one
```

That sequence is the rename, and it is three steps:

```php
// 1. Create the new host with the same addresses.
$old = $client->host()->info('ns1.example.com.ua');
$client->host()->create('ns9.example.com.ua', array_column($old->hostAddresses(), 'ip'));

// 2. Repoint every domain that uses the old one. There is no registry-side list of these —
//    it comes from your own records of what you delegated where.
foreach ($yourDomainsUsingIt as $domain) {
    $client->domain()->update($domain, [
        'add' => ['ns' => ['ns9.example.com.ua']],
        'rem' => ['ns' => ['ns1.example.com.ua']],
    ]);
}

// 3. Only once nothing references it — otherwise this is a 2305.
$client->host()->delete('ns1.example.com.ua');
```

Add before removing, in one command per domain, so the domain is never momentarily undelegated.

---

## delete

```php
public function delete(string $name, bool $force = false): Response
```

**On the wire:** `<command><delete><host:delete>` — RFC 5732 §3.2.2. With `$force`, an
`<registry:delete><registry:deleteNS confirm="yes"/>` block rides along in `<extension>`.

A host still used as a nameserver by any domain cannot be deleted: the registry answers **`2305`**.
The `linked` status is the advance warning.

```php
$h = $client->host()->info('ns1.example.com.ua');

if (in_array('linked', $h->statuses(), true)) {
    // Detach it from the domains that use it first, or use the forced delete below.
    return;
}

$client->host()->delete('ns1.example.com.ua');
```

### Forced delete

```php
$client->host()->delete('ns1.example.com.ua', force: true);
```

This removes the host from the nameserver set of **every** domain that referenced it, then deletes
it. The `confirm="yes"` the registry requires is sent for you — which is the point of the flag being
a separate argument rather than a default.

Understand what it costs before you use it: a domain left with fewer nameservers than the zone
requires goes `inactive` and stops resolving. It is the right tool for a nameserver you are
decommissioning and the wrong one for tidying up. Where you can, repoint the domains first and use
the ordinary delete.

**Result codes:** `1000`; `2303` (no such host); `2305` (still used as a nameserver — the ordinary
delete); `2400` (the forced detach could not complete).

---

## Result codes on this page

| Code | Meaning | Exception |
|---|---|---|
| `1000` | done | — |
| `2001` | the frame is malformed — e.g. more addresses than the per-host limit | `CommandException` |
| `2003` | a subordinate host with no glue address | `CommandException` |
| `2005` | a malformed address or host name | `CommandException` |
| `2302` | the host already exists | `ObjectExistsException` |
| `2303` | no such host | `ObjectDoesNotExistException` |
| `2304` / `2305` | a status prohibits it / still used as a nameserver | `ObjectStatusException` |
| `2306` | policy — e.g. an address on an external host | `PolicyException` |
| `2400` | the registry could not complete it; may be transient | `CommandException` (`isRetryable()`) |

A `newName` option never reaches the registry: it is a `ValidationException`, thrown before the
frame is built. `ResultCode` has a named constant for every code above; the full taxonomy is in
[Errors](errors.md).

---

See also: [Domains](domains.md) · [Contacts](contacts.md) · [Poll](poll.md) ·
[Responses](responses.md) · [Builders](builders.md)

[← Manual index](README.md)
