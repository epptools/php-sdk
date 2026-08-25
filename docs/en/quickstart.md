# Quickstart

Install the library, open a session, ask the registry three real questions and close the session
cleanly. One program, then a walk through every line of it.

## Install

```bash
composer require epptools/sdk
```

Or straight from GitHub, pinned to a release tag, if you would rather not depend on Packagist:

```bash
composer config repositories.epptools vcs https://github.com/epptools/php-sdk
composer require epptools/sdk:^1.0
```

No Composer at all: clone the repository and `require __DIR__ . '/php-sdk/autoload.php';`. It
registers a minimal PSR-4 autoloader for the `EppTools\` namespace.

## What you need before you run it

| What | Where it comes from |
|---|---|
| Host and port | Your account. The port is `700` unless you were told otherwise. |
| Your clID | Your registrar identifier, e.g. `EXAMPLE`. |
| Password | Issued with the account, changed through `login()` — see [Session](session.md). |
| The registry CA certificate | Port 700 presents a certificate from the registry's own private CA. Ask the registry for the `.pem` bundle. |
| An allow-listed source address | Your server's public IP must be registered against the clID, or the login is refused with 2200. |

## The whole program

Save it as `epp-first-run.php`, fill in the four values at the top, and run it with
`php epp-first-run.php`.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\EppException;

$config = Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => 'your-secret',
    'lang'     => 'en',                          // result messages in this language, if the greeting lists it
    // 'caFile' => '/etc/epp/registry-ca.pem',   // only for a private-CA or self-signed certificate
]);

$client = new Client($config);

try {
    $greeting = $client->connect();
    echo 'server: ' . ($greeting->value('svID') ?? 'unnamed') . "\n";

    $login = $client->login();
    foreach ($login->securityEvents() as $event) {
        fwrite(STDERR, sprintf(
            "login %s (%s): %s\n",
            $event['level'] ?? 'warning',
            $event['type'] ?? 'custom',
            $event['text']
        ));
    }

    $check = $client->domain()->check(['example.com.ua'], fee: ['create' => 1]);

    if ($check->isAvailable('example.com.ua') === true) {
        echo "example.com.ua is available\n";
        echo '  create, 1 year: ' . ($check->feeFor('example.com.ua', 'create', 1) ?? 'not quoted')
            . ' ' . ($check->fees()['_currency'] ?? '') . "\n";
    } else {
        echo 'example.com.ua is taken: '
            . ($check->unavailableReason('example.com.ua') ?? 'no reason given') . "\n";

        $info = $client->domain()->info('example.com.ua');
        echo '  sponsor: ' . ($info->sponsor() ?? '-') . "\n";
        echo '  expires: ' . ($info->expiryDate() ?? '-') . "\n";
        echo '  status:  ' . implode(', ', $info->statuses() ?: ['-']) . "\n";
        echo '  ns:      ' . implode(', ', $info->nameservers() ?: ['-']) . "\n";
    }

    $balance = $client->balance();
    echo 'available credit: ' . ($balance->availableCredit() ?? '-') . "\n";

    $client->logout();
} catch (EppException $e) {
    fwrite(STDERR, 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client->disconnect();
}
```

Expected output against a live account, when the name is registered by someone else:

```
server: Registry EPP Server
example.com.ua is taken: In use
  sponsor: ACME
  expires: 2027-04-01T09:15:00Z
  status:  ok
  ns:      ns1.example.com.ua, ns2.example.com.ua
available credit: 12500.00
```

Nothing in this program changes anything or costs anything. `check`, `info` and the balance query
are all read-only, which is what makes them the right first commands: they are the only ones that
are safe to repeat blindly.

## Line by line

### `require __DIR__ . '/vendor/autoload.php';`

Composer's autoloader. Without Composer, require the SDK's own `autoload.php` instead.

### `Config::fromArray([...])`

The connection settings. `fromArray()` takes the same names as the constructor and fills in every
default, so a short array is enough to start:

- **`host`** and **`clid`** and **`password`** have no useful defaults. An empty `host` raises a
  `ConfigException` from `connect()`; an empty `clid` or `password` raises one from `login()`,
  before a byte reaches the wire.
- **`caFile`** is required here. The endpoint on port 700 presents a certificate issued by the
  registry's own private CA, which is in no system trust store, so with peer verification left at
  its correct default the handshake fails without it.
- **`port`** defaults to 700. `lang` defaults to `en`. Everything else is covered field by field in
  [Session](session.md).

Do not put the password in the file. Read it from the environment or from your secret store; the
example spells it out only so the program is complete.

### `new Client($config)`

Constructing the client opens nothing. It gives you the object; the socket comes next. A second
optional argument takes a custom transport and a third takes a PSR-3 logger — see
[Session](session.md).

### `$greeting = $client->connect();`

Opens the TLS socket and reads the server's unsolicited `<greeting>`, which is the first thing an
EPP server sends (RFC 5730). The greeting lists the object and extension namespaces this server
supports, and `login()` mirrors them back so the session is never refused for announcing a service
the server does not offer.

`connect()` returns the greeting as a [`Response`](responses.md). `$greeting->value('svID')` reads
the server's name out of it. If the first frame is not a greeting, `connect()` raises a
`ConnectionException` rather than treating whatever arrived as the service list.

### `$login = $client->login();`

Sends `<login>` with your clID and password, the protocol version, the language and the service
list taken from the greeting. It returns the login response.

### The `securityEvents()` loop

Where the server offers the Login Security extension (RFC 8807), the login carries a small block
identifying this client, and the server answers with anything it wants you to fix about the
session: a client certificate approaching expiry, an obsolete TLS version, a cipher suite that is
not AEAD.

The list is empty on a healthy session, so treat any entry as something to act on. Each entry
always carries `text`; `type` and `level` are there when the server sent them, hence the `??`
defaults. The commonest event is a certificate expiring in the next 30 days — the alternative to
hearing about it here is finding out on the morning logins stop.

### `$client->domain()->check(['example.com.ua'], fee: ['create' => 1])`

`domain()` returns the domain command handler; `check()` sends `domain:check` (RFC 5731) and
returns the answer. The named `fee:` argument rides an RFC 8748 price query along with it, so one
round trip answers both "is it free" and "what would it cost".

Asking the price is free and changes nothing. See [Balance](balance.md) for the full price table
form, and for how to turn the same extension into a **cap** on what a transform may charge you.

### `$check->isAvailable('example.com.ua')`

Returns `true`, `false`, or `null` when the answer said nothing about that name. The three-way
result is the point: indexing an availability array by a key you misspelled also produces "not
found", and those two answers must not look the same on the line before a registration.

The comparison is case-insensitive, so a name you typed in mixed case still matches.

### `$check->feeFor('example.com.ua', 'create', 1)`

The quoted price for one operation at one period, as an exact decimal **string**, or `null` when
the answer carried no such quote. Never cast it to `float` before doing arithmetic — use `bcmath`
or integer minor units. `fees()['_currency']` is the currency the whole quote is in.

The amounts in this manual are illustrative, not the registry's tariff.

### `$check->unavailableReason('example.com.ua')`

The registry's own words for why a name is not available — `In use`, `Reserved` — or `null` when
it is available or the registry gave no reason.

### `$client->domain()->info('example.com.ua')`

`domain:info` returns the full record for a name you are allowed to see. The program only sends it
in the branch where the name is taken: an `info` for a name that does not exist is refused with
2303, which arrives as an `ObjectDoesNotExistException`.

`sponsor()`, `expiryDate()`, `statuses()` and `nameservers()` are named accessors, so you never
index into an array by a string you had to guess. `expiryDate()` gives back the registry's own
string, exactly as it was sent. Every accessor is listed in [Responses](responses.md).

`nameservers()` covers both EPP delegation models — a reference to a host object, or the name with
its glue inlined — so the list is right whichever one this registry uses.

### `$client->balance()`

The registrar account balance, through the registry's balance extension. `availableCredit()` is
what you can still spend: the balance plus any credit limit, again as an exact decimal string. See
[Balance](balance.md).

### `$client->logout();`

Ends the session. The server answers 1500 and closes the link. A session that is dropped without a
logout still counts against your concurrent-session limit until it times out, so log out on the way
past.

### `catch (EppException $e)`

Every failure this library raises extends `EppException`, so one catch handles everything: a bad
argument, a TLS failure, a refusal from the registry. Catch the subclasses where the right next
step differs — a 2104 means stop the batch and top up, a 2302 means pick another name. The full
taxonomy is in [Errors](errors.md).

### `finally { $client->disconnect(); }`

Closes the socket whatever happened, including on the paths where `logout()` was never reached.

## Where to go next

- [Session](session.md) — every `Config` field, TLS diagnosis, password rotation, logging
- [Commands](commands.md) — transaction ids, `throwOnFailure`, custom frames
- [Domains](domains.md) — register, renew, transfer, restore
- [Errors](errors.md) — what to do about each refusal, and the unknown-outcome rule

Build against a test identity before you build against the live one. Your registry account manager
issues it; the API is identical and nothing is billed.

---

[← Manual index](README.md)
