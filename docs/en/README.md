# EppTools PHP SDK — reference manual

Version **1.0.0**.

```bash
composer require epptools/sdk
```

## Who this is for

You are integrating a billing or provisioning system against a domain registry that speaks EPP, and
the registry has given you a clID and a password.

This library is an **EPP client**, and not any one registry's client. It opens a TLS socket to the
registry — port 700 by convention — writes RFC 5734 frames and reads the replies. Everything in this
manual is the wire protocol of **RFC 5730–5734** with a PHP object in front of it: a session, a
greeting, commands, responses, result codes, objects, extensions and a poll queue.

Where a registry adds something of its own to the protocol, the library reads what that is from the
server's `<greeting>` rather than being told at build time — so nothing here needs configuring per
registry, and the examples work wherever you point them.

The manual assumes you can write PHP 8.0 or newer and that EPP is new to you. Where a method
corresponds to a specific EPP command or RFC, the page says which, so you can read the registry's
own manual beside this one and recognise what you are looking at.

Two habits worth adopting before you write anything that costs money:

- **Dates and money come back as the registry's own strings**, never as `DateTime` or `float`. The
  registry decides which calendar day a renewal lands on, and `0.1 + 0.2` is not `0.3` in binary
  floating point.
- **A command whose answer nobody reads is half an integration.** Every page shows the response
  being read, not only the command being sent.

## The twelve pages, in reading order

| Page | What it covers |
|---|---|
| [Index](README.md) | this page: what the manual contains, in what order, and who it is for |
| [Quickstart](quickstart.md) | install, connect, log in, run one real command, log out — one complete program, walked through line by line |
| [Session](session.md) | `Config` field by field, TLS verification and failed handshakes, connect/hello/login/logout, password rotation, RFC 8807 security events, logging with secrets masked |
| [Commands](commands.md) | the command surface as a whole: what a command returns, client transaction ids, the `throwOnFailure` switch, and building a raw frame for anything not covered |
| [Domains](domains.md) | every domain method (RFC 5731) — check, info, create, update, renew, restore, delete, transfer — with DNSSEC and the registry's own extension |
| [Contacts](contacts.md) | every contact method (RFC 5733), including registry-minted handles and disclosure preferences |
| [Hosts](hosts.md) | every nameserver method (RFC 5732), glue addresses and the forced delete |
| [Poll](poll.md) | the message queue: request, ack, drain, and what the notices carry |
| [Balance](balance.md) | the account balance, and prices and fees (RFC 8748): asking, capping, reading |
| [Responses](responses.md) | every `Response` accessor, grouped by what it answers |
| [Builders](builders.md) | the fluent builders: every step of every builder, the accumulate rule and the send-once rule |
| [Errors](errors.md) | the exception taxonomy, result codes, retry rules and unknown-outcome handling |

Start with [Quickstart](quickstart.md). Read [Session](session.md) and [Errors](errors.md) before
your first billable command.

## Requirements

PHP 8.0 or newer (works through 8.x), with the `dom`, `libxml`, `openssl` and `mbstring`
extensions. The source uses union types and `mixed`, so it does not parse on 7.4. `psr/log` is
pulled in by Composer for optional logging.

## Support

Questions about the library, a frame the registry rejected, or a bug: **https://github.com/epptools/php-sdk/issues**.

When reporting a problem, include the **svTRID** from the response and the **clTRID** your client
sent — together they identify the exact transaction in the registry's logs, which is what makes a
report answerable without a round trip. Send the frames too if you can, but redact `<pw>`,
`<newPW>` and `<authInfo>` first: those are live credentials, and the library masks them in its own
logs for the same reason.

Account, billing and registration questions go to your registry account manager, not here — this
address is for the client library.
