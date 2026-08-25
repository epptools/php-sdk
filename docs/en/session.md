# Session

An EPP session is a TLS connection with a login on it. This page covers everything from the socket
to the logout: configuring the client, verifying the server's certificate, opening and closing the
session, rotating the password, reading the server's security warnings, and logging frames without
leaking credentials.

The shape of it never changes:

```
connect()  →  <greeting>  →  login()  →  commands …  →  logout()  →  disconnect()
```

`connect()` opens the socket and reads the greeting. `login()` authenticates. Commands run.
`logout()` ends the session at the registry, and `disconnect()` closes the socket.

## Config

`EppTools\Config` holds the connection settings. Build it with the constructor, or with
`Config::fromArray()`, which takes the same names as keys and fills in every default:

```php
use EppTools\Config;

$config = Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => getenv('EPP_PASSWORD'),
    'caFile'   => '/etc/epp/registry-ca.pem',
]);
```

A key `fromArray()` does not recognise is ignored, so check the spelling against the table below.
The properties are public, so anything you did not pass can be read back off the object.

### Every field

| Field | Default | What it is, and what happens if it is wrong |
|---|---|---|
| `host` | `''` | The registry hostname. Empty raises `ConfigException` from `connect()`. It is also the TLS `peer_name` used for SNI and hostname verification, so an IP address here fails name verification against a certificate issued to a hostname. |
| `clid` | `''` | Your registrar identifier, 3–16 characters of Latin letters, digits and hyphen. Empty raises `ConfigException` from `login()` before anything is sent. A well-formed but wrong value is refused by the server with 2200. |
| `password` | `''` | Your EPP password. Empty raises `ConfigException` from `login()`. Shorter than 6 characters, or longer than 128, raises `ConfigException` before the socket opens. Longer than 16 is possible only where the server offers RFC 8807 — see [Long passwords](#long-passwords) below. |
| `port` | `700` | The EPP port. Change it only if you were given a different endpoint. A wrong port produces a `ConnectionException` from `connect()`. |
| `lang` | `'en'` | The language of the server's result messages for this session. The registry advertises `en`, `uk`, `ua` and `ru` in its greeting; a value it does not advertise fails the login with 2102. This is the language of `message()`, not of the poll notices, which carry their own. |
| `connectTimeout` | `10.0` | Seconds to wait for the TCP connect and TLS handshake. Too low on a slow link produces a `ConnectionException` that reads like a refused connection. |
| `readTimeout` | `30.0` | Seconds to wait for a reply frame. Fractions are honoured (`2.5` means two and a half seconds). A value of zero or less is floored at one second, so it never means "wait forever". A read that times out raises `ConnectionException` and **closes the connection** — a half-read frame leaves the byte stream at an unknown offset, and the next command would read the middle of this one's reply. |
| `verifyPeer` | `true` | Verify the server certificate chain. Leave it on. See [TLS](#tls-and-certificate-verification). |
| `verifyPeerName` | `true` | Verify that the certificate matches `host`. Turning it off is a narrow loosening (right certificate, wrong hostname) that is occasionally reasonable in development. |
| `caFile` | `null` | Path to the PEM bundle of the CA that signed the **server** certificate. Required against `epp.registry.example:700`, which uses the registry's own private CA. Unset or wrong, the handshake fails with a certificate-verify error. |
| `clientCert` | `null` | Path to **your** client certificate, PEM. Only needed if your endpoint requires mutual TLS; the public port 700 does not. |
| `clientKey` | `null` | Path to your client private key, PEM. May be omitted when the key is bundled into `clientCert`. |
| `clientKeyPassphrase` | `null` | Passphrase for an encrypted client key. Wrong, and the handshake fails while loading the key rather than while verifying the peer. |
| `objUris` | `null` | The object services to announce in `<login>`. `null` means "exactly what the greeting offered", which is why a default session is never refused for an unsupported service. Override it and any URI this server does not serve fails the login with 2307. The base `epp-1.0` URI is never sent as an object service, whether or not the greeting lists it. |
| `extUris` | `null` | The extension services to announce, same rule. Announcing fewer extensions than you use means the registry will not return that extension's data. |
| `clTRIDPrefix` | `'PHP-SDK'` | The first segment of every auto-generated client transaction id. Set it to something that identifies your system in the registry's logs. See [Commands](commands.md#client-transaction-ids). |
| `registryExtUri` | `null` | The namespace of **this registry's own** object extension. `null` — the normal case — reads it from the greeting. Set it only for a registry whose extension is not named `…/registry-<version>`, which is what discovery matches on. A wrong value is not rejected: an extension in a namespace the server does not know is IGNORED, so the data goes missing silently. See [Commands](commands.md#your-registrys-own-extensions). |
| `registryBalanceUri` | `null` | The same, for the account-balance extension. |
| `loginSecurity` | `true` | Take part in RFC 8807 where the server offers it. Setting it false stops the server's security events reaching you. See [Login security](#login-security-rfc-8807). |

### `streamContextOptions(): array`

Returns the PHP stream-context options the socket is opened with, as
`['ssl' => [...]]`. Useful when a handshake fails and you want to see exactly what was asked for:

```php
print_r($config->streamContextOptions());
```

The block always sets `verify_peer`, `verify_peer_name`, `SNI_enabled`, `peer_name` (from `host`)
and a `crypto_method` of **TLS 1.2 or TLS 1.3 only** — anything older is refused before a byte of
EPP is written. `cafile`, `local_cert`, `local_pk` and `passphrase` appear only when the matching
field is set.

## TLS and certificate verification

| Scenario | Config |
|---|---|
| `epp.registry.example:700` (private-CA certificate) | set `caFile` to the registry CA `.pem` — **required** |
| Public, browser-trusted certificate | the defaults (`verifyPeer: true`, `verifyPeerName: true`) |
| Hostname mismatch in development | `verifyPeerName: false` |
| Mutual-TLS endpoint | `clientCert` + `clientKey` (+ `clientKeyPassphrase` if the key is encrypted) |

The endpoint on `epp.registry.example:700` presents a certificate issued by the registry's **own private
CA**. That CA is in no system trust store, so `caFile` must point at its `.pem` bundle. Ask the
registry for it.

Otherwise the endpoint is strict RFC EPP and needs **no client certificate**: authentication is
clID plus password over TLS, with an IP allowlist. Your own certificate and key are needed only if
your endpoint requires mutual TLS.

### When the handshake fails

The commonest first-run failure is certificate verification, and it looks like this:

```
ConnectionException: Cannot connect to epp.registry.example:700 — SSL operation failed …
certificate verify failed
```

The exception carries the reason PHP reported, not a generic "unknown error", so read the tail of
the message first. Then check the bundle from the command line:

```bash
openssl s_client -connect epp.registry.example:700 -CAfile /path/to/registry-ca.pem </dev/null
# "Verify return code: 0 (ok)" means the bundle is right; anything else means it is not.
```

A short diagnosis list, in the order worth trying:

| Message | Usual cause |
|---|---|
| `certificate verify failed` | `caFile` unset, or pointing at the wrong bundle |
| `did not match expected CN` / name mismatch | `host` differs from the name on the certificate — an IP address, or an alias |
| `Connection refused` / `Connection timed out` | wrong port, or your source address is not allow-listed at the firewall |
| a failure while loading the local certificate | `clientCert` / `clientKey` path or `clientKeyPassphrase` wrong |

**Do not reach for `verifyPeer: false`.** It makes the message go away and leaves you sending your
clID, your password and every transfer secret to whatever answers on that address, with no way to
tell. If the handshake will not verify, the bundle is wrong — ask the registry for the current one.
`verifyPeerName: false` is the narrower loosening and is occasionally reasonable in development;
`verifyPeer: false` is not reasonable anywhere.

## Opening the session

### `new Client(Config $config, ?Transport $connection = null, ?LoggerInterface $logger = null)`

Constructing the client opens nothing. The second argument replaces the transport (see
[Transport](#transport)); the third attaches a PSR-3 logger.

### `connect(): Response`

Opens the TLS socket and reads the server's unsolicited `<greeting>` (RFC 5730 §2.4), returning it
as a [`Response`](responses.md).

```php
$greeting = $client->connect();
$greeting->serviceObjUris();   // the object namespaces this server manages
$greeting->serviceExtUris();   // the extensions it supports
$greeting->value('svID');      // its name
$greeting->value('svDate');    // its clock, as its own string
```

Two things it refuses to do. An empty `host` raises `ConfigException`. A first frame that is not a
greeting raises `ConnectionException` — accepting whatever arrived instead would let a stray
response, or a middlebox banner, stand in for the service list, and the login would then advertise
services this server does not offer while losing the extensions it does.

Calling `connect()` on an already-open connection does not reopen the socket; it reads the next
frame as a greeting. Use `hello()` for that instead.

### `greeting(): ?Response`

The last greeting read, or `null` before `connect()`. Useful for deciding, without a round trip,
whether an extension is available:

```php
use EppTools\Namespaces;

$fees = in_array(Namespaces::FEE, $client->greeting()?->serviceExtUris() ?? [], true);
```

### `hello(): Response`

Sends `<hello>` and reads the fresh `<greeting>` the server answers with. Two uses: re-reading the
service menu, and keeping an idle session alive — the registry closes a session that has been idle
too long, and a `hello` is the cheapest frame that resets that clock. The greeting it returns
replaces the stored one.

### `login(?string $newPassword = null): Response`

Authenticates. It builds `<login>` with your clID and password, `<version>1.0</version>`, the
configured `<lang>`, and the service list — by default exactly the services the greeting offered.

```php
$response = $client->login();
$response->code();   // 1000
```

If you call `login()` without having called `connect()`, it connects first.

Checks that happen before the frame is built, each raising `ConfigException`:

- an empty `clid` or `password`;
- a password outside 6–128 characters;
- a password longer than 16 characters when this server does not advertise RFC 8807 — the base
  `<pw>` element cannot carry it, and the server would answer a bare 2001 naming no field.

Only result code 2200 raises `AuthenticationException`. A login refused for any other reason
arrives as its own class with its own remedy: 2502 (session limit), 2501 (server closing), 2307 (a
service in `<svcs>` is not offered), 2002 (this connection is already logged in), 2100 (protocol
version). Calling them all an authentication failure sends you to rotate a password that was never
the problem. See [Errors](errors.md).

### `Client::connectAndLogin(Config $config): self`

Static shortcut: constructs the client, connects and logs in, returning the ready client.

```php
$client = Client::connectAndLogin($config);
```

It uses the default transport and no logger. Where you need either, use the constructor.

### `isConnected(): bool` and `isLoggedIn(): bool`

`isConnected()` reports whether the socket is open. `isLoggedIn()` reports whether a login has
succeeded and neither `logout()` nor `disconnect()` has run since.

Neither one polls the server, so a session the registry closed on its own — an idle timeout, a
restart — can still read as logged in until the next command fails. Treat them as bookkeeping, not
as a health check; a `hello()` is the health check.

## Closing the session

### `logout(): Response`

Sends `<logout>`. The server answers **1500** and closes the link. 1500 is a success code, so this
does not raise.

### `disconnect(): void`

Closes the socket. Safe to call when nothing is open, which is why it belongs in a `finally`:

```php
try {
    $client->connect();
    $client->login();
    // …
    $client->logout();
} finally {
    $client->disconnect();
}
```

### `__destruct()`

The client also closes its connection when PHP destroys it — at the end of the request, or when the
last reference to it goes away. You never call this yourself; it is the last-resort close.

```php
public function __destruct()
```

It swallows any error it meets, because throwing from a destructor would replace the real failure
with a second one raised at a point that tells you nothing about the first.

Do not rely on it for a long-running process. It runs when PHP decides to, which for a worker
holding the client in a static or a container may be at shutdown and not before, and an unclosed
session counts against your concurrent-session limit until the registry times it out. `logout()` on
the way past and `disconnect()` in a `finally` are the two you write.

Note that it closes the **socket** and does not send a `<logout>`: at destruction time the frame may
have nowhere to go. The registry sees an abandoned connection rather than a clean end of session.

## Password rotation

Pass the new password to `login()` and it goes out as `<newPW>` alongside the old one, in the same
frame (RFC 5730 §2.9.1.1). The change takes effect only if the login succeeds.

```php
$response = $client->login('N3w-Secret-Pw');
// From here on, this session is authenticated. Every LATER session must use the new password.
```

The order to do this in matters, because there is no way to ask the registry which password is
current:

1. Write the new password to your secret store **first**, marked as pending.
2. Call `login($newPassword)`.
3. On success, promote it to current. On failure, the old password is still the live one — the
   registry applies the change only on a successful login.

Both passwords are bounds-checked before the socket opens. 6–16 characters is the base `<pw>`
schema limit; 6–128 is available where the server offers RFC 8807, and rotating **across** that
boundary is handled for you — changing a short password to a long one relocates only the new one.

### Long passwords

A password longer than 16 characters cannot travel in the base `<pw>` element. Where the server
advertises RFC 8807, the library sends the reserved sentinel `[LOGIN-SECURITY]` in `<pw>` and the
real password in `<loginSec:pw>`; the sentinel is decided **per element**, so an element still
carrying its own value never claims its value is somewhere else.

Two consequences worth knowing before you set one:

- A long password authenticates only on an endpoint that offers the extension. Any of your software
  connecting to an endpoint that does not will start failing authentication.
- Because the sentinel is reserved, `[LOGIN-SECURITY]` can never itself be a password.

`Config::$loginSecurity` does not control this. A relocated password sends the block regardless,
having no other way to travel.

## Login security (RFC 8807)

Where the server offers the Login Security extension, the login carries a small block identifying
this client — application name and version, the PHP version, the OS family — and the server answers
with anything it wants you to fix about the session.

```php
foreach ($client->login()->securityEvents() as $event) {
    // type:  certificate | cipher | tlsProtocol | password | newPW | stat | custom
    // level: 'warning' or 'error'
    // text:  a sentence to show an operator
    alert(
        $event['level'] ?? 'warning',
        $event['type'] ?? 'custom',
        $event['text'],
        $event['exDate'] ?? null
    );
}
```

`securityEvents()` returns a list of maps. Every entry carries `text`; `type`, `name`, `level`,
`exDate`, `value`, `duration` and `lang` appear when the event carries them, so read them with
`??`. The list is empty on a healthy session — treat any entry as something to act on.

| `type` | Raised when |
|---|---|
| `certificate` | your client certificate expires soon; `exDate` carries the exact moment |
| `tlsProtocol` | the session negotiated an obsolete TLS version; `name` carries it |
| `cipher` | the session negotiated a cipher suite that is not AEAD; `name` carries it |
| `password` / `newPW` | something about the credential itself, such as a length that limits where it can be used |
| `stat` | session statistics the server wants you to see |
| `custom` | server-specific; `name` identifies it |

A server sends these only to a client that **took part** in the extension, because announcing a URI
is not evidence of supporting it — many clients build `<svcExtension>` by echoing the greeting back.
That is why the block goes out even when nothing needs to travel in it.

Set `loginSecurity: false` in the config to stay off the extension. You then get no security
events, and the commonest one is a client certificate approaching its expiry date: the alternative
to hearing about it here is finding out on the morning it stops working.

The client version sent in the block is whatever `Client::VERSION` holds in the copy you installed.
Quote it in a support ticket.

## Logging (PSR-3)

Pass any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger — Monolog, Laravel's, your own.

```php
$log = new Monolog\Logger('epp');
$log->pushHandler(new Monolog\Handler\StreamHandler('php://stderr', Monolog\Level::Debug));

$client = new Client($config, null, $log);
// or later, and null to detach:
$client->setLogger($log);
```

What is written:

| Level | Event |
|---|---|
| `debug` | every request frame and every response frame, under the key `frame` |
| `info` | each successful result — code, `svTRID`, `clTRID` |
| `warning` | each failed result, same fields |

**Secrets are masked before a frame is logged.** Every `<pw>` and `<newPW>` element is replaced with
`***`, in any namespace prefix and whatever attributes the opening tag carries. That covers the
login password, the `<newPW>` of a rotation, the `<loginSec:pw>` of a long password, and the `<pw>`
inside every `<authInfo>` block — which is the transfer secret, the one credential that lets any
registrar take a domain away from you.

Two things the masking does not reach, because they are not the logger's output:

- `Response::raw()` returns the frame as it arrived, unmasked. If you store it, mask it yourself.
- Anything you log yourself. `authInfo()` returns a live credential; never log it, never put it in
  a support ticket, and roll it after you have passed it to a customer.

## Transport

`Client` speaks to the world through the `EppTools\Transport` interface — `open()`, `isOpen()`,
`writeFrame(string $xml)`, `readFrame(): string`, `close()`. The default implementation,
`EppTools\Connection`, is the TLS socket plus RFC 5734 framing: each message is prefixed with a
4-byte big-endian length that **includes** the four header bytes.

Supplying your own is how you drive the client against a recorded exchange in tests:

```php
$client = new Client($config, new MyRecordedTransport());
```

Two rules the default transport enforces, and any replacement should:

- A frame length below 4 bytes or above 1 MiB is refused, and the connection is **closed**. The
  length bytes have already been consumed at that point, so the stream sits at an unknown offset.
- A partial read is terminal for the same reason. Continuing would make the next command read this
  command's reply — an off-by-one across billable transforms, where a renew returns 1000 carrying
  another name's expiry date.

That is also why a `ConnectionException` mid-command leaves the outcome genuinely unknown. Do not
retry blindly; see [Errors](errors.md#when-a-transform-fails-and-you-do-not-know-whether-it-happened).

## Session hygiene

- **One command at a time.** Send a command, read its reply, then send the next. For throughput,
  open more sessions rather than overlapping commands inside one.
- **Sessions end.** By your `logout()`, by an idle timeout, by a maintenance restart. Nothing you
  have already been told about is lost — a 1000 you have read is final. Reconnect and continue.
- **Watch the concurrent-session limit.** A 2502 arrives as a `SessionException`; the remedy is to
  close an idle session, not to retry immediately.

---

[← Manual index](README.md)
