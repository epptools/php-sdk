# Changelog

All notable changes to this library are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2]

### Fixed

None of these was visible from inside this library. Its suite was green, and stayed green, because a
suite only ever asserts its own frames — so a frame that was wrong was wrong in exactly the way the
suite expected. They were found by holding the four EppTools libraries against each other: the same
command built in every language and compared, each frame graded against the registry's own XSD, and
every option key checked against the code that actually reads it.

What a customer saw before this release depended on which of them they hit: a result code that named
no element, because the frame failed schema validation before the server read it; or a `1000` for a
change that never happened, because a key the library accepted was one nothing read.

- **A transfer on a zone whose transfers are free built a frame the registry refuses.** Those zones
  state their policy as "the term does not move", and a registry that validates an explicit period
  says so in its error: the period must be 0. But RFC 5731 bounds `periodType` at 1 to 99, so
  `<domain:period>0</domain:period>` fails schema validation before any of that is read, and comes
  back as a bare `2001` naming no element. The value the operator was told to send could not be sent.

  Passing 0 now leaves the element out, which is what an absent period has always meant: apply the
  zone's own policy. Passing anything outside 1 to 99 to a create, a renew or a transfer is refused
  here, with the bound and the RFC named, instead of on the wire.

- **`contact:update` would clear an e-mail that RFC 5733 does not allow to be cleared.**
  `contact:create` has always refused an empty one. On an update it was emitted, and
  `<contact:email></contact:email>` is schema-invalid — `minTokenType` has a minimum length of one —
  so the change came back as a bare `2001` naming no element, which is the failure the create-side
  refusal exists to prevent. Clearing it is now refused with the reason.

- **A blank contact status reached the wire.** `ContactUpdateBuilder::addStatus('')` passed the
  value through, and `<contact:status s=""/>` is schema-invalid: `statusValueType` is an enumeration
  and the empty string is not a member of it. Every other list step in every builder already dropped
  blanks, including this library's own `HostUpdateBuilder`; this one now does too.

- **`<loginSec:os>` named the operating system in a way that told a support desk less than it
  could.** RFC 8807 section 3.1 asks for the client operating system "with version if available", in
  the shape `arch SP name SP version`, and the field exists so that one login answers "which client,
  on what". This library sent the family alone — `Windows`, with no architecture and no version. The value now carries architecture, name and version as the runtime reports them.

- **`hello()` would store a frame that is not a greeting.** `connect()` already refused one. `hello()`
  is the documented keep-alive, so it is the call a long-lived worker makes on a timer, and a
  `<response>` stored in its place advertises no services at all: the next `login()` fell back to the
  default list, announcing services the server may not offer and losing the extensions it does — the
  registry's own among them, after which a licence create, a forced host delete and `balance()` all
  began to throw and `change()` simply stopped arriving. A non-greeting is now refused, the greeting
  the session already holds is kept, and the frame is logged like every other one.

- **`dsData` and `keyData` could be mixed in one secDNS block.** RFC 5910 forbids it except where the
  key is nested inside the DS record, and `secDNS-1.1.xsd` writes that as a choice, so the frame was
  refused with a bare `2001` naming no element: the domain was left unsigned, or left signed with the
  keys it already had while the caller believed the roll had gone through. Five paths built it — the
  option map, the create builder's `dsRecord()` plus `keyRecord()`, an update's `add` or `rem`, and
  `dsRecordWithKey()` beside a separate `keyRecord()`. All five are now refused where the frame is
  built. The nested form on its own is the one legal combination and still works.

- **Blanks and nulls reached the wire from a direct call.** Every builder list step dropped them and
  every direct call emitted what it was given, so the two disagreed. `<domain:status s="">`,
  `<contact:status s="">` and `<host:status s="">` are outside their enumerations, an empty
  `<domain:hostObj/>` underruns minLength 1 and an empty `<host:addr/>` or `<domain:hostAddr/>`
  minLength 3 — so one stray entry cost the whole command, including the entries that were right. The
  commands now trim and drop blanks for every list, and `DomainCreateBuilder::nameserverWithGlue()`
  does the same for its glue addresses.

- **`maxSigLife` was unbounded.** `secDNS-1.1.xsd` puts a minimum of 1 on it, so a `0`, a negative or
  a non-numeric value was a frame the registry refuses, taking the whole DNSSEC change with it. It is
  now checked the way a period is, with the bound and the RFC named.

- **A fee agreement's currency travelled verbatim, and its amount was never checked.**
  `fee:currencyType` is `[A-Z]{3}`, so `'uah'` was refused with a bare `2001` at the one point in the
  protocol where money is involved — and the agreement is the only protection a registrar has against
  a premium price. The currency is now upper-cased, as `check()`'s already was, and the amount is
  validated in the command, so a direct call and the builder's `maxFee()` step cannot disagree about
  what a price looks like.

- **A fee query with a currency and no operation was sent.** `fee:checkType` requires at least one
  `<fee:command>`, so it built a `<fee:check>` the schema refuses. It is now refused rather than
  quietly stripped: the caller asked what a name costs, and silence where the price should be would
  read as "there is nothing to charge".

- **An empty `objUris` list built a login that cannot succeed.** `epp-1.0.xsd` requires at least one
  `<objURI>`, so the login was refused outright. An empty list now means "not configured", the same as
  a null, and falls back to the greeting's list. An empty `extUris` keeps its meaning — announce
  nothing — because that one is legitimate.

- **The restore frame omitted the empty `<domain:chg/>` RFC 3915 requires.** Section 4.2.5: at least
  one empty `add`, `rem` or `chg` element must be present when the extension travels in an `<update>`.
  Without it the command is a `domain:update` with a name and no delta, which a server may read as a
  no-op and refuse — while the redemption window keeps running.

- **Enum-shaped arguments were not checked.** A transfer `op`, `info(hosts:)`, a contact role, a
  postal block's `type` and a disclosed field's form are each an XSD enumeration, so a value outside
  the set is not a policy refusal but a frame the server never parses. Each is now refused with the
  accepted set named. On a transfer that matters most: the deadline on an incoming request runs while
  the caller reads an error about nothing.

- **`check([])` with no names was sent.** `domain:check`, `contact:check` and `host:check` each
  require at least one child, so an empty list built a childless frame — a bare `2001` for a question
  the caller believed they had asked, which a loop over an empty result set reaches without anyone
  writing it on purpose. The shipped `examples/web.php` reached it from an empty query string and now
  answers that as the bad request it is.

- **`fees()` read `avail="false"` as available.** `fee-1.0.xsd` types the attribute as `xs:boolean`,
  for which `false` is as legal as `0`, and `availability()` in the same class already accepted both
  spellings. A caller gating a create on the price answer went on to a create the registry refuses,
  with the reason sitting unread in `<fee:reason>`.

- **`balance()` answered on responses that carry no balance block.** `<fee:balance>` and
  `<fee:creditLimit>` are children of every RFC 8748 transform result, so an ordinary create carrying
  a fee echo held the same figures under a different parent — and a poll handler branching on
  `balance()` treated a registration as a low-balance warning, with `availableCredit()` empty because
  a fee echo does not carry one. The figures are now read only from a balance `infData`.

- **A duplicate guard that could not fire has gone.** A second empty-name test sat directly below the
  loop that already made it, and was its helper's only call site. A check that cannot fire is worse
  than none, because a reader counts it as protection and stops looking for the one doing the work.

- **A blank name in a `check` was quietly dropped, so the answer was shorter than the question.** An
  empty `<domain:name>` is a frame the registry refuses, and three of these libraries filtered the
  blank out instead: fifty names in, forty-nine answers out. A caller then looks up the blank in the
  result and finds nothing, which reads as "not available" rather than as "never asked" — a wrong
  answer further from its cause than the refusal would have been. A blank entry is now refused,
  naming its position and the element. Whitespace around a real name is still trimmed.

- **An update that asked for nothing was sent, and could come back `1000`.** RFC 5731, 5732 and 5733
  each say in section 3.2.5 that at least one of `add`, `rem` or `chg` MUST be provided unless the
  command carries an extension. None of the three schemas can express it, because all three elements
  are optional there — so an update describing no change has always been valid XML, answered with a
  `2003` naming nothing, or with a `1000` for a change that was never described. Nobody writes that
  update on purpose: they assemble a delta from variables that all turn out empty, or a list of
  statuses that filters down to nothing. Three things are fixed together: a block that filters down
  to nothing is no longer opened at all, a `chg` whose only key produces no child is no longer opened,
  and a command with no delta and no extension is refused with the rule quoted. An update carrying
  only an extension — a DNSSEC change, an RGP restore — is still sent, which is what the RFC allows.

- **A registrant could be cleared, which RFC 5731 has no way to express.** `authInfo` has a nullable
  form and the registrant has none, so a domain can be moved to another holder but not left without
  one. A blank one reached the wire as an empty `<domain:registrant/>`, which `clIDType` refuses for
  its minimum length of three. Refused here now, with the reason.

- **One disclosed postal form given as a bare string became one element per letter.** `{addr: 'int'}`
  is a reasonable thing to write, and two of these libraries iterated the string's characters and
  emitted `type="i"`, `type="n"`, `type="t"` — three elements the schema refuses, for a privacy
  preference nobody got wrong. A bare string is now one form in all four, and a list of both still
  gives both.

- **The two `<extension>` children of a `domain:update` came out in different orders in different
  languages.** A fee agreement and a DNSSEC delta on the same update produced `fee` first in one
  library and `secDNS` first in the others. Both are valid — `epp-1.0.xsd` does not order the
  extension block — but a customer comparing two of these libraries against one another should not
  find a difference, and no test reached the case. The order is now the same in all four, and the
  shared frame fixture carries a scenario that holds it there.

### Documentation

- The README said a postal field you omit is preserved. It is deleted — the block is replaced — which
  is the claim release 1.0.2 was cut to kill; the rewrite reached `docs/*/contacts.md` and never
  reached the README or `ContactUpdateBuilder`'s own docblock.
- Contact handles below `eppcom:clIDType`'s minimum of three characters have gone from the README:
  every frame built from one is refused.
- One registry's policy was stated as protocol fact in all three languages: which languages a greeting
  offers, whether a CA bundle is needed, whether a source-address allow-list applies, how many glue
  addresses a host may carry, and whether RFC 5732 defines a host rename (it does — implementing it is
  each registry's own decision). Each now says whose decision it is.
- Claims the code contradicts: a renew `2004` for a period the library refuses locally; `threshold()`
  and `change()` missing from pages that promise every accessor; `CHANGEPOLL` missing from the
  namespace table; a TLS error string the library never produces; a builder step described as passing
  blanks through when it drops them.
- Examples that do not run: a `contact:update` shown with a partial postal block the library refuses,
  and an address read-back that dropped the state and postcode it claimed to keep.
- The `add`/`rem` and create tables offered `dsData` and `keyData` together, presenting the refused
  shape as normal; `balance.md` presented a currency with no operations as a usable query; the restore
  page had the RFC 3915 rule backwards, right about content and wrong about presence; `builders.md`
  recommended `contact('reseller', …)` and claimed a role may be any name the registry recognises.

## [1.1.1]

### Fixed

- **The manuals now document `change()`, and every version they state matches the package.** 1.1.0
  shipped the feature with per-language manuals that did not mention it, and the install line
  offering a GitHub tag still named an older release — so a reader following the documented command
  installed a library older than the page describing it. All three languages now carry the section,
  and a test fails the build when any documented version drifts from the package's own.
## [1.1.0]

### Added

- **`change()` — what the registry did to your object, as data instead of a sentence (RFC 8590).**
  Some poll notices describe something that happened to one of your objects without you asking: it
  stopped existing at the registry, or it left on a transfer. Those are the ones you have to act on
  automatically — stop billing it, tell your customer, drop it from your own store — and the `<msg>`
  they carry is written in your account's notification language, so nothing in it is safe to parse.

  `change()` returns the operation, when it happened, who did it, the server transaction id and the
  registry's own finer name for the event, or `null` where the notice carries no change block. The
  object itself is in the response as usual, so the ordinary accessors read it.

  **`state` matters**: it says whether that object describes itself **before** the change or
  **after** it. A domain that no longer exists can only be described as it last was, so those
  notices read `before` — storing such a block as the object's *current* state is how a deleted
  domain comes back to life in your own records.

  To receive it, announce `urn:ietf:params:xml:ns:changePoll-1.0` at login. This library mirrors the
  server's greeting into `<svcs>`, so a server that offers it is announced for you unless you pin
  your own service list.
## [1.0.2]

### Fixed

- **A postal change that carried only part of the block could delete the rest of the address.** A
  `<contact:postalInfo>` inside `<contact:chg>` is not merged field by field by every registry: one
  that *replaces* the block stores exactly what you sent and drops everything else — while answering
  **1000**. Clearing an organisation on its own therefore left a contact with no name, no street, no
  city, no postal code and no country, and nothing in the response said so. `name`, `city` and `cc`
  are now required in every postal change, through `contact()->update()` and the update builder
  alike.

### Changed

- A postal change missing `name`, `city` or `cc` now raises `ValidationException` instead of being
  sent. This rejects calls that previously appeared to succeed — those were the calls losing data.
  Read the current block with `contact()->info()->postalInfo()` and send it back with your change
  applied.
- The documentation for partial postal updates was wrong in the same way and has been rewritten. A
  block is replaced, not merged: a field you leave out is deleted, not preserved.

## [1.0.1]

### Fixed

- **Clearing one optional part of an address built a frame the server rejects.** RFC 5733 makes
  `<contact:addr>` a sequence with a required `city` and `cc`, so a change to any part of it sends
  the whole block. What the library did was substitute an EMPTY STRING for whatever the caller had
  not supplied — so `{"sp": ""}`, the documented way to remove a state, went out with `<city/>` and
  `<cc/>` beside it. Both are schema-invalid (`postalLineType` has minLength 1, `ccType` is exactly
  two characters), and an invalid frame comes back as a bare `2001` naming no element. A caller
  following the manual got the least useful error in EPP.

  A partial address change now requires `city` and `cc` and says so before anything is sent. Read
  them back from `info()` and pass them through unchanged alongside what you are changing.

- **An empty value is now refused for the fields the schema forbids it on.** Which parts of a
  contact can be CLEARED is fixed by `contact-1.0.xsd` and not by convention: `org`, `street`, `sp`
  and `pc` have types with no minimum length, so sending them empty is what removes them; `name`,
  `city` and `cc` do not, so an empty one cannot be sent at all. Passing one is answered here, with
  the reason, instead of costing a round trip.

Neither changes a call that worked. Both turn a call the server was already rejecting into one that
fails immediately and says why.

## [1.0.0]

First public release.

### The library

- **EPP over TLS, no framework and no dependencies** beyond PSR-3 for optional logging. Domains,
  contacts and hosts (RFC 5730–5733), DNSSEC (RFC 5910), redemption and restore (RFC 3915), prices
  and fee agreements (RFC 8748) and login security (RFC 8807).
- **A registry's own extensions are discovered from its `<greeting>`**, not compiled in. This library
  ships no registry's URIs, so it works against a registry it has never seen — and keeps working when
  one changes its namespaces. `Client::registryExtUri()` / `registryBalanceUri()` report what was
  found; `Config` can override both for a registry whose naming discovery cannot guess.
- **Responses are read by local element name**, never by namespace prefix, so extension data stays
  readable whatever namespace it arrived under and whatever prefix the server chose.
- **Commands that need an extension the server does not offer fail loudly.** `domain:create` with a
  licence, `host:delete` with `force` and `balance()` throw `ConfigException` naming what was wanted
  and listing what the server advertised — because an extension sent under a namespace the server
  does not recognise is ignored rather than rejected, so the alternative is a `1000 OK` with the
  value silently unset.
- **Misspelt option keys are refused, not dropped.** A key this library does not understand raises
  `ValidationException` with the nearest accepted spelling, instead of building a frame that omits
  what you asked for and comes back successful.
- **Passwords never reach a log.** Frame logging redacts `<pw>` and `<newPW>` in any namespace, and
  a password too long for the RFC 5730 `<pw>` element is either carried by RFC 8807 or refused
  before a socket is opened.
- **Builders** for the commands with the most options — `domain:update`, `contact:create`,
  `contact:update` — so a long call site reads as a sequence of decisions.

### The documentation

- A full reference manual in English, Ukrainian and Russian: `docs/en/`, `docs/uk/`, `docs/ru/`,
  twelve pages each — quickstart, session, domains, contacts, hosts, DNSSEC, transfers, poll, fees,
  balance, responses, errors, plus a commands reference and a builders guide.
- Every example runs against `epp.registry.example` with the login `EXAMPLE`. The examples name no
  real registry, and every hostname is under a TLD RFC 2606 reserves so a copied example cannot
  reach somebody's server.
