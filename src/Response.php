<?php

declare(strict_types=1);

namespace EppTools;

use EppTools\Exception\ConnectionException;

/**
 * A parsed EPP response (or greeting). Wraps the raw XML with convenience accessors:
 * the result code/message, transaction ids, the availability map for *:check, and
 * generic value/values getters plus the underlying DOM/XPath for anything bespoke.
 */
final class Response
{
    private string $raw;
    private \DOMDocument $dom;
    private \DOMXPath $xpath;

    private function __construct(string $raw, \DOMDocument $dom, \DOMXPath $xpath)
    {
        $this->raw = $raw;
        $this->dom = $dom;
        $this->xpath = $xpath;
    }

    public static function fromXml(string $xml): self
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($ok === false) {
            throw new ConnectionException('Server returned malformed XML');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', Namespaces::EPP);
        $xpath->registerNamespace('domain', Namespaces::DOMAIN);
        $xpath->registerNamespace('contact', Namespaces::CONTACT);
        $xpath->registerNamespace('host', Namespaces::HOST);
        $xpath->registerNamespace('secDNS', Namespaces::SECDNS);
        $xpath->registerNamespace('rgp', Namespaces::RGP);
        // No prefix is registered for a registry's own extensions, and none is needed: every read of
        // extension data in this class goes through local-name(), which matches an element by its
        // name whatever namespace it arrived under. So a response keeps parsing when a registry moves
        // to a new extension URI, and this library can read a registry it has never seen.
        $xpath->registerNamespace('fee', Namespaces::FEE);

        return new self($xml, $dom, $xpath);
    }

    /** The EPP result code (e.g. 1000, 1001, 2200), or 0 for a greeting/codeless frame. */
    public function code(): int
    {
        $node = $this->xpath->query('//e:result/@code')->item(0);

        return $node !== null ? (int) $node->nodeValue : 0;
    }

    public function message(): ?string
    {
        $node = $this->xpath->query('//e:result/e:msg')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** The language of the result <msg> ("en", "uk", "ua" or "ru"), or null. */
    public function messageLang(): ?string
    {
        $node = $this->xpath->query('//e:result/e:msg/@lang')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /** A 1xxx code means success (1000 done, 1001 action pending). */
    public function isSuccess(): bool
    {
        $code = $this->code();

        return $code >= 1000 && $code < 2000;
    }

    public function isPending(): bool
    {
        return $this->code() === 1001;
    }

    public function isGreeting(): bool
    {
        return $this->xpath->query('//e:greeting')->length > 0;
    }

    public function clTRID(): ?string
    {
        $node = $this->xpath->query('//e:trID/e:clTRID')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    public function svTRID(): ?string
    {
        $node = $this->xpath->query('//e:trID/e:svTRID')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /**
     * Availability map for a *:check response: name/id => is-available.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        $out = [];
        // Only the NAME/ID child of a *:cd block. A bare `//*[@avail]` would also match `fee:cd`,
        // which carries avail="0" on the block ITSELF (with fee:objID + fee:reason inside) whenever
        // a zone is not served or an unsupported currency is asked for — its concatenated
        // textContent would then become a junk key like "example.uaZone is not served" sitting
        // beside the real entry, and a check carrying a fee rider hits that on an ordinary call.
        /** @var \DOMElement $element */
        foreach ($this->xpath->query('//*[local-name()="cd"]/*[@avail]') as $element) {
            $out[trim($element->textContent)] = in_array($element->getAttribute('avail'), ['1', 'true'], true);
        }

        return $out;
    }

    /** Poll only: the queued message id to pass to poll()->ack(), or null. */
    public function messageId(): ?string
    {
        $node = $this->xpath->query('//e:msgQ/@id')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /** Poll only: how many messages remain in the queue. */
    public function messageCount(): int
    {
        $node = $this->xpath->query('//e:msgQ/@count')->item(0);

        return $node !== null ? (int) $node->nodeValue : 0;
    }

    /**
     * Poll only: the QUEUED NOTICE text, from <msgQ><msg>.
     *
     * NOT the same as message(), which returns <result><msg> — the command-result banner
     * ("Command completed successfully; ack to dequeue"). Reading the notice with message()
     * hands the caller that constant string while the real content is discarded, and an ack
     * dequeues it at the registry irreversibly.
     */
    public function queueMessage(): ?string
    {
        $node = $this->xpath->query('//e:msgQ/e:msg')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** Poll only: the language of the queued notice ("uk", "ru", "en"), or null. */
    public function queueMessageLang(): ?string
    {
        $node = $this->xpath->query('//e:msgQ/e:msg/@lang')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /** Poll only: when the notice was queued (<msgQ><qDate>), or null. */
    public function queueDate(): ?string
    {
        $node = $this->xpath->query('//e:msgQ/e:qDate')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /**
     * Poll only: the outcome of an operation the registry processed OFFLINE (`<domain:panData>`,
     * `<contact:panData>` — RFC 5731 §3.3 / RFC 5733 §3.3).
     *
     * This is how a deferred command finally reports back. You send `domain:create`, get **1001**
     * ("queued; the result will arrive via poll") and a `svTRID`; some time later a poll message
     * carries the panData saying whether it worked.
     *
     * The three fields that matter, and why:
     *
     *  - `success` — the `paResult` attribute. **The only thing that says whether it worked.** The
     *    surrounding `<result code="1301">` means "here is a message", not "your operation
     *    succeeded", and reading that instead is the classic mistake: every poll answer looks like
     *    a success.
     *  - `clTRID` / `svTRID` — from `<paTRID>`: the transaction ids of the ORIGINAL command. Match
     *    the `svTRID` against the one you were given with the 1001 to know WHICH of your pending
     *    operations this is about. Do not assume it is the most recent one; poll is a queue.
     *  - `date` — `<paDate>`, when the action completed. Not when you polled.
     *
     * Returns null when the message carries no panData (an ordinary notice, or an empty queue).
     *
     * @return array{object: string, success: bool, clTRID: ?string, svTRID: ?string, date: ?string}|null
     */
    public function pendingActionData(): ?array
    {
        // Matched by LOCAL NAME across every object namespace: domain:panData, contact:panData and
        // any dialect's own. Binding to one namespace would silently return null on the others.
        $el = $this->xpath->query('//*[local-name()="panData"]')->item(0);
        if (!$el instanceof \DOMElement) {
            return null;
        }
        $nameEl = $this->firstByLocalName($el, 'name') ?? $this->firstByLocalName($el, 'id');
        // paResult is xs:boolean, so "1"/"true" both mean success — and anything else means it did
        // NOT work. Absent is treated as failure: a missing verdict is not a yes.
        $flag = $nameEl?->getAttribute('paResult') ?? '';

        $trid = $this->firstByLocalName($el, 'paTRID');
        $dateEl = $this->firstByLocalName($el, 'paDate');

        return [
            'object'  => $nameEl !== null ? trim($nameEl->textContent) : '',
            'success' => $flag === '1' || strtolower($flag) === 'true',
            'clTRID'  => $trid !== null ? $this->childTextOrNull($trid, 'clTRID') : null,
            'svTRID'  => $trid !== null ? $this->childTextOrNull($trid, 'svTRID') : null,
            'date'    => $dateEl !== null ? trim($dateEl->textContent) : null,
        ];
    }

    /** First descendant with this local name, in any namespace. */
    private function firstByLocalName(\DOMElement $el, string $local): ?\DOMElement
    {
        $found = $this->xpath->query('.//*[local-name()="' . $local . '"]', $el)->item(0);

        return $found instanceof \DOMElement ? $found : null;
    }

    private function childTextOrNull(\DOMElement $el, string $local): ?string
    {
        $node = $this->firstByLocalName($el, $local);
        $text = $node !== null ? trim($node->textContent) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * Object status values from the `s` attribute (e.g. ['ok'] or ['clientHold', ...]).
     *
     * @return string[]
     */
    public function statuses(): array
    {
        $out = [];
        foreach ($this->xpath->query("//*[local-name()='status']/@s") as $attr) {
            $out[] = $attr->nodeValue;
        }

        return $out;
    }

    /**
     * Account figures from a balance:info response (creditLimit / balance / availableCredit,
     * strings in your account currency), or null when this is not a balance response.
     *
     * @return array{creditLimit:string,balance:string,availableCredit:string}|null
     */
    public function balance(): ?array
    {
        $limit = $this->value('creditLimit');
        $avail = $this->value('availableCredit');
        if ($limit === null && $avail === null) {
            return null;
        }

        return [
            'creditLimit'     => (string) $limit,
            'balance'         => (string) $this->value('balance'),
            'availableCredit' => (string) $avail,
        ];
    }

    /**
     * Renewal/restore price hints from a domain:info response (the registry priceData
     * extension), keyed by operation, e.g. ['renewal' => ['value' => '180.00', 'currency' => 'UAH']].
     * Empty when the response carries no price data.
     *
     * @return array<string, array{value:string, currency:string}>
     */
    public function prices(): array
    {
        $out = [];
        /** @var \DOMElement $node */
        foreach ($this->xpath->query("//*[local-name()='price']") as $node) {
            $op = $node->getAttribute('operation');
            if ($op === '') {
                continue;
            }
            $out[$op] = ['value' => trim($node->textContent), 'currency' => $node->getAttribute('currency')];
        }

        return $out;
    }

    /**
     * The price channel this domain is billed on, as an opaque id — the key that matches these
     * prices to a row of the registry's published price catalogue.
     *
     * Null when the response carries no price data. A domain registered long ago may sit on a
     * different channel from the one a new registration in the same zone would use, which is why
     * this is per-domain rather than per-zone.
     */
    public function priceChannel(): ?string
    {
        $node = $this->xpath->query("//*[local-name()='priceData']")->item(0);
        if (!$node instanceof \DOMElement || !$node->hasAttribute('channel')) {
            return null;
        }
        $channel = trim($node->getAttribute('channel'));

        return $channel !== '' ? $channel : null;
    }

    /**
     * The registrar of record at the registry, when it is not you.
     *
     * sponsor() names the account this object belongs to — yours, in your own reseller hierarchy.
     * This names the handle the registry's own WHOIS and RDAP publish as the registrar, which for a
     * reseller is a different party. Null when the response does not carry one.
     */
    public function registrarOfRecord(): ?string
    {
        $node = $this->xpath->query("//*[local-name()='registrar']")->item(0);
        if ($node === null) {
            return null;
        }
        $handle = trim($node->textContent);

        return $handle !== '' ? $handle : null;
    }

    /**
     * Per-name prices from a domain:check + fee:check response (RFC 8748 fee:chkData),
     * keyed by domain name:
     *
     *   ['example.com.ua' => [
     *       'avail'    => true,          // false => see 'reason' (unknown zone, currency…)
     *       'class'    => 'premium',     // present only for premium names
     *       'reason'   => null,
     *       'commands' => ['create' => ['years' => 1, 'fee' => '100.00'],
     *                      'renew'  => ['years' => 1, 'fee' => '90.00'],
     *                      'restore'=> ['years' => 1, 'fee' => null, 'reason' => '…']],
     *   ]]
     *
     * plus '_currency' => 'UAH' at the top level. Empty when the response carries no fee data.
     *
     * @return array<string, mixed>
     */
    public function fees(): array
    {
        $chk = $this->xpath->query('//fee:chkData')->item(0);
        if (!$chk instanceof \DOMElement) {
            return [];
        }
        $out = ['_currency' => $this->childText($chk, 'currency')];
        /** @var \DOMElement $cd */
        foreach ($this->xpath->query('./fee:cd', $chk) as $cd) {
            $name = $this->childText($cd, 'objID');
            $entry = [
                'avail'    => $cd->getAttribute('avail') !== '0',
                'reason'   => $this->childText($cd, 'reason') ?: null,
                'commands' => [],
                'periods'  => [],
            ];
            $class = $this->childText($cd, 'class');
            if ($class !== '') {
                $entry['class'] = $class;
            }
            /** @var \DOMElement $cmd */
            foreach ($this->xpath->query('./fee:command', $cd) as $cmd) {
                $feeNode = $this->xpath->query('./fee:fee', $cmd)->item(0);
                $c = [
                    'years' => max(1, (int) $this->childText($cmd, 'period')),
                    'fee'   => $feeNode !== null ? trim($feeNode->textContent) : null,
                ];
                $cmdReason = $this->childText($cmd, 'reason');
                if ($cmdReason !== '') {
                    $c['reason'] = $cmdReason;
                }
                $op = $cmd->getAttribute('name');
                $entry['periods'][] = ['op' => $op] + $c;
                // Asking one operation at several periods brings back one <fee:command> per period,
                // all with the same name. The map keeps the FIRST — the period you asked for first
                // — so a caller reading commands['create'] still gets an answer. Read `periods`
                // whenever you asked for more than one.
                if (!isset($entry['commands'][$op])) {
                    $entry['commands'][$op] = $c;
                }
            }
            $out[$name] = $entry;
        }

        return $out;
    }

    /**
     * The quoted price for ONE operation at ONE period, or null when the answer carried no such
     * quote.
     *
     *     $r = $client->domain()->check(['example1.com.ua'], fee: ['create' => [1, 2, 5]]);
     *     $r->feeFor('example1.com.ua', 'create', 5);   // the five-year price
     *
     * Use this rather than indexing fees() whenever you asked one operation at several periods:
     * the map there holds one entry per operation. `transfer` and `restore` are one-year
     * operations however many years you ask for, so read those back at 1.
     */
    public function feeFor(string $name, string $operation, int $years = 1): ?string
    {
        foreach ($this->fees() as $key => $entry) {
            if ($key === '_currency' || !is_array($entry) || strcasecmp($key, $name) !== 0) {
                continue;
            }
            foreach ($entry['periods'] ?? [] as $quote) {
                if ($quote['op'] === $operation && $quote['years'] === $years) {
                    return $quote['fee'];
                }
            }
        }

        return null;
    }

    /**
     * The fee actually charged, echoed on a successful transform that carried a fee
     * agreement (fee:creData / renData / trnData / updData), e.g.
     * ['currency' => 'UAH', 'fee' => '100.00'] — or null when the response has none.
     *
     * @return array{currency:string,fee:string}|null
     */
    public function chargedFee(): ?array
    {
        foreach (['creData', 'renData', 'trnData', 'updData', 'delData'] as $local) {
            $el = $this->xpath->query('//fee:' . $local)->item(0);
            if ($el instanceof \DOMElement) {
                return [
                    'currency' => $this->childText($el, 'currency'),
                    'fee'      => $this->childText($el, 'fee'),
                ];
            }
        }

        return null;
    }

    /** The trademark or licence number from a domain:info response, or null. Carried in the registry's own extension, so it is present only where that registry has one. */
    public function license(): ?string
    {
        return $this->value('license');
    }

    /**
     * RGP status values from a domain:info response (e.g. ['redemptionPeriod']); empty if none.
     *
     * @return string[]
     */
    public function rgpStatus(): array
    {
        $out = [];
        foreach ($this->xpath->query("//*[local-name()='rgpStatus']/@s") as $attr) {
            $out[] = $attr->nodeValue;
        }

        return $out;
    }

    /** The transfer status from a transfer response or poll trnData (e.g. "pending"), or null. */
    public function transferStatus(): ?string
    {
        return $this->value('trStatus');
    }

    /**
     * DNSSEC DS records from a domain:info response (secDNS:dsData), each as
     * ['keyTag'=>int,'alg'=>int,'digestType'=>int,'digest'=>string]. Empty when unsigned.
     *
     * @return array<int, array{keyTag:int,alg:int,digestType:int,digest:string}>
     */
    public function dsRecords(): array
    {
        $out = [];
        /** @var \DOMElement $ds */
        foreach ($this->xpath->query('//secDNS:dsData') as $ds) {
            $out[] = [
                'keyTag'     => (int) $this->childText($ds, 'keyTag'),
                'alg'        => (int) $this->childText($ds, 'alg'),
                'digestType' => (int) $this->childText($ds, 'digestType'),
                'digest'     => $this->childText($ds, 'digest'),
            ];
        }

        return $out;
    }

    /**
     * DNSSEC key records from a domain:info response (top-level secDNS:keyData), each as
     * ['flags'=>int,'protocol'=>int,'alg'=>int,'pubKey'=>string]. Empty when none.
     *
     * @return array<int, array{flags:int,protocol:int,alg:int,pubKey:string}>
     */
    public function keyRecords(): array
    {
        $out = [];
        /** @var \DOMElement $kd */
        foreach ($this->xpath->query('//secDNS:infData/secDNS:keyData') as $kd) {
            $out[] = [
                'flags'    => (int) $this->childText($kd, 'flags'),
                'protocol' => (int) $this->childText($kd, 'protocol'),
                'alg'      => (int) $this->childText($kd, 'alg'),
                'pubKey'   => $this->childText($kd, 'pubKey'),
            ];
        }

        return $out;
    }

    /** True when a domain:info response carries DNSSEC data (any DS or key records). */
    public function isSigned(): bool
    {
        return $this->dsRecords() !== [] || $this->keyRecords() !== [];
    }

    /**
     * Extra diagnostic text from a failed command's <extValue><reason> elements.
     *
     * @return string[]
     */
    public function errorReasons(): array
    {
        return $this->collect('//e:extValue/e:reason');
    }

    /**
     * Is this one name available? `null` when the response says nothing about it.
     *
     * Saves indexing `availability()` by hand, which returns `null` for both "taken" and "you
     * misspelled the key" — two answers that must not look the same when the next line registers
     * the name.
     */
    public function isAvailable(string $name): ?bool
    {
        foreach ($this->availability() as $candidate => $free) {
            if (strcasecmp($candidate, $name) === 0) {
                return $free;
            }
        }

        return null;
    }

    /** The object this response is about: a domain name, a host name or a contact id. */
    public function objectName(): ?string
    {
        // Taken from the DIRECT child of the object block. A document-wide search for <name> finds a
        // contact's postalInfo name first, so contact:info would answer with the person's full name
        // where the caller asked for the handle — and feeding that back as an id draws a 2303.
        $direct = $this->xpath->query('//e:resData/*/*[local-name()="id" or local-name()="name"]')->item(0);
        if ($direct !== null && trim($direct->textContent) !== '') {
            return trim($direct->textContent);
        }

        return $this->value('name') ?? $this->value('id');
    }

    /**
     * Expiry, as the registry sent it: `2027-04-01T09:15:00Z` or with an offset.
     *
     * Kept as the SERVER's string rather than a parsed date. The registry decides which calendar day
     * a renewal lands on, and re-formatting through a local timezone is how a client ends up
     * displaying — and renewing against — the day before.
     */
    public function expiryDate(): ?string
    {
        return $this->value('exDate');
    }

    /** When the object was created, as the registry sent it. */
    public function createdDate(): ?string
    {
        return $this->value('crDate');
    }

    /** When the object was last changed, as the registry sent it; null if never. */
    public function updatedDate(): ?string
    {
        return $this->value('upDate');
    }

    /** The registry's own identifier for the object (ROID). */
    public function roid(): ?string
    {
        return $this->value('roid');
    }

    /** The registrant contact handle of a domain. */
    public function registrant(): ?string
    {
        return $this->value('registrant');
    }

    /** The registrar currently sponsoring the object. */
    public function sponsor(): ?string
    {
        return $this->value('clID');
    }

    /**
     * A domain's nameservers, however the registry expressed them.
     *
     * Both EPP models are covered: `<domain:hostObj>` (a reference to a host object) and
     * `<domain:hostAttr>` (the name inlined with its glue). A client that reads only hostObj sees an
     * empty list against a registry using the other model, and concludes the domain has no
     * nameservers at all.
     *
     * @return string[] lower-cased, in the order the registry listed them
     */
    public function nameservers(): array
    {
        $out = [];
        foreach ($this->xpath->query('//*[local-name()="hostObj"]') as $node) {
            $out[] = strtolower(trim($node->textContent));
        }
        foreach ($this->xpath->query('//*[local-name()="hostAttr"]/*[local-name()="hostName"]') as $node) {
            $out[] = strtolower(trim($node->textContent));
        }

        return array_values(array_unique($out));
    }

    /**
     * A domain's role contacts, keyed by role: `['admin' => ['c-1'], 'tech' => ['c-1','c-2']]`.
     *
     * The registrant is NOT included — it is a separate element with its own meaning; see
     * registrant().
     *
     * @return array<string, string[]>
     */
    public function contacts(): array
    {
        $out = [];
        /** @var \DOMElement $el */
        foreach ($this->xpath->query('//*[local-name()="contact"][@type]') as $el) {
            $role = $el->getAttribute('type');
            $handle = trim($el->textContent);
            if ($role === '' || $handle === '') {
                continue;
            }
            $out[$role][] = $handle;
        }

        return $out;
    }

    /**
     * The handles in ONE role: `contactsFor('tech')` => `['c-tech1', 'c-tech2']`.
     *
     * Empty when the domain carries nobody in that role — which is a legitimate answer, not an
     * error, since only the registrant is mandatory everywhere.
     *
     * The role is matched case-insensitively: registries are inconsistent about `tech` vs `Tech`,
     * and a case-sensitive lookup silently returns "no technical contact" for a domain that has one.
     *
     * @return string[]
     */
    public function contactsFor(string $role): array
    {
        foreach ($this->contacts() as $key => $handles) {
            if (strcasecmp($key, $role) === 0) {
                return $handles;
            }
        }

        return [];
    }

    /**
     * The administrative contacts — the people authorised to make decisions about the domain.
     *
     * @return string[]
     */
    public function adminContacts(): array
    {
        return $this->contactsFor('admin');
    }

    /**
     * The technical contacts — the people to reach about DNS and delegation.
     *
     * @return string[]
     */
    public function techContacts(): array
    {
        return $this->contactsFor('tech');
    }

    /**
     * The billing contacts — the people to reach about invoices for this domain.
     *
     * @return string[]
     */
    public function billingContacts(): array
    {
        return $this->contactsFor('billing');
    }

    /**
     * Every handle attached to the domain in any capacity, registrant included, de-duplicated.
     *
     * Use this when you care THAT a contact is referenced rather than in which role — deleting a
     * contact, or working out which of your contact objects are still in use.
     *
     * @return string[]
     */
    public function allContacts(): array
    {
        $out = [];
        $registrant = $this->registrant();
        if ($registrant !== null && $registrant !== '') {
            $out[] = $registrant;
        }
        foreach ($this->contacts() as $handles) {
            foreach ($handles as $handle) {
                $out[] = $handle;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Subordinate hosts — nameserver objects that live UNDER this domain
     * (`<domain:host>ns1.example.com.ua</domain:host>` in a domain:info response).
     *
     * The registry refuses to delete a domain while these exist, so check the list before a delete
     * and remove or rename them first.
     *
     * @return string[] lower-cased
     */
    public function subordinateHosts(): array
    {
        $out = [];
        foreach ($this->xpath->query('//*[local-name()="host"][not(*)]') as $node) {
            $name = strtolower(trim($node->textContent));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** A contact's e-mail address. */
    public function email(): ?string
    {
        return $this->value('email');
    }

    /** A contact's voice number, in the EPP `+CC.NNNN` form. */
    public function voice(): ?string
    {
        return $this->value('voice');
    }

    /** A contact's fax number, in the EPP `+CC.NNNN` form. */
    public function fax(): ?string
    {
        return $this->value('fax');
    }

    /**
     * A contact's postal addresses, keyed by form: `'int'` (ASCII, always accepted by every
     * registry) and `'loc'` (the local script, e.g. Cyrillic). A contact may carry either or both.
     *
     * Each entry: `['name','org','street'(array),'city','sp','pc','cc']`, missing parts as `''`.
     * Read `['int']` when you need something you can safely print anywhere; read `['loc']` when you
     * want the address as the registrant actually wrote it.
     *
     * @return array<string, array{name: string, org: string, street: string[], city: string, sp: string, pc: string, cc: string}>
     */
    public function postalInfo(): array
    {
        $out = [];
        /** @var \DOMElement $el */
        foreach ($this->xpath->query('//*[local-name()="postalInfo"]') as $el) {
            $type = $el->getAttribute('type') ?: 'int';
            $street = [];
            foreach ($el->getElementsByTagName('*') as $child) {
                if ($child->localName === 'street') {
                    $street[] = trim($child->textContent);
                }
            }
            $addr = $this->firstByLocalName($el, 'addr');
            $out[$type] = [
                'name'   => $this->childText($el, 'name'),
                'org'    => $this->childText($el, 'org'),
                'street' => $street,
                'city'   => $addr !== null ? $this->childText($addr, 'city') : '',
                'sp'     => $addr !== null ? $this->childText($addr, 'sp') : '',
                'pc'     => $addr !== null ? $this->childText($addr, 'pc') : '',
                'cc'     => $addr !== null ? $this->childText($addr, 'cc') : '',
            ];
        }

        return $out;
    }

    /**
     * A host object's glue addresses: `[['ip' => '192.0.2.1', 'version' => 'v4'], …]`.
     *
     * Only a host INSIDE the zone it serves carries glue; for an external nameserver the registry
     * returns none, and that is normal rather than a missing answer.
     *
     * @return array<int, array{ip: string, version: string}>
     */
    public function hostAddresses(): array
    {
        // Scoped to the host object itself. A bare //addr would also match the glue inside a
        // domain's hostAttr blocks, flattening the addresses of DIFFERENT nameservers into one list
        // that reads as a single well-addressed host and loses which IP belongs to which name.
        // Per-name glue is nameserverAddresses(). The contact <addr> is a container, hence not(*).
        return $this->addressesIn('//*[local-name()="infData"]/*[local-name()="addr"][not(*)]');
    }

    /**
     * A domain's INLINE glue, keyed by nameserver name:
     * `['ns1.example.com.ua' => [['ip' => '192.0.2.1', 'version' => 'v4']]]`.
     *
     * Only populated for registries that answer with `<domain:hostAttr>`; one answering with
     * `<domain:hostObj>` returns the names alone and you fetch the addresses with a host:info per
     * name. Either way nameservers() gives you the names, so use that for the list and this for the
     * glue — an empty result here does NOT mean the domain is undelegated.
     *
     * @return array<string, array<int, array{ip: string, version: string}>>
     */
    public function nameserverAddresses(): array
    {
        $out = [];
        /** @var \DOMElement $attr */
        foreach ($this->xpath->query('//*[local-name()="hostAttr"]') as $attr) {
            $name = strtolower($this->childText($attr, 'hostName'));
            if ($name === '') {
                continue;
            }
            $out[$name] = $this->addressesIn('./*[local-name()="hostAddr"]', $attr);
        }

        return $out;
    }

    /**
     * @return array<int, array{ip: string, version: string}>
     */
    private function addressesIn(string $query, ?\DOMElement $context = null): array
    {
        $out = [];
        /** @var \DOMElement $el */
        foreach ($this->xpath->query($query, $context) as $el) {
            $ip = trim($el->textContent);
            if ($ip === '') {
                continue;
            }
            // Absent @ip means v4 per the host schema's default, so an IPv4-only registry that
            // omits the attribute must not come back labelled as something else.
            $out[] = ['ip' => $ip, 'version' => $el->getAttribute('ip') ?: 'v4'];
        }

        return $out;
    }

    /**
     * The object's authorisation code (`<authInfo><pw>`), or null when the registry withheld it.
     *
     * Present only for the sponsoring registrar, and only on info commands that asked for it. This
     * is the secret that lets ANY registrar take the domain away from you: never log it, never put
     * it in a support ticket, and roll it after you have passed it to a customer.
     */
    public function authInfo(): ?string
    {
        $node = $this->xpath->query('//*[local-name()="authInfo"]/*[local-name()="pw"]')->item(0);
        if ($node === null) {
            return null;
        }
        $pw = trim($node->textContent);

        return $pw !== '' ? $pw : null;
    }

    /**
     * A contact's disclosure preference: `['flag' => true, 'elements' => ['email', 'voice']]`,
     * or null when the contact carries no preference and registry policy alone applies.
     *
     * `flag` true means the listed elements MAY be published; false means they must be withheld.
     * The elements NOT listed take the opposite of the flag, so the list is meaningless without it.
     *
     * @return array{flag: bool, elements: string[]}|null
     */
    public function disclose(): ?array
    {
        $el = $this->xpath->query('//*[local-name()="disclose"]')->item(0);
        if (!$el instanceof \DOMElement) {
            return null;
        }
        $elements = [];
        foreach ($el->getElementsByTagName('*') as $child) {
            $name = $child->localName;
            // postalInfo/org and /addr are distinguished by @type ("int" or "loc").
            $type = $child->getAttribute('type');
            $elements[] = $type !== '' ? $name . ':' . $type : $name;
        }

        return [
            'flag'     => in_array($el->getAttribute('flag'), ['1', 'true'], true),
            'elements' => $elements,
        ];
    }

    /**
     * The registry's fee class for a name, e.g. `'premium'` or `'standard'`; null when the answer
     * carried no class.
     *
     * Take the price from fees() rather than inferring it from the class — the class says which
     * price list applies, not what it costs.
     */
    public function feeClass(?string $name = null): ?string
    {
        foreach ($this->fees() as $key => $entry) {
            if ($key === '_currency' || !is_array($entry)) {
                continue;
            }
            if ($name !== null && strcasecmp($key, $name) !== 0) {
                continue;
            }
            if (isset($entry['class']) && $entry['class'] !== '') {
                return $entry['class'];
            }
        }

        return null;
    }

    /**
     * Whether the registry priced the name outside the standard list.
     *
     * A false here is not a promise of the standard price — it means the answer declared no special
     * class. Always charge from fees(), and re-check the fee on the create itself.
     */
    public function isPremium(?string $name = null): bool
    {
        $class = $this->feeClass($name);

        return $class !== null && strcasecmp($class, 'standard') !== 0;
    }

    /**
     * Why a name in a check response is unavailable, e.g. "In use" or "Reserved"; null when it is
     * available or the registry gave no reason.
     */
    public function unavailableReason(string $name): ?string
    {
        /** @var \DOMElement $cd */
        foreach ($this->xpath->query('//*[local-name()="cd"]') as $cd) {
            $label = $this->firstByLocalName($cd, 'name') ?? $this->firstByLocalName($cd, 'id');
            if ($label === null || strcasecmp(trim($label->textContent), $name) !== 0) {
                continue;
            }
            $reason = $this->childText($cd, 'reason');

            return $reason !== '' ? $reason : null;
        }

        return null;
    }

    /** The registrar that created the object. */
    public function createdBy(): ?string
    {
        return $this->value('crID');
    }

    /**
     * The registrar that last changed the object, or null when it has never been changed.
     *
     * Sent only to the sponsoring registrar. Pair it with updatedDate() when reconciling your own
     * records: a change you did not make means it came from the registry side or from a support
     * action, not from your system.
     */
    public function updatedBy(): ?string
    {
        return $this->value('upID');
    }

    /** When the object last changed hands, or null if it never has. */
    public function transferDate(): ?string
    {
        return $this->value('trDate');
    }

    /**
     * A transfer notice in full — who asked, when, who must answer, and by when.
     *
     * `transferStatus()` alone says a transfer is pending without saying whose or how long you have
     * to act, and `acDate` is the deadline after which the registry decides for you.
     *
     * @return array{status: string, requestedBy: string, requestedAt: string, actingClient: string, actBy: string, expiryDate: string}|null
     */
    public function transfer(): ?array
    {
        $el = $this->xpath->query('//*[local-name()="trnData"]')->item(0);
        if (!$el instanceof \DOMElement) {
            return null;
        }

        return [
            'status'       => $this->childText($el, 'trStatus'),
            'requestedBy'  => $this->childText($el, 'reID'),
            'requestedAt'  => $this->childText($el, 'reDate'),
            'actingClient' => $this->childText($el, 'acID'),
            'actBy'        => $this->childText($el, 'acDate'),
            'expiryDate'   => $this->childText($el, 'exDate'),
        ];
    }

    /**
     * What the registry did to one of your objects without you asking — RFC 8590 change poll.
     *
     * A poll notice about a server-initiated change carries a human sentence in `<msg>`, and that
     * sentence is written in your account's notification language. This is the same event stated so
     * that code can act on it: the object is in `<resData>` as usual, and this returns the change
     * itself.
     *
     * `state` says which way `<resData>` reads: `before` describes the object as it last was (a
     * domain that no longer exists cannot be described any other way), `after` describes it as it
     * now is. `who` is free text for audit and the server chooses what to put there — a role, a
     * name or an identifier. `Registry` means the change came from the registry side rather than
     * from your account. `reason` is the registry's own finer name for the event where it has one,
     * which may be more specific than `operation`.
     *
     * Null when the response carries no change block — including when you did not announce
     * `urn:ietf:params:xml:ns:changePoll-1.0` at login, since a server sends this only to a client
     * that asked for it.
     *
     * @return array{operation: string, op: string, state: string, date: string, svTRID: string, who: string, reason: string}|null
     */
    public function change(): ?array
    {
        $el = $this->xpath->query('//*[local-name()="changeData"]')->item(0);
        if (!$el instanceof \DOMElement) {
            return null;
        }
        $operation = $el->getElementsByTagNameNS('*', 'operation')->item(0);

        return [
            'operation' => $this->childText($el, 'operation'),
            // The `op` attribute qualifies a `custom` operation with the registry's own verb; empty
            // for every operation the RFC enumerates, which is most of them.
            'op'        => $operation instanceof \DOMElement ? $operation->getAttribute('op') : '',
            // 'after' is the schema default, so a server that omits the attribute means 'after'.
            'state'     => $el->getAttribute('state') !== '' ? $el->getAttribute('state') : 'after',
            'date'      => $this->childText($el, 'date'),
            'svTRID'    => $this->childText($el, 'svTRID'),
            'who'       => $this->childText($el, 'who'),
            'reason'    => $this->childText($el, 'reason'),
        ];
    }

    /** Your credit limit, or null when the response carries no balance block. */
    public function creditLimit(): ?string
    {
        return $this->value('creditLimit');
    }

    /**
     * Your current balance. Named for what it is, because `balance()` returns the whole block.
     * Money is a decimal STRING throughout: never cast it to float before doing arithmetic.
     */
    public function currentBalance(): ?string
    {
        return $this->balance() === null ? null : $this->value('balance');
    }

    /** What you can still spend: balance plus any credit limit. */
    public function availableCredit(): ?string
    {
        return $this->value('availableCredit');
    }

    /** The amount charged for the command just executed, or null when it carried no fee. */
    public function feeAmount(): ?string
    {
        $fee = $this->chargedFee();

        return $fee === null ? null : $fee['fee'];
    }

    /** The currency of feeAmount(). */
    public function feeCurrency(): ?string
    {
        $fee = $this->chargedFee();

        return $fee === null ? null : $fee['currency'];
    }

    /**
     * The `<extValue>` blocks in full: WHICH element the server is complaining about, not just the
     * complaint. This is how EPP identifies the offending part of a request — a `<check>` for five
     * domains that fails on one carries that one name here, and a server may relocate data it could
     * not return normally (RFC 9038) into the same place.
     *
     * `errorReasons()` gives the prose alone, which cannot be acted on programmatically: it tells
     * you something was rejected, never which of the five it was.
     *
     * @return array<int, array{element: string, namespace: string, text: string, values: array<string, string>, xml: string, reason: string, lang: string}>
     *         element/namespace/text/xml describe the offending node ('' when the server sent a
     *         reason with no value); values carries a container's children by local name; reason is
     *         the server's explanation; lang its language.
     */
    public function extValues(): array
    {
        $out = [];
        foreach ($this->xpath->query('//e:extValue') as $ext) {
            if (!$ext instanceof \DOMElement) {
                continue;
            }
            $reasonEl = $ext->getElementsByTagNameNS(Namespaces::EPP, 'reason')->item(0);
            $valueEl = $ext->getElementsByTagNameNS(Namespaces::EPP, 'value')->item(0);
            // <value> is xs:any processContents="skip", so its child can be an element in ANY
            // namespace — including one this client does not know. Report it as data, never parse it.
            $node = null;
            if ($valueEl instanceof \DOMElement) {
                foreach ($valueEl->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $node = $child;
                        break;
                    }
                }
            }
            // The offending element is usually a leaf (<domain:name>bad..name</domain:name>), and
            // then `text` is the answer. When it is a CONTAINER — an RFC 9038 payload the server
            // relocated here because the client did not announce its namespace — a recursive
            // textContent would fuse the children into one string ("120.00500.00"), which reads
            // like a value and is not one. So `text` is the element's OWN character data and
            // `values` carries the children by name, and neither case answers with junk.
            $ownText = '';
            $values = [];
            if ($node !== null) {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMText) {
                        $ownText .= $child->data;
                    } elseif ($child instanceof \DOMElement) {
                        $values[$child->localName] = trim($child->textContent);
                    }
                }
            } elseif ($valueEl !== null) {
                $ownText = $valueEl->textContent;
            }
            $out[] = [
                'element'   => $node?->localName ?? '',
                'namespace' => $node?->namespaceURI ?? '',
                'text'      => trim($ownText),
                'values'    => $values,
                'xml'       => $node !== null ? (string) $node->ownerDocument?->saveXML($node) : '',
                'reason'    => $reasonEl !== null ? trim($reasonEl->textContent) : '',
                'lang'      => $reasonEl instanceof \DOMElement ? $reasonEl->getAttribute('lang') : '',
            ];
        }

        return $out;
    }

    /**
     * Login only: the server's security warnings about THIS session (RFC 8807 loginSec:event).
     *
     * Each entry has `type` (password|certificate|cipher|tlsProtocol|newPW|stat|custom), `level`
     * ('warning' or 'error') and a human `text`, plus whichever of `name`, `exDate`, `value`,
     * `duration` and `lang` the event carries. A certificate about to expire arrives here as
     * type=certificate with the date in `exDate` — weeks before the day it would otherwise be
     * discovered, which is the day logins stop.
     *
     * Empty when the session is healthy, so treat any entry as something to act on. The server
     * sends these only to a client that took part in the extension; keep Config::$loginSecurity on.
     *
     * @return array<int, array<string, string>>
     */
    public function securityEvents(): array
    {
        $out = [];
        foreach ($this->xpath->query('//*[local-name()="loginSecData"]/*[local-name()="event"]') as $event) {
            if (!$event instanceof \DOMElement) {
                continue;
            }
            $entry = ['text' => trim($event->textContent)];
            foreach (['type', 'name', 'level', 'exDate', 'value', 'duration', 'lang'] as $attr) {
                if ($event->hasAttribute($attr)) {
                    $entry[$attr] = $event->getAttribute($attr);
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** Greeting only: the object services the server advertises. @return string[] */
    public function serviceObjUris(): array
    {
        return $this->collect('//e:svcMenu/e:objURI');
    }

    /** Greeting only: the extension services the server advertises. @return string[] */
    public function serviceExtUris(): array
    {
        return $this->collect('//e:svcMenu/e:svcExtension/e:extURI');
    }

    /** First element anywhere with this local name (namespace-agnostic), trimmed. */
    public function value(string $localName): ?string
    {
        $node = $this->xpath->query(sprintf("(//*[local-name()='%s'])[1]", $localName))->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** Every element with this local name, trimmed. @return string[] */
    public function values(string $localName): array
    {
        return $this->collect(sprintf("//*[local-name()='%s']", $localName));
    }

    /** The <resData> element of the response, if present (for custom parsing). */
    public function resData(): ?\DOMElement
    {
        $node = $this->xpath->query('//e:resData')->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function dom(): \DOMDocument
    {
        return $this->dom;
    }

    public function xpath(): \DOMXPath
    {
        return $this->xpath;
    }

    /** @return string[] */
    private function collect(string $query): array
    {
        $out = [];
        foreach ($this->xpath->query($query) as $node) {
            $out[] = trim($node->textContent);
        }

        return $out;
    }

    /** Text of a direct child element by local name, or '' if absent. */
    private function childText(\DOMElement $parent, string $localName): string
    {
        $node = $this->xpath->query("./*[local-name()='" . $localName . "']", $parent)->item(0);

        return $node !== null ? trim($node->textContent) : '';
    }
}

