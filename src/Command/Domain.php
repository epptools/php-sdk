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
        if ($names === []) {
            // domain:checkType requires at least one <domain:name>, so an empty list builds a
            // childless <domain:check/> the server never parses: a bare 2001 for a question the
            // caller thinks they asked. A loop over an empty result set reaches this without
            // anyone writing check([]) on purpose.
            throw new ValidationException(
                'domain:check needs at least one name — RFC 5731 requires a <domain:name>, so an '
                . 'empty list is a frame the registry refuses rather than an empty answer',
            );
        }

        // The fee query is worked out and refused BEFORE the frame exists, so a call that cannot be
        // sent does not spend a clTRID on the way out.
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
        if ($wanted === [] && $currency !== null) {
            // fee:checkType requires at least one <fee:command>, so a currency on its own builds a
            // <fee:check> the schema refuses. Refused here rather than sent, and refused rather than
            // dropped: the caller asked what a name costs in this currency, and quietly sending the
            // check without the fee query would answer availability and say nothing about price —
            // silence that reads as "there is nothing to charge".
            throw new ValidationException(sprintf(
                "a fee query needs at least one operation: a currency ('%s') on its own prices "
                . 'nothing, and RFC 8748 requires a <fee:command> in <fee:check>. Pass '
                . "fee: ['create' => 1] alongside it.",
                $currency,
            ));
        }

        // Before the frame, like the fee query above: a blank name is refused, not dropped. See
        // Options::identifiers() for why this list is the one place a blank is not filtered.
        $names = Options::identifiers($names, 'domain:check', 'domain:name');

        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
        foreach ($names as $name) {
            $frame->ns($check, Namespaces::DOMAIN, 'domain:name', $name);
        }
        if ($wanted !== []) {
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
     * A registration period in years, checked against the bound RFC 5731 puts on it.
     *
     * periodType is 1 to 99, so a 0 is not a small period - it is a frame the schema refuses, and the
     * refusal comes back as a bare 2001 or 2004 that names no element. Checked here so the caller is
     * told which value was wrong, by their own code, before anything is sent. A transfer is the one
     * command where 0 is meaningful - see transfer(), which omits the element instead of writing it.
     */
    private static function period(int $years, string $where): string
    {
        if ($years < 1 || $years > 99) {
            throw new ValidationException(
                "{$where} period must be 1 to 99 years (RFC 5731), not {$years} - omit it to take the "
                . "registry's own default",
            );
        }

        return (string) $years;
    }

    /**
     * A maximum signature lifetime in seconds, checked against the bound RFC 5910 puts on it.
     *
     * The same shape as period(), one element along. secDNS-1.1.xsd restricts maxSigLifeType to
     * minInclusive 1, so a 0 does not mean "no limit" and a negative does not mean anything: both are
     * frames the schema refuses, and so is anything that is not a number at all. The whole DNSSEC
     * change goes with them, answered by a bare 2001 that names no element — a domain left unsigned,
     * or left signed with keys the caller believed they had rolled.
     */
    private static function maxSigLife(mixed $seconds, string $where): string
    {
        if (!is_numeric($seconds) || (int) $seconds < 1) {
            throw new ValidationException(sprintf(
                "%s maxSigLife must be a whole number of seconds, 1 or more (RFC 5910), not '%s' - "
                . "omit it to leave the registry's own signature lifetime alone",
                $where,
                is_scalar($seconds) ? (string) $seconds : get_debug_type($seconds),
            ));
        }

        return (string) (int) $seconds;
    }

    /**
     * A fee agreement amount, checked against the type RFC 8748 gives it.
     *
     * fee:nonNegativeDecimal is an xs:decimal with minInclusive 0, so a currency symbol, a thousands
     * separator or a word reaches the server as a frame it cannot parse. That matters more here than
     * anywhere else in the library: the agreement is the one protection a registrar has against being
     * charged a premium price, and a malformed one is not a smaller cap — it is no cap, behind a bare
     * 2001 that names no element. Checked in the command so a direct call and the builder's
     * maxFee() step cannot disagree about what a price looks like.
     */
    private static function feeAmount(mixed $amount): string
    {
        $amount = trim((string) $amount);
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new ValidationException(
                "a fee amount must be a plain decimal like '100.00' (got '{$amount}') - RFC 8748 types "
                . 'it as a non-negative decimal, with no currency symbol and no separators',
            );
        }

        return $amount;
    }

    /** The five transfer operations epp-1.0.xsd enumerates (transferOpType). */
    private const TRANSFER_OPS = ['request', 'approve', 'reject', 'cancel', 'query'];

    /** What a domain info may be asked to list about subordinate hosts (domain:hostsType). */
    private const INFO_HOSTS = ['all', 'del', 'sub', 'none'];

    /** The three contact roles a domain has (domain:contactAttrType). */
    private const CONTACT_ROLES = ['admin', 'billing', 'tech'];

    /**
     * Flattens a `contacts` option into (role, handle) pairs, accepting EITHER one handle per role
     * or SEVERAL.
     *
     *     ['admin' => 'ADM-0001', 'tech' => ['TEC-0001', 'TEC-0002']]
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
            // The role is an XSD enumeration, not a label the registry interprets: a reseller or an
            // abuse role is refused as a frame, not as policy, and the whole command goes with it.
            // Checked once the role has a handle to carry, so naming a role and passing nothing
            // still writes nothing rather than raising.
            $handles = Options::nonEmptyStrings($handles);
            if ($handles === []) {
                continue;
            }
            $role = Options::oneOf($type, self::CONTACT_ROLES, 'contact role', 'RFC 5731 domain:contactAttrType');
            foreach ($handles as $handle) {
                $out[] = [$role, $handle];
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
        if (is_array($fee)) {
            // The currency is half the agreement. A misspelled key dropped it silently, and the cap
            // was then compared in whatever currency the registry prices in - so the one protection
            // a registrar has against a premium price either did not bite or bit at another figure.
            Options::check($fee, self::FEE_KEYS, 'fee agreement');
        }
        $amount = self::feeAmount(is_array($fee) ? ($fee['amount'] ?? '') : $fee);
        $currency = is_array($fee) ? ($fee['currency'] ?? null) : null;
        $el = $frame->ns($frame->extension(), Namespaces::FEE, 'fee:' . $local);
        if ($currency !== null) {
            // fee:currencyType is pattern [A-Z]{3}, so 'uah' is refused with a bare 2001 at the one
            // point in the protocol where money is involved. check() already upper-cases the currency
            // it is asked to quote in; the agreement is the same three letters and takes the same
            // treatment, so a caller reading their own config out of a database is not punished for
            // its case.
            $frame->ns($el, Namespaces::FEE, 'fee:currency', strtoupper(trim((string) $currency)));
        }
        $frame->ns($el, Namespaces::FEE, 'fee:fee', $amount);
    }

    /** @param string $hosts which hosts the answer lists: "all" (default), "del", "sub" or "none". */
    public function info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response
    {
        // domain:hostsType is an enumeration of four values. Anything else is refused as a frame, so
        // an info written from memory ("hosts: 'every'") comes back as a bare 2001 that names no
        // attribute rather than as the domain's data.
        $hosts = Options::oneOf($hosts, self::INFO_HOSTS, "domain:info 'hosts' filter", 'RFC 5731 domain:hostsType');
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
            $frame->ns($create, Namespaces::DOMAIN, 'domain:period', self::period((int) $options['years'], 'domain:create'), ['unit' => 'y']);
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
                    $frame->ns($secCreate, Namespaces::SECDNS, 'secDNS:maxSigLife', self::maxSigLife($secDns['maxSigLife'], 'domain:create'));
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

        // What the command will actually contain is worked out BEFORE the frame exists, because
        // Client::frame() stamps a clTRID and a command that turns out to ask for nothing should not
        // spend one on the way out.
        $blocks = [];
        foreach (['add', 'rem'] as $op) {
            $spec = (array) ($options[$op] ?? []);
            $blocks[$op] = [
                'ns' => (array) ($spec['ns'] ?? []),
                'contacts' => self::contactPairs($spec['contacts'] ?? []),
                // Trimmed and blank-dropped here, not only in the builder: domain:statusValueType is
                // an enumeration and the empty string is not a member of it, so one blank status
                // costs the whole update. See Options::nonEmptyStrings().
                'statuses' => Options::nonEmptyStrings($spec['statuses'] ?? []),
            ];
        }

        // RFC 3915 section 4.2.5: "at least one empty <domain:add>, <domain:rem>, or <domain:chg>
        // element MUST be present if this extension is specified within an <update> command." A
        // restore changes nothing about the domain, so there is nothing to put in any of the three —
        // and the RFC's own example answers that with an empty <domain:chg/>. Omitting it leaves a
        // domain:update with a name and no delta, which a server is entitled to read as a no-op and
        // refuse: the redemption window keeps running while the caller sees success or an error that
        // names nothing.
        $restoring = !empty($options['restore']);
        $chg = (array) ($options['chg'] ?? []);

        $registrant = isset($chg['registrant']) ? trim((string) $chg['registrant']) : null;
        if ($registrant === '') {
            // RFC 5731 gives authInfo a nullable form and gives the registrant none, so a domain can
            // be moved to another holder but not left without one. A blank reaches the wire as an
            // empty <domain:registrant/>, which clIDType refuses for minLength 3 — a 2001 naming an
            // element the caller never meant to send.
            throw new ValidationException(
                "domain:update 'chg' cannot clear the registrant: RFC 5731 defines no null form for "
                . 'it, and an empty value is a frame the registry refuses. Name the new holder.',
            );
        }

        // <authInfo><null/> REMOVES the transfer secret rather than setting it to something. The
        // distinction matters after a leak: an empty <pw/> stores the empty string, which is a value
        // the holder can still present, so the domain stays as movable as it was. Only this clears
        // it. Mutually exclusive with setting one, and the schema has no way to express both.
        $clearingAuthInfo = !empty($chg['clearAuthInfo']);
        $newAuthInfo = isset($chg['authInfo']) ? (string) $chg['authInfo'] : null;
        if ($clearingAuthInfo && $newAuthInfo !== null) {
            throw new ValidationException(
                "domain:update 'chg' cannot both set 'authInfo' and clear it — "
                . "drop one of 'authInfo' / 'clearAuthInfo'",
            );
        }

        // Whether anything will go inside the block, rather than whether the caller passed the key:
        // 'clearAuthInfo' => false is a key that produces no child, and on its own it would open an
        // empty <domain:chg/>. A restore is the one case where the empty block is deliberate, and
        // RFC 3915 requires it — see above.
        $changing = $registrant !== null || $clearingAuthInfo || $newAuthInfo !== null;

        // DNSSEC delta (RFC 5910): rem (specific or all), add, chg maxSigLife. At least one of
        // them is required — 'secDNS' => [] must NOT emit a childless <secDNS:update/>, which the
        // server rejects with 2003 for what reads as a no-op, losing the DNSSEC change.
        $secDns = $options['secDNS'] ?? null;
        $hasSecDelta = is_array($secDns)
            && (!empty($secDns['remAll']) || !empty($secDns['rem']) || !empty($secDns['add']) || isset($secDns['maxSigLife']));

        // RFC 5731 section 3.2.5: "At least one <domain:add>, <domain:rem>, or <domain:chg> element
        // MUST be provided if the command is not being extended. All of these elements MAY be omitted
        // if an <update> extension is present."
        //
        // domain-1.0.xsd cannot express that, because it makes all three optional — which is why an
        // update that asks for nothing has always been schema-valid and has never been caught here. A
        // server answers it with 2003, or worse with 1000, and either way the change the caller
        // believes they made was never described. The commonest way to arrive here is not writing
        // update($name) on purpose: it is a delta assembled from variables where every one turned out
        // empty, or a status list that filtered down to nothing just above.
        $touchesObject = $changing || $restoring
            || $blocks['add']['ns'] !== [] || $blocks['add']['contacts'] !== [] || $blocks['add']['statuses'] !== []
            || $blocks['rem']['ns'] !== [] || $blocks['rem']['contacts'] !== [] || $blocks['rem']['statuses'] !== [];
        $extended = $restoring || $hasSecDelta || isset($options['fee']) || isset($options['license']);
        if (!$touchesObject && !$extended) {
            throw new ValidationException(
                'domain:update asks for nothing: RFC 5731 requires at least one of add, rem or chg '
                . 'unless the command carries an extension, so this frame describes no change and the '
                . 'registry has nothing to apply. Check whether the delta you assembled came out '
                . 'empty — a list of statuses holding only blanks filters down to nothing.',
            );
        }

        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::DOMAIN, 'domain:update');
        $frame->ns($update, Namespaces::DOMAIN, 'domain:name', $name);

        foreach (['add', 'rem'] as $op) {
            ['ns' => $nameservers, 'contacts' => $contacts, 'statuses' => $statuses] = $blocks[$op];
            // The block is opened only once something is known to go inside it. A spec that filters
            // down to nothing — a single blank status, a contact list that was empty — would otherwise
            // reach the wire as <domain:rem/>, and domain-1.0.xsd accepts that, because every child of
            // addRemType is optional. The server reads it as "remove nothing", answers 1000, and the
            // caller is told the removal they asked for succeeded.
            if ($nameservers === [] && $contacts === [] && $statuses === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::DOMAIN, "domain:{$op}");
            if ($nameservers !== []) {
                $this->appendNameservers($frame, $block, $nameservers);
            }
            foreach ($contacts as [$type, $handle]) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:contact', $handle, ['type' => $type]);
            }
            foreach ($statuses as $status) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:status', null, ['s' => $status]);
            }
        }

        if ($changing || $restoring) {
            $block = $frame->ns($update, Namespaces::DOMAIN, 'domain:chg');
            if ($registrant !== null) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:registrant', $registrant);
            }
            if ($clearingAuthInfo) {
                $ai = $frame->ns($block, Namespaces::DOMAIN, 'domain:authInfo');
                $frame->ns($ai, Namespaces::DOMAIN, 'domain:null');
            }
            if ($newAuthInfo !== null) {
                $ai = $frame->ns($block, Namespaces::DOMAIN, 'domain:authInfo');
                $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', $newAuthInfo);
            }
        }

        if ($restoring) {
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
                $frame->ns($chgSec, Namespaces::SECDNS, 'secDNS:maxSigLife', self::maxSigLife($secDns['maxSigLife'], 'domain:update'));
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
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:period', self::period($years, 'domain:renew'), ['unit' => 'y']);
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
        // The five operations are an epp-1.0.xsd enumeration, and the wrong verb is not a refusal the
        // registry can explain: a frame carrying op="renew" or op="accept" is never parsed, so the
        // answer is a bare 2001 and the transfer stays where it was. The deadline on an incoming
        // transfer runs while the caller reads it.
        $op = Options::oneOf($op, self::TRANSFER_OPS, "transfer 'op'", 'RFC 5730 transferOpType');
        $frame = $this->client->frame();
        $transfer = $frame->verb('transfer');
        $transfer->setAttribute('op', $op);
        $d = $frame->ns($transfer, Namespaces::DOMAIN, 'domain:transfer');
        $frame->ns($d, Namespaces::DOMAIN, 'domain:name', $name);
        // Zero years means "the term does not move", which is how a free-transfer zone states its policy
        // - and the wire expresses it by leaving the period out, because RFC 5731's periodType starts at
        // 1. Writing <domain:period>0</domain:period> instead builds a frame the schema refuses, and a
        // registry that demands 0 in its own error message accepts an absent period just the same.
        if ($years !== null && $years !== 0) {
            $frame->ns($d, Namespaces::DOMAIN, 'domain:period', self::period($years, 'domain:transfer'), ['unit' => 'y']);
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

        // Blanks are dropped before the block is opened, not while it is being filled: domain:hostObj
        // has minLength 1 and domain:hostAddr minLength 3, so one empty entry — a stray comma in a
        // form field, a trailing line in a config file — is a frame the schema refuses, and the
        // delegation that was spelled correctly goes with it. A list that held nothing BUT blanks must
        // then not open a childless <domain:ns/>, which is refused in its own right.
        $names = [];
        foreach ($nameservers as $host) {
            if (!is_array($host)) {
                $name = trim((string) $host);
                if ($name !== '') {
                    $names[] = $name;
                }

                continue;
            }
            // Inside the entry, not just around it. `nmae` for `name` left a hostAttr named nothing,
            // and `address` for `addresses` threw the glue away — either way the domain is delegated
            // to a nameserver the registry cannot resolve, behind a 1000.
            Options::check($host, self::GLUE_KEYS, 'a nameserver given with its glue');
            $name = trim((string) ($host['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $names[] = ['name' => $name, 'addresses' => Options::nonEmptyStrings($host['addresses'] ?? [])];
        }
        if ($names === []) {
            return;
        }

        $ns = $frame->ns($parent, Namespaces::DOMAIN, 'domain:ns');
        foreach ($names as $host) {
            if (!is_array($host)) {
                $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostObj', $host);

                continue;
            }
            $attr = $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostAttr');
            $frame->ns($attr, Namespaces::DOMAIN, 'domain:hostName', $host['name']);
            foreach ($host['addresses'] as $ip) {
                $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
                $frame->ns($attr, Namespaces::DOMAIN, 'domain:hostAddr', $ip, ['ip' => $version]);
            }
        }
    }

    /** Every key a DS record understands; keyData is the RFC 5910 nested form for server validation. */
    private const DS_KEYS = ['keyTag', 'alg', 'digestType', 'digest', 'keyData'];

    /** Every key a DNSKEY record understands. */
    private const KEY_KEYS = ['flags', 'protocol', 'alg', 'pubKey'];

    /** Every key a fee agreement understands. */
    private const FEE_KEYS = ['amount', 'currency'];

    /** Every key a nameserver given with its glue understands. */
    private const GLUE_KEYS = ['name', 'addresses'];

    /**
     * Append RFC 5910 dsData / keyData records to a secDNS block (create / add / rem).
     *
     * The two forms are ALTERNATIVES, not a pair. RFC 5910 sections 2 and 4: "<secDNS:dsData> and
     * <secDNS:keyData> MUST NOT be mixed, except for when <secDNS:keyData> is a child element of
     * <secDNS:dsData> for server validation", and secDNS-1.1.xsd writes that as an XSD choice in
     * dsOrKeyType and remType. So a block carrying one of each is refused as a frame — a bare 2001
     * naming no element — and the domain is left unsigned, or left signed with the keys it had while
     * the caller believes the roll went through. The nested form, dsRecordWithKey(), is the one legal
     * combination and is built below inside the DS record.
     */
    private function appendSecDnsRecords(Frame $frame, \DOMElement $parent, array $spec): void
    {
        if (!empty($spec['dsData']) && !empty($spec['keyData'])) {
            throw new ValidationException(
                'secDNS records must be all dsData or all keyData, not a mixture — RFC 5910 makes '
                . '<secDNS:dsData> and <secDNS:keyData> a choice. A DS record may carry the DNSKEY it '
                . "was computed from NESTED inside it, which is the one legal combination: give the "
                . "key as the record's 'keyData' (dsRecordWithKey() in the builder).",
            );
        }

        foreach ((array) ($spec['dsData'] ?? []) as $ds) {
            // Inside the record, not just around it. `key_tag` for `keyTag` was accepted and defaulted
            // to 0, and a DS record of zeros with an empty digest is SCHEMA-VALID - so the registry
            // answers 1000 and the domain is published with a signer that matches no key. Once the
            // parent zone carries it, every validating resolver answers SERVFAIL for the name.
            Options::check((array) $ds, self::DS_KEYS, 'secDNS dsData record');
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
        Options::check($key, self::KEY_KEYS, 'secDNS keyData record');
        $keyData = $frame->ns($parent, Namespaces::SECDNS, 'secDNS:keyData');
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:flags', (string) (int) ($key['flags'] ?? 257));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:protocol', (string) (int) ($key['protocol'] ?? 3));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:alg', (string) (int) ($key['alg'] ?? 0));
        $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:pubKey', (string) ($key['pubKey'] ?? ''));
    }
}

