<?php

declare(strict_types=1);

namespace EppTools\Command;

use EppTools\Client;
use EppTools\Frame;
use EppTools\Namespaces;
use EppTools\Builder\DomainCreateBuilder;
use EppTools\Builder\DomainUpdateBuilder;
use EppTools\Exception\ValidationException;
use EppTools\Options;
use EppTools\Response;

/**
 * Domain object commands (RFC 5731) plus the Registry .ua licence, secDNS (RFC 5910)
 * and RGP restore (RFC 3915) extensions. Reached via Client::domain().
 */
final class Domain
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** A fee query carries at most this many <fee:command> entries; a longer one is refused (2306). */
    private const MAX_FEE_COMMANDS = 20;

    /**
     * Check availability, optionally asking for prices at the same time (RFC 8748).
     *
     * The fee query is operation => years. A LIST of years asks the SAME operation at SEVERAL
     * periods in the one command, so a whole price table costs one round trip instead of five:
     *
     *     $client->domain()->check(['example1.com.ua'], fee: ['create' => [1, 2, 3, 5, 10], 'renew' => 1]);
     *
     * Read the reply with Response::feeFor() for a single figure, or Response::fees() for the lot.
     * Operations: create|renew|transfer|restore|update|delete.
     *
     * `transfer` and `restore` are one-year operations however many years you ask for, and the
     * reply echoes the period that would actually be charged — so quote them at one year and read
     * the answer back at one year.
     *
     * @param string[] $names
     * @param array<string, int|int[]> $fee operation => years, or operation => list of years
     * @param string|null $currency ask for the quote in this currency; omit to take the registry's
     *        own. A currency it does not price in comes back as unavailable with a reason, rather
     *        than as a converted guess.
     */
    public function check(array $names, array $fee = [], ?string $currency = null): Response
    {
        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
        foreach ($names as $name) {
            $frame->ns($check, Namespaces::DOMAIN, 'domain:name', $name);
        }

        $wanted = [];
        foreach ($fee as $op => $years) {
            foreach ((is_array($years) ? $years : [$years]) as $y) {
                $wanted[] = [(string) $op, max(1, (int) $y)];
            }
        }
        if (count($wanted) > self::MAX_FEE_COMMANDS) {
            throw new ValidationException(sprintf(
                'a fee query carries at most %d entries; this one has %d',
                self::MAX_FEE_COMMANDS,
                count($wanted),
            ));
        }
        if ($wanted !== [] || $currency !== null) {
            $feeCheck = $frame->ns($frame->extension(), Namespaces::FEE, 'fee:check');
            if ($currency !== null) {
                $frame->ns($feeCheck, Namespaces::FEE, 'fee:currency', strtoupper($currency));
            }
            foreach ($wanted as [$op, $y]) {
                $cmd = $frame->ns($feeCheck, Namespaces::FEE, 'fee:command', null, ['name' => $op]);
                $frame->ns($cmd, Namespaces::FEE, 'fee:period', (string) $y, ['unit' => 'y']);
            }
        }

        return $this->client->request($frame);
    }

    /**
     * Flattens a `contacts` option into (role, handle) pairs, accepting EITHER one handle per role
     * or SEVERAL.
     *
     *     ['admin' => 'A1', 'tech' => ['T1', 'T2']]
     *
     * RFC 5731 allows repeated `<domain:contact type="…">`, so a role may carry as many handles as
     * registry policy permits — each one becomes its own element. A scalar is treated as a
     * one-element list.
     *
     * @param mixed $contacts
     * @return list<array{0:string,1:string}>
     */
    private static function contactPairs(mixed $contacts): array
    {
        $out = [];
        foreach ((array) $contacts as $type => $handles) {
            foreach ((is_array($handles) ? $handles : [$handles]) as $handle) {
                $handle = trim((string) $handle);
                if ($handle !== '') {
                    $out[] = [(string) $type, $handle];
                }
            }
        }

        return $out;
    }

    /**
     * Attach the RFC 8748 fee AGREEMENT to a transform command: the price you consent to pay
     * (a string amount, or ['amount' => '100.00', 'currency' => 'UAH']). The server refuses
     * the command (2004) if the real price is higher — you are never charged more than this.
     *
     * @param string|array{amount:string,currency?:string} $fee
     */
    private function appendFeeAgreement(Frame $frame, string $local, string|array $fee): void
    {
        $amount = is_array($fee) ? (string) ($fee['amount'] ?? '') : (string) $fee;
        $currency = is_array($fee) ? ($fee['currency'] ?? null) : null;
        $el = $frame->ns($frame->extension(), Namespaces::FEE, 'fee:' . $local);
        if ($currency !== null) {
            $frame->ns($el, Namespaces::FEE, 'fee:currency', (string) $currency);
        }
        $frame->ns($el, Namespaces::FEE, 'fee:fee', $amount);
    }

    /** @param string $hosts which hosts the answer lists: "all" (default), "del", "sub" or "none". */
    public function info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::DOMAIN, 'domain:info');
        $frame->ns($info, Namespaces::DOMAIN, 'domain:name', $name, ['hosts' => $hosts]);
        if ($authInfo !== null) {
            $ai = $frame->ns($info, Namespaces::DOMAIN, 'domain:authInfo');
            $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /**
     * Build a registration step by step instead of assembling an options array.
     *
     *     $client->domain()->createBuilder('example3.com.ua')->years(1)->registrant('C1')->send()
     *
     * Same command, same frame, same result — create() is what it calls. The difference is that a
     * misspelling here is a method that does not exist, which your editor tells you about, rather
     * than a key nobody reads.
     */
    public function createBuilder(string $name): DomainCreateBuilder
    {
        return new DomainCreateBuilder($this, $name);
    }

    /** Build a change step by step. See createBuilder(); this one calls update(). */
    public function updateBuilder(string $name): DomainUpdateBuilder
    {
        return new DomainUpdateBuilder($this, $name);
    }

    /** Every option domain:create understands. A key outside this list is refused, not ignored. */
    private const CREATE_KEYS = ['years', 'nameservers', 'nameServers', 'registrant', 'contacts', 'authInfo', 'secDNS', 'license', 'fee'];

    /** Every option domain:update understands. */
    private const UPDATE_KEYS = ['add', 'rem', 'chg', 'restore', 'fee', 'license', 'secDNS'];

    /**
     * @param array{years?:int,registrant?:string,contacts?:array<string,string>,
     *     nameservers?:string[],nameServers?:string[],authInfo?:string,license?:string,
     *     secDNS?:array<string,mixed>,fee?:string|array{amount:string,currency?:string}} $options
     *     'fee': the RFC 8748 price you agree to pay (see appendFeeAgreement).
     */
    public function create(string $name, array $options = []): Response
    {
        Options::check($options, self::CREATE_KEYS, 'domain:create');
        if (isset($options['secDNS']) && is_array($options['secDNS'])) {
            Options::check($options['secDNS'], ['dsData', 'keyData', 'maxSigLife'], "domain:create 'secDNS'");
        }

        $frame = $this->client->frame();
        $create = $frame->ns($frame->verb('create'), Namespaces::DOMAIN, 'domain:create');
        $frame->ns($create, Namespaces::DOMAIN, 'domain:name', $name);
        if (isset($options['years'])) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:period', (string) (int) $options['years'], ['unit' => 'y']);
        }
        $nameservers = (array) (Options::pick($options, 'nameservers', 'nameServers') ?? []);
        if ($nameservers !== []) {
            $this->appendNameservers($frame, $create, $nameservers);
        }
        if (isset($options['registrant'])) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:registrant', (string) $options['registrant']);
        }
        foreach (self::contactPairs($options['contacts'] ?? []) as [$type, $handle]) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:contact', $handle, ['type' => $type]);
        }
        // authInfo is MANDATORY on domain:create per RFC 5731 (domain:createType requires it), so the
        // element is always emitted — with the caller's transfer secret, or an empty <pw/>
        // (schema-valid: pwType allows minLength 0) so the registry then applies its per-zone
        // authInfo policy (e.g. auto-mint). Omitting it fails server XSD validation, which is why
        // registering without a password still sends the element.
        $ai = $frame->ns($create, Namespaces::DOMAIN, 'domain:authInfo');
        $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', (string) ($options['authInfo'] ?? ''));

        $secDns = $options['secDNS'] ?? null;
        $license = $options['license'] ?? null;
        // secDNS:create requires at least one dsData|keyData (dsOrKeyType) — an empty/keyless
        // secDNS array must NOT emit a childless <secDNS:create/>, which the server XSD rejects.
        $hasSecDns = is_array($secDns) && (!empty($secDns['dsData']) || !empty($secDns['keyData']));
        if ($hasSecDns || $license !== null) {
            $ext = $frame->extension();
            if ($hasSecDns) {
                $secCreate = $frame->ns($ext, Namespaces::SECDNS, 'secDNS:create');
                if (isset($secDns['maxSigLife'])) {
                    $frame->ns($secCreate, Namespaces::SECDNS, 'secDNS:maxSigLife', (string) (int) $secDns['maxSigLife']);
                }
                $this->appendSecDnsRecords($frame, $secCreate, (array) $secDns);
            }
            if ($license !== null) {
                // Resolved from the greeting, not from a constant: a licence is a registry's own
                // extension and the URI differs between them. requireRegistryExtUri throws with the
                // list the server actually advertised rather than sending a guess, because an
                // extension in an unknown namespace is ignored rather than refused — the create
                // would succeed WITHOUT the licence and nothing would say so.
                $uri = $this->client->requireRegistryExtUri('domain:create with a licence');
                $u = $frame->ns($ext, $uri, 'registry:create');
                $frame->ns($u, $uri, 'registry:license', (string) $license);
            }
        }
        if (isset($options['fee'])) {
            $this->appendFeeAgreement($frame, 'create', $options['fee']);
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{add?:array<string,mixed>,rem?:array<string,mixed>,
     *     chg?:array{registrant?:string,authInfo?:string},restore?:bool,license?:string,
     *     secDNS?:array{add?:array<string,mixed>,rem?:array<string,mixed>,remAll?:bool,maxSigLife?:int},
     *     fee?:string|array{amount:string,currency?:string}} $options
     *     'fee': the RFC 8748 price you agree to pay (relevant for a restore).
     */
    public function update(string $name, array $options = []): Response
    {
        // Plain words first: 'remove'/'change' are the documented spellings, 'rem'/'chg' are EPP's
        // own abbreviations and keep working. See Options::canonicalise().
        $options = Options::canonicalise($options, ['remove' => 'rem', 'change' => 'chg']);
        if (isset($options['secDNS']) && is_array($options['secDNS'])) {
            $options['secDNS'] = Options::canonicalise($options['secDNS'], ['remove' => 'rem', 'removeAll' => 'remAll']);
        }

        Options::check($options, self::UPDATE_KEYS, 'domain:update');
        foreach (['add', 'rem'] as $block) {
            if (isset($options[$block]) && is_array($options[$block])) {
                Options::check($options[$block], ['ns', 'contacts', 'statuses'], "domain:update '{$block}'");
            }
        }
        if (isset($options['chg']) && is_array($options['chg'])) {
            Options::check($options['chg'], ['registrant', 'authInfo', 'clearAuthInfo'], "domain:update 'chg'");
        }
        if (isset($options['secDNS']) && is_array($options['secDNS'])) {
            Options::check($options['secDNS'], ['add', 'rem', 'remAll', 'maxSigLife'], "domain:update 'secDNS'");
        }

        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::DOMAIN, 'domain:update');
        $frame->ns($update, Namespaces::DOMAIN, 'domain:name', $name);

        foreach (['add', 'rem'] as $op) {
            $spec = $options[$op] ?? [];
            if ($spec === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::DOMAIN, "domain:{$op}");
            if (!empty($spec['ns'])) {
                $this->appendNameservers($frame, $block, (array) $spec['ns']);
            }
            foreach (self::contactPairs($spec['contacts'] ?? []) as [$type, $handle]) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:contact', $handle, ['type' => $type]);
            }
            foreach ((array) ($spec['statuses'] ?? []) as $status) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:status', null, ['s' => (string) $status]);
            }
        }

        $chg = $options['chg'] ?? [];
        if ($chg !== []) {
            $block = $frame->ns($update, Namespaces::DOMAIN, 'domain:chg');
            if (isset($chg['registrant'])) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:registrant', (string) $chg['registrant']);
            }
            if (!empty($chg['clearAuthInfo'])) {
                // <authInfo><null/> REMOVES the transfer secret rather than setting it to something.
                // The distinction matters after a leak: an empty <pw/> stores the empty string,
                // which is a value the holder can still present, so the domain stays as movable as
                // it was. Only this clears it. Mutually exclusive with setting one, and the schema
                // has no way to express both.
                if (isset($chg['authInfo'])) {
                    throw new ValidationException(
                        "domain:update 'chg' cannot both set 'authInfo' and clear it — "
                        . "drop one of 'authInfo' / 'clearAuthInfo'",
                    );
                }
                $ai = $frame->ns($block, Namespaces::DOMAIN, 'domain:authInfo');
                $frame->ns($ai, Namespaces::DOMAIN, 'domain:null');
            }
            if (isset($chg['authInfo'])) {
                $ai = $frame->ns($block, Namespaces::DOMAIN, 'domain:authInfo');
                $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', (string) $chg['authInfo']);
            }
        }

        if (!empty($options['restore'])) {
            $rgp = $frame->ns($frame->extension(), Namespaces::RGP, 'rgp:update');
            $frame->ns($rgp, Namespaces::RGP, 'rgp:restore', null, ['op' => 'request']);
        }
        if (isset($options['fee'])) {
            $this->appendFeeAgreement($frame, 'update', $options['fee']);
        }
        if (isset($options['license'])) {
            $uri = $this->client->requireRegistryExtUri('domain:update with a licence');
            $u = $frame->ns($frame->extension(), $uri, 'registry:update');
            $frame->ns($u, $uri, 'registry:license', (string) $options['license']);
        }

        // DNSSEC delta (RFC 5910): rem (specific or all), add, chg maxSigLife. At least one of
        // them is required — 'secDNS' => [] must NOT emit a childless <secDNS:update/>, which the
        // server rejects with 2003 for what reads as a no-op, losing the DNSSEC change.
        $secDns = $options['secDNS'] ?? null;
        $hasSecDelta = is_array($secDns)
            && (!empty($secDns['remAll']) || !empty($secDns['rem']) || !empty($secDns['add']) || isset($secDns['maxSigLife']));
        if ($hasSecDelta) {
            $secUpdate = $frame->ns($frame->extension(), Namespaces::SECDNS, 'secDNS:update');
            if (!empty($secDns['remAll'])) {
                $rem = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:rem');
                $frame->ns($rem, Namespaces::SECDNS, 'secDNS:all', 'true');
            } elseif (!empty($secDns['rem'])) {
                $rem = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:rem');
                $this->appendSecDnsRecords($frame, $rem, (array) $secDns['rem']);
            }
            if (!empty($secDns['add'])) {
                $add = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:add');
                $this->appendSecDnsRecords($frame, $add, (array) $secDns['add']);
            }
            if (isset($secDns['maxSigLife'])) {
                $chgSec = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:chg');
                $frame->ns($chgSec, Namespaces::SECDNS, 'secDNS:maxSigLife', (string) (int) $secDns['maxSigLife']);
            }
        }

        return $this->client->request($frame);
    }

    /**
     * Renew a domain.
     *
     * `$curExpDate` accepts EITHER form and needs no trimming by the caller: the date the registry
     * wants (`2027-04-01`) or the full timestamp its `<exDate>` carries (`2027-04-01T09:15:00.0Z`),
     * which is what `Response::expiryDate()` returns. See dateOnly() for why this is the library's
     * job rather than yours.
     *
     * @param string|array{amount:string,currency?:string}|null $fee RFC 8748 price you agree to pay
     */
    public function renew(string $name, string $curExpDate, int $years = 1, string|array|null $fee = null): Response
    {
        $frame = $this->client->frame();
        $renew = $frame->ns($frame->verb('renew'), Namespaces::DOMAIN, 'domain:renew');
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:name', $name);
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:curExpDate', self::dateOnly($curExpDate));
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:period', (string) $years, ['unit' => 'y']);
        if ($fee !== null) {
            $this->appendFeeAgreement($frame, 'renew', $fee);
        }

        return $this->client->request($frame);
    }

    /**
     * The calendar date at the front of an EPP timestamp, or the string unchanged if it does not
     * begin with one.
     *
     * WHY THIS EXISTS. Two EPP elements carry the same expiry and are DIFFERENT XML types.
     * `<domain:exDate>` is an `xs:dateTime` — `2027-04-01T09:15:00.0Z` — and `<domain:curExpDate>`
     * is an `xs:date` — `2027-04-01`. So the obvious code, feeding what `info()` returned straight
     * back into `renew()`, is refused: the frame fails schema validation, or the registry reads a
     * date it cannot match and answers 2105 "expiry is not what you said". The renewal does not
     * happen, and the reason names neither element.
     *
     * WHY NO TIMEZONE CONVERSION. The date is taken as the SERVER WROTE IT, with no parsing and no
     * reformatting. EPP timestamps are UTC, and the registry's own expiry date is the UTC one; a
     * client that reformats through a local zone lands a day either side of it for every domain
     * expiring near midnight — and then renews against a date the registry does not hold.
     *
     * Anything not starting with a `YYYY-MM-DD` is passed through untouched, so an unusual value
     * reaches the server and earns the server's own error, rather than being silently truncated
     * into a date that means something else.
     */
    public static function dateOnly(string $value): string
    {
        return preg_match('~^(\d{4}-\d{2}-\d{2})~', $value, $m) === 1 ? $m[1] : $value;
    }

    public function delete(string $name): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::DOMAIN, 'domain:delete');
        $frame->ns($del, Namespaces::DOMAIN, 'domain:name', $name);

        return $this->client->request($frame);
    }

    /**
     * Restore a redemption-period domain (rgp:restore op="request").
     * @param string|array{amount:string,currency?:string}|null $fee RFC 8748 restore price you agree to pay
     */
    public function restore(string $name, string|array|null $fee = null): Response
    {
        $options = ['restore' => true];
        if ($fee !== null) {
            $options['fee'] = $fee;
        }

        return $this->update($name, $options);
    }

    /**
     * @param string $op one of request|approve|reject|cancel|query
     * @param string|array{amount:string,currency?:string}|null $fee RFC 8748 transfer price you agree to pay (request only)
     */
    public function transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null, string|array|null $fee = null): Response
    {
        $frame = $this->client->frame();
        $transfer = $frame->verb('transfer');
        $transfer->setAttribute('op', $op);
        $d = $frame->ns($transfer, Namespaces::DOMAIN, 'domain:transfer');
        $frame->ns($d, Namespaces::DOMAIN, 'domain:name', $name);
        if ($years !== null) {
            $frame->ns($d, Namespaces::DOMAIN, 'domain:period', (string) $years, ['unit' => 'y']);
        }
        if ($authInfo !== null) {
            $ai = $frame->ns($d, Namespaces::DOMAIN, 'domain:authInfo');
            $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', $authInfo);
        }
        if ($fee !== null) {
            $this->appendFeeAgreement($frame, 'transfer', $fee);
        }

        return $this->client->request($frame);
    }

    /**
     * Append a <domain:ns> block.
     *
     * A nameserver is either a NAME — a reference to a host object that already exists at the
     * registry — or a name WITH its glue addresses, inlined. Registries take one model or the
     * other, so ask yours which; a plain string gives the first and
     * `['name' => 'ns1.example', 'addresses' => ['192.0.2.1']]` gives the second.
     *
     * RFC 5731 makes <domain:ns> a choice, so the two cannot be mixed in one command: a frame
     * carrying both is refused by the schema, which is a bare 2001 naming no field.
     *
     * @param array<int, string|array<string, mixed>> $nameservers
     */
    private function appendNameservers(Frame $frame, \DOMElement $parent, array $nameservers): void
    {
        $inline = [];
        foreach ($nameservers as $host) {
            $inline[] = is_array($host) && isset($host['name']);
        }
        if (in_array(true, $inline, true) && in_array(false, $inline, true)) {
            throw new ValidationException(
                'nameservers must be all names or all name-with-glue, not a mixture — '
                . 'RFC 5731 makes <domain:ns> a choice between the two models',
            );
        }

        $ns = $frame->ns($parent, Namespaces::DOMAIN, 'domain:ns');
        foreach ($nameservers as $host) {
            if (!is_array($host)) {
                $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostObj', (string) $host);

                continue;
            }
            $attr = $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostAttr');
            $frame->ns($attr, Namespaces::DOMAIN, 'domain:hostName', (string) $host['name']);
            foreach ((array) ($host['addresses'] ?? []) as $ip) {
                $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
                $frame->ns($attr, Namespaces::DOMAIN, 'domain:hostAddr', (string) $ip, ['ip' => $version]);
            }
        }
    }

    /** Append RFC 5910 dsData / keyData records to a secDNS block (create / add / rem). */
    private function appendSecDnsRecords(Frame $frame, \DOMElement $parent, array $spec): void
    {
        foreach ((array) ($spec['dsData'] ?? []) as $ds) {
            $dsData = $frame->ns($parent, Namespaces::SECDNS, 'secDNS:dsData');
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:keyTag', (string) (int) ($ds['keyTag'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:alg', (string) (int) ($ds['alg'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:digestType', (string) (int) ($ds['digestType'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:digest', (string) ($ds['digest'] ?? ''));
            // RFC 5910 lets a DS record carry the DNSKEY it was computed from. Registries that
            // accept it can verify the digest for you; ones that do not answer 2306 rather than
            // ignoring it, so sending it is safe to try and never silently discarded.
            if (isset($ds['keyData']) && is_array($ds['keyData'])) {
                $this->appendKeyData($frame, $dsData, $ds['keyData']);
            }
        }
        foreach ((array) ($spec['keyData'] ?? []) as $key) {
            $this->appendKeyData($frame, $parent, (array) $key);
        }
    }

    /**
     * One <secDNS:keyData> block, in the element order the schema fixes.
     *
     * @param array<string, mixed> $key
     */
    private function appendKeyData(Frame $frame, \DOMElement $parent, array $key): void
    {
        $keyData = $frame->ns($parent, Namespaces::SECDNS, 'secDNS:keyData');
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:flags', (string) (int) ($key['flags'] ?? 257));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:protocol', (string) (int) ($key['protocol'] ?? 3));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:alg', (string) (int) ($key['alg'] ?? 0));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:pubKey', (string) ($key['pubKey'] ?? ''));
    }
}

