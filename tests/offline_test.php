<?php

declare(strict_types=1);

/**
 * Offline self-test: exercises frame building and response parsing with a fake
 * in-memory transport — no server, no network. Run it with:
 *
 *     php sdk/tests/offline_test.php
 *
 * Deliberately plain syntax (no named args, no nullsafe operator) so it runs on the SDK's whole
 * supported range, PHP 8.0 and up.
 */

require __DIR__ . '/../autoload.php';

use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\CommandException;
use EppTools\Namespaces;
use EppTools\ResultCode;
use EppTools\Transport;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ok  " : " FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/** A transport that records what was written and replays queued responses. */
final class FakeTransport implements Transport
{
    /** @var string[] */
    public array $written = [];
    /** @var string[] */
    public array $queue = [];
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function writeFrame(string $xml): void
    {
        $this->written[] = $xml;
    }

    public function readFrame(): string
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeTransport: no queued response');
        }

        return (string) array_shift($this->queue);
    }

    public function close(): void
    {
        $this->open = false;
    }
}

/**
 * The extension namespaces of the fictional registry these fixtures simulate.
 *
 * They are NOT constants of the library, and there is no equivalent there to compare them against:
 * the library knows the RFC namespaces and discovers a registry's own from its <greeting>. So these
 * belong to the fixture, the way a hostname or a password in a fixture does.
 *
 * Deliberately a registry no version of this code has ever named. A fixture written with the URIs
 * the library used to hard-code would keep passing if discovery quietly regressed to a constant —
 * the strings would still line up — and would prove only that the code agrees with itself. Under a
 * URI that appears nowhere in src/, these tests can pass only by actually reading the greeting.
 */
const EXT_REGISTRY = 'http://registry.example/epp/registry-1.0';
const EXT_BALANCE  = 'http://registry.example/epp/balance-1.0';

/**
 * @param string[] $responses
 * @return array{0: Client, 1: FakeTransport}
 */
function makeClient(array $responses, string $password = 'secret', array $config = []): array
{
    $fake = new FakeTransport();
    $fake->queue = $responses;
    $config = Config::fromArray($config + ['host' => 'epp.example', 'clid' => 'EXAMPLE', 'password' => $password]);
    $client = new Client($config, $fake);

    return [$client, $fake];
}

/** Load a frame for inspection with the SDK prefixes registered. */
function xp(string $xml): DOMXPath
{
    $dom = new DOMDocument();
    if ($dom->loadXML($xml) === false) {
        throw new RuntimeException('written frame is not well-formed XML');
    }
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('e', Namespaces::EPP);
    $xpath->registerNamespace('domain', Namespaces::DOMAIN);
    $xpath->registerNamespace('contact', Namespaces::CONTACT);
    $xpath->registerNamespace('host', Namespaces::HOST);
    $xpath->registerNamespace('secDNS', Namespaces::SECDNS);
    $xpath->registerNamespace('rgp', Namespaces::RGP);
    $xpath->registerNamespace('registry', EXT_REGISTRY);
    $xpath->registerNamespace('fee', Namespaces::FEE);

    return $xpath;
}

/** First matching node's text, or null. */
function firstText(DOMXPath $xpath, string $query): ?string
{
    $node = $xpath->query($query)->item(0);

    return $node !== null ? $node->textContent : null;
}

$GREETING = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><greeting>'
    . '<svID>Registry EPP</svID><svDate>2026-06-15T00:00:00Z</svDate><svcMenu><version>1.0</version><lang>en</lang>'
    . '<objURI>urn:ietf:params:xml:ns:epp-1.0</objURI><objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>'
    . '<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI><objURI>urn:ietf:params:xml:ns:host-1.0</objURI>'
    . '<svcExtension><extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI><extURI>urn:ietf:params:xml:ns:rgp-1.0</extURI>'
    . '<extURI>http://registry.example/epp/registry-1.0</extURI><extURI>http://registry.example/epp/balance-1.0</extURI>'
    . '</svcExtension></svcMenu></greeting></epp>';

$OK = static function (int $code = 1000): string {
    return '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="' . $code . '"><msg>ok</msg></result><trID><svTRID>SRV-1</svTRID></trID></response></epp>';
};

echo "greeting + login\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$greeting = $client->connect();
check('greeting parsed', $greeting->isGreeting());
check('serviceObjUris has domain', in_array(Namespaces::DOMAIN, $greeting->serviceObjUris(), true));
check('serviceExtUris has the registry extension', in_array(EXT_REGISTRY, $greeting->serviceExtUris(), true));
$client->login();
$loginXp = xp($fake->written[0]);
check('login carries clID', firstText($loginXp, '//e:login/e:clID') === 'EXAMPLE');
check('login version 1.0', firstText($loginXp, '//e:options/e:version') === '1.0');
$objUris = [];
foreach ($loginXp->query('//e:svcs/e:objURI') as $n) {
    $objUris[] = $n->textContent;
}
check('login objURIs exclude epp base', !in_array(Namespaces::EPP, $objUris, true));
check('login objURIs include domain/contact/host', $objUris === [Namespaces::CONTACT, Namespaces::DOMAIN, Namespaces::HOST]);
check('login mirrors the registry extension back', $loginXp->query('//e:svcExtension/e:extURI[text()="' . EXT_REGISTRY . '"]')->length === 1);

echo "namespace discovery from the greeting\n";
[$client] = makeClient([$GREETING]);
$client->connect();
check('registry extension discovered', $client->registryExtUri() === EXT_REGISTRY);
check('balance extension discovered', $client->registryBalanceUri() === EXT_BALANCE);

// Discovery must key on the last segment and nothing else: a registry's URI can be any string, and
// the only part of it this library is entitled to assume is the extension's name.
$oddGreeting = str_replace(
    ['http://registry.example/epp/registry-1.0', 'http://registry.example/epp/balance-1.0'],
    ['https://epp.other.example/xml/schemas/registry-1.2', 'urn:example:other:balance'],
    $GREETING
);
[$client] = makeClient([$oddGreeting]);
$client->connect();
check('a differently-shaped registry URI is found', $client->registryExtUri() === 'https://epp.other.example/xml/schemas/registry-1.2');
check('a non-http registry URI is found too', $client->registryBalanceUri() === 'urn:example:other:balance');

// RFC extensions are skipped by prefix, and this is the case that makes it necessary: fee-1.0 is an
// IETF extension whose last segment would match a search for an extension named "fee".
$feeOnly = str_replace(
    '<extURI>http://registry.example/epp/registry-1.0</extURI><extURI>http://registry.example/epp/balance-1.0</extURI>',
    '<extURI>urn:ietf:params:xml:ns:epp:fee-1.0</extURI>',
    $GREETING
);
[$client] = makeClient([$feeOnly]);
$client->connect();
check('a registry advertising no extension of its own reports none', $client->registryExtUri() === null);
check('and no balance extension either', $client->registryBalanceUri() === null);

// Absence must be REPORTED, not guessed around. Sending an invented URI would not be rejected — an
// extension the server does not recognise is ignored — so the licence would silently not be set.
$threw = null;
try {
    $client->requireRegistryExtUri('domain:create with a licence');
} catch (\EppTools\Exception\ConfigException $e) {
    $threw = $e->getMessage();
}
check('asking for a missing extension throws', $threw !== null);
check('and the message says what was wanted', $threw !== null && str_contains($threw, 'domain:create with a licence'));
check('and lists what the server did advertise', $threw !== null && str_contains($threw, 'urn:ietf:params:xml:ns:epp:fee-1.0'));

// balance() has nothing to fall back on, so it must refuse rather than send a frame that cannot work.
$threwBal = null;
try {
    $client->balance();
} catch (\EppTools\Exception\ConfigException $e) {
    $threwBal = $e->getMessage();
}
check('balance() refuses when the server offers no balance extension', $threwBal !== null);

// The config override exists for a registry that names its extension something discovery cannot
// guess. It must win outright — including over a greeting that advertises a different URI.
[$client] = makeClient([$GREETING], 'secret', [
    'registryExtUri'     => 'urn:example:custom:registry',
    'registryBalanceUri' => 'urn:example:custom:balance',
]);
$client->connect();
check('a configured registry URI overrides the greeting', $client->registryExtUri() === 'urn:example:custom:registry');
check('a configured balance URI overrides the greeting', $client->registryBalanceUri() === 'urn:example:custom:balance');

// Before connect() there is no greeting. Discovery must return null rather than fail, so that a
// caller who set the URIs in config can work without ever reading one.
$noGreeting = new Client(Config::fromArray(['host' => 'h', 'clid' => 'EXAMPLE', 'password' => 'secret']), new FakeTransport());
check('no greeting read yet discovers nothing', $noGreeting->registryExtUri() === null);

echo "login password rotation (newPW)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->login('BrandNewPass1');
$lx = xp($fake->written[0]);
check('login carries newPW', firstText($lx, '//e:login/e:newPW') === 'BrandNewPass1');

echo "domain:check + availability\n";
$checkResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">free.com.ua</domain:name></domain:cd>'
    . '<domain:cd><domain:name avail="0">taken.com.ua</domain:name><domain:reason>in use</domain:reason></domain:cd>'
    . '</domain:chkData></resData><trID><svTRID>SRV-2</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $checkResp]);
$client->connect();
$resp = $client->domain()->check(['free.com.ua', 'taken.com.ua']);
$avail = $resp->availability();
check('check avail: free=true', ($avail['free.com.ua'] ?? null) === true);
check('check avail: taken=false', ($avail['taken.com.ua'] ?? null) === false);

// A fee rider rides along with EVERY check, and the registry answers an
// unserved zone / non-UAH request with avail="0" on the <fee:cd> BLOCK, whose children are
// fee:objID + fee:reason. Keying on "any element with @avail" turned that into a junk entry
// ("bad.zzZone is not served" => false) sitting next to the real names.
$feeNoisyXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">free.com.ua</domain:name></domain:cd>'
    . '</domain:chkData></resData>'
    . '<extension><fee:chkData xmlns:fee="urn:ietf:params:xml:ns:epp:fee-1.0">'
    . '<fee:cd avail="0"><fee:objID>bad.zz</fee:objID><fee:reason>Zone is not served</fee:reason></fee:cd>'
    . '</fee:chkData></extension>'
    . '</response></epp>';
$noisy = \EppTools\Response::fromXml($feeNoisyXml)->availability();
check('availability ignores fee:cd', count($noisy) === 1 && ($noisy['free.com.ua'] ?? null) === true);
$checkXp = xp($fake->written[0]);
check('check frame has 2 domain:name', $checkXp->query('//domain:check/domain:name')->length === 2);

echo "domain:create with licence + secDNS\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('brand.ua', [
    'years' => 2,
    'registrant' => 'C1',
    'contacts' => ['admin' => 'C1', 'tech' => 'C2'],
    'nameservers' => ['ns1.brand.ua', 'ns2.brand.ua'],
    'authInfo' => 'Sup3r&Secret<>',
    'license' => 'TM-12345',
    'secDNS' => ['maxSigLife' => 604800, 'dsData' => [['keyTag' => 12345, 'alg' => 8, 'digestType' => 2, 'digest' => 'ABCDEF']]],
]);
$cx = xp($fake->written[0]); // also proves the frame is well-formed despite & < > in authInfo
check('create name', firstText($cx, '//domain:create/domain:name') === 'brand.ua');
check('create period unit=y', firstText($cx, '//domain:period[@unit="y"]') === '2');
check('create hostObj x2', $cx->query('//domain:ns/domain:hostObj')->length === 2);
check('create contact type=admin', firstText($cx, '//domain:contact[@type="admin"]') === 'C1');
check('create authInfo escaped round-trip', firstText($cx, '//domain:authInfo/domain:pw') === 'Sup3r&Secret<>');
check('create licence wrapper registry:create>license', firstText($cx, '//e:extension/registry:create/registry:license') === 'TM-12345');
check('create secDNS dsData keyTag', firstText($cx, '//secDNS:create/secDNS:dsData/secDNS:keyTag') === '12345');
check('create secDNS maxSigLife', firstText($cx, '//secDNS:create/secDNS:maxSigLife') === '604800');

echo "domain:create/update with inline glue (hostAttr)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('glue.ua', [
    'years' => 1,
    'registrant' => 'C1',
    'nameservers' => [
        ['name' => 'ns1.glue.ua', 'addresses' => ['192.0.2.1', '2001:db8::1']],
        ['name' => 'ns2.glue.ua', 'addresses' => ['192.0.2.2']],
    ],
]);
$gx = xp($fake->written[0]);
check('glue hostAttr x2', $gx->query('//domain:ns/domain:hostAttr')->length === 2);
check('glue hostName', firstText($gx, '//domain:hostAttr[1]/domain:hostName') === 'ns1.glue.ua');
check('glue v4 addr tagged ip=v4', firstText($gx, '//domain:hostAttr[1]/domain:hostAddr[@ip="v4"]') === '192.0.2.1');
check('glue v6 addr tagged ip=v6', firstText($gx, '//domain:hostAttr[1]/domain:hostAddr[@ip="v6"]') === '2001:db8::1');
check('glue emits no hostObj', $gx->query('//domain:ns/domain:hostObj')->length === 0);

// A nameserver may be added to an existing domain with its glue, too.
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->update('glue.ua', ['add' => ['ns' => [['name' => 'ns3.glue.ua', 'addresses' => ['192.0.2.3']]]]]);
$gux = xp($fake->written[0]);
check('glue on update add', firstText($gux, '//domain:add/domain:ns/domain:hostAttr/domain:hostName') === 'ns3.glue.ua');

// RFC 5731 makes <domain:ns> a choice, so a mixture is refused here rather than at the registry.
$mixed = null;
try {
    [$client, $fake] = makeClient([$GREETING, $OK()]);
    $client->connect();
    $client->domain()->create('mix.ua', ['nameservers' => ['ns1.mix.ua', ['name' => 'ns2.mix.ua', 'addresses' => ['192.0.2.9']]]]);
} catch (\EppTools\Exception\ValidationException $e) {
    $mixed = $e->getMessage();
}
check('mixed hostObj + hostAttr refused', $mixed !== null && str_contains($mixed, 'all names or all name-with-glue'));

echo "domain create WITHOUT authInfo still emits the RFC-mandatory <authInfo><pw/>\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('noauth.ua', ['years' => 1, 'registrant' => 'C1', 'contacts' => ['admin' => 'C1', 'tech' => 'C2'], 'nameservers' => ['ns1.noauth.ua']]);
$nx = xp($fake->written[0]);
check('create without authInfo still emits <domain:authInfo>', $nx->query('//domain:create/domain:authInfo')->length === 1);
$noauthPw = firstText($nx, '//domain:authInfo/domain:pw');
check('create without authInfo emits an empty <domain:pw>', $noauthPw === null || $noauthPw === '');

echo "contact create WITHOUT email throws a clear client-side error (not an opaque server 2005)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$emailThrew = false;
try {
    $client->contact()->create('C9', ['name' => 'Jane', 'city' => 'Lviv', 'cc' => 'UA']); // email omitted
} catch (\EppTools\Exception\EppException $e) {
    // Inside the SDK hierarchy the README promises: a web caller catching EppException returns
    // its documented JSON error instead of an uncaught \InvalidArgumentException + HTTP 500.
    $emailThrew = $e instanceof \EppTools\Exception\ValidationException;
}
check('contact create without email throws ValidationException (an EppException)', $emailThrew);

echo "domain:update restore (rgp, no add/rem/chg)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->restore('redeem.com.ua');
$ux = xp($fake->written[0]);
check('restore rgp op=request', $ux->query('//e:extension/rgp:update/rgp:restore[@op="request"]')->length === 1);
check('restore has no domain:chg', $ux->query('//domain:chg')->length === 0);
check('restore has no domain:add', $ux->query('//domain:add')->length === 0);

echo "domain:renew accepts an exDate timestamp for curExpDate\n";
// The value that reaches a caller's hands is <exDate>, an xs:dateTime; the value the wire wants is
// <curExpDate>, an xs:date. Passing the first straight to renew() is the obvious thing to write,
// and before this it produced a 2105 whose message mentions neither element.
[$client, $fake] = makeClient([$GREETING, $OK(), $OK(), $OK(), $OK()]);
$client->connect();
$client->domain()->renew('example.com.ua', '2027-01-15', 1);
check('a plain date is sent unchanged', firstText(xp($fake->written[0]), '//domain:curExpDate') === '2027-01-15');

$client->domain()->renew('example.com.ua', '2027-01-15T09:15:00.0Z', 1);
check('a full timestamp is reduced to its date', firstText(xp($fake->written[1]), '//domain:curExpDate') === '2027-01-15');

$client->domain()->renew('example.com.ua', '2027-01-15T23:30:00.0Z', 1);
check('and the date is the one the server wrote, never a local-timezone shift of it',
    firstText(xp($fake->written[2]), '//domain:curExpDate') === '2027-01-15');

$client->domain()->renew('example.com.ua', 'not-a-date', 1);
check('an unrecognised value goes to the server unchanged',
    firstText(xp($fake->written[3]), '//domain:curExpDate') === 'not-a-date');

echo "error handling\n";
[$client, $fake] = makeClient([$GREETING, $OK(2303)]);
$client->connect();
$threw = false;
try {
    $client->domain()->info('nope.com.ua');
} catch (CommandException $e) {
    $threw = ($e->eppCode === 2303);
}
check('2303 throws CommandException with eppCode', $threw);

[$client, $fake] = makeClient([$GREETING, $OK(2303)]);
$client->connect();
$client->throwOnFailure(false);
$resp = $client->domain()->info('nope.com.ua');
check('throwOnFailure(false): returns Response', $resp->code() === 2303 && !$resp->isSuccess());

echo "domain:update secDNS (add / rem-all / maxSigLife)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->update('dnssec.ua', [
    'secDNS' => [
        'add' => ['dsData' => [['keyTag' => 1, 'alg' => 8, 'digestType' => 2, 'digest' => 'AA']]],
        'remAll' => true,
        'maxSigLife' => 1209600,
    ],
]);
$sx = xp($fake->written[0]);
check('secDNS:update rem all=true', firstText($sx, '//secDNS:update/secDNS:rem/secDNS:all') === 'true');
check('secDNS:update add dsData keyTag', firstText($sx, '//secDNS:update/secDNS:add/secDNS:dsData/secDNS:keyTag') === '1');
check('secDNS:update chg maxSigLife', firstText($sx, '//secDNS:update/secDNS:chg/secDNS:maxSigLife') === '1209600');

echo "poll messageId/count + ack\n";
$pollResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1301"><msg>ack to dequeue</msg></result>'
    . '<msgQ count="3" id="12"><qDate>2026-06-15T00:00:00Z</qDate><msg>Domain transferred</msg></msgQ>'
    . '<trID><svTRID>SRV-9</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $pollResp, $OK()]);
$client->connect();
$poll = $client->poll()->request();
check('poll messageId', $poll->messageId() === '12');
check('poll messageCount', $poll->messageCount() === 3);
$client->poll()->ack($poll->messageId());
$ax = xp($fake->written[1]);
check('pollAck carries msgID', $ax->query('//e:poll[@op="ack"][@msgID="12"]')->length === 1);

echo "poll panData — the outcome of an offline operation\n";
// A deferred command reports back this way: you sent domain:create, got 1001 and an svTRID, and
// the answer arrives later as a poll message. The <result code="1301"> means "here is a message",
// NOT "your operation succeeded" — paResult is the only thing that says that, and reading the
// result code instead makes every poll answer look like a success.
$panResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1301"><msg>ack to dequeue</msg></result>'
    . '<msgQ count="1" id="11"><qDate>1970-01-01T00:00:12Z</qDate><msg>Domain registered</msg></msgQ>'
    . '<resData><domain:panData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name paResult="1">example.com.ua</domain:name>'
    . '<domain:paTRID><clTRID>my-create-1</clTRID><svTRID>SRV-19700101000000-1-00042</svTRID></domain:paTRID>'
    . '<domain:paDate>1970-01-01T00:00:12Z</domain:paDate>'
    . '</domain:panData></resData>'
    . '<trID><svTRID>SRV-9</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $panResp]);
$client->connect();
$pan = $client->poll()->request()->pendingActionData();
check('panData object', ($pan['object'] ?? '') === 'example.com.ua');
check('panData success from paResult', ($pan['success'] ?? null) === true);
// The svTRID of the ORIGINAL command — this is how a client knows WHICH of its pending operations
// the message is about. Poll is a queue; it is not necessarily the most recent one.
check('panData original svTRID', ($pan['svTRID'] ?? '') === 'SRV-19700101000000-1-00042');
check('panData original clTRID', ($pan['clTRID'] ?? '') === 'my-create-1');
check('panData paDate', ($pan['date'] ?? '') === '1970-01-01T00:00:12Z');

// paResult="0" is a REFUSAL, and the response code is still 1301.
$panFail = str_replace('paResult="1"', 'paResult="0"', $panResp);
[$client, $fake] = makeClient([$GREETING, $panFail]);
$client->connect();
check('panData failure from paResult=0', $client->poll()->request()->pendingActionData()['success'] === false);

// An ordinary notice has no panData at all: null, not an empty array pretending to be an outcome.
[$client, $fake] = makeClient([$GREETING, $pollResp]);
$client->connect();
check('no panData on a plain notice', $client->poll()->request()->pendingActionData() === null);

// contact:panData must work too — the accessor matches by local name across every object
// namespace, so binding to domain-1.0 would have returned null on a contact transfer.
$panContact = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1301"><msg>ack to dequeue</msg></result>'
    . '<msgQ count="1" id="12"><qDate>1970-01-01T00:00:12Z</qDate><msg>Contact transferred</msg></msgQ>'
    . '<resData><contact:panData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">'
    . '<contact:id paResult="true">CH-151</contact:id>'
    . '<contact:paTRID><clTRID>my-xfer-1</clTRID><svTRID>SRV-19700101000000-1-00043</svTRID></contact:paTRID>'
    . '<contact:paDate>1970-01-01T00:00:12Z</contact:paDate>'
    . '</contact:panData></resData>'
    . '<trID><svTRID>SRV-9</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $panContact]);
$client->connect();
$cpan = $client->poll()->request()->pendingActionData();
check('contact panData id', ($cpan['object'] ?? '') === 'CH-151');
check('paResult="true" is also success', ($cpan['success'] ?? null) === true);

echo "info statuses\n";
$infoResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example3.com.ua</domain:name><domain:status s="ok"/><domain:status s="clientHold"/>'
    . '<domain:exDate>2027-01-15T00:00:00+02:00</domain:exDate></domain:infData></resData>'
    . '<trID><svTRID>SRV-7</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $infoResp]);
$client->connect();
$info = $client->domain()->info('example3.com.ua');
check('statuses from @s', $info->statuses() === ['ok', 'clientHold']);
check('value exDate', $info->value('exDate') === '2027-01-15T00:00:00+02:00');

echo "error reasons + ResultCode\n";
$errResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="2306"><msg>Policy error</msg><extValue><value>x.closed.ua</value>'
    . '<reason>Zone is closed for new registrations</reason></extValue></result>'
    . '<trID><svTRID>SRV-8</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $errResp]);
$client->connect();
$client->throwOnFailure(false);
$r = $client->domain()->create('x.closed.ua', ['years' => 1]);
check('ResultCode constant matches', $r->code() === ResultCode::PARAMETER_VALUE_POLICY_ERROR);
check('errorReasons parsed', $r->errorReasons() === ['Zone is closed for new registrations']);

echo "contact create: postalInfo (int+loc) + disclose\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->create('c1', [
    'postalInfos' => [
        ['type' => 'int', 'name' => 'ACME', 'street' => ['1 St'], 'city' => 'Kyiv', 'cc' => 'UA'],
        ['type' => 'loc', 'name' => "\u{0410}\u{041A}\u{041C}\u{0415}", 'city' => "\u{041A}\u{0438}\u{0457}\u{0432}", 'cc' => 'UA'],
    ],
    'email' => 'contact@example.com',
    'authInfo' => 'pw',
    'disclose' => ['flag' => false, 'addr' => ['int'], 'voice' => true, 'email' => true],
]);
$kx = xp($fake->written[0]);
check('contact 2 postalInfo blocks', $kx->query('//contact:create/contact:postalInfo')->length === 2);
check('contact postalInfo loc name', firstText($kx, '//contact:postalInfo[@type="loc"]/contact:name') === "\u{0410}\u{041A}\u{041C}\u{0415}");
check('contact disclose flag=0', $kx->query('//contact:disclose[@flag="0"]')->length === 1);
check('contact disclose addr type=int', $kx->query('//contact:disclose/contact:addr[@type="int"]')->length === 1);
check('contact disclose voice flag present', $kx->query('//contact:disclose/contact:voice')->length === 1);

// Removing an organisation is expressed by an EMPTY element, and the difference between "empty" and
// "absent" is the whole mechanism: <contact:org/> means take it away, no element at all means leave
// it alone. Get that backwards in either direction and it fails silently — an omitted clear leaves a
// former organisation in the public WHOIS, while a phantom clear wipes one that was never touched.
echo "contact update: an EMPTY org removes it, an ABSENT org says nothing\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', ['chg' => ['postalInfo' => ['type' => 'loc', 'org' => '', 'city' => 'Kyiv', 'cc' => 'UA']]]);
$kx = xp($fake->written[0]);
check('org emitted for a clear', $kx->query('//contact:chg/contact:postalInfo/contact:org')->length === 1);
check('and it is empty', firstText($kx, '//contact:chg/contact:postalInfo/contact:org') === '');

[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', ['chg' => ['postalInfo' => ['type' => 'loc', 'city' => 'Lviv', 'cc' => 'UA']]]);
$kx = xp($fake->written[0]);
check('no org element when the caller never mentioned it', $kx->query('//contact:chg/contact:postalInfo/contact:org')->length === 0);

// On a create there is nothing to remove, so an empty org is simply not an element.
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->create('c1', [
    'postalInfos' => [['type' => 'int', 'name' => 'ACME', 'org' => '', 'city' => 'Kyiv', 'cc' => 'UA']],
    'email' => 'contact@example.com', 'authInfo' => 'pw',
]);
$kx = xp($fake->written[0]);
check('create never emits an empty org', $kx->query('//contact:create/contact:postalInfo/contact:org')->length === 0);

echo "contact create: the reserved id asks the registry to mint the handle\n";
$creData = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>Command completed successfully</msg></result>'
    . '<resData><contact:creData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">'
    . '<contact:id>C0000042-EXAMPLE</contact:id><contact:crDate>2026-08-16T10:00:00.0Z</contact:crDate>'
    . '</contact:creData></resData><trID><svTRID>SRV-1</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $creData]);
$client->connect();
$minted = $client->contact()->createAuto([
    'name' => 'ACME', 'city' => 'Kyiv', 'cc' => 'UA', 'email' => 'contact@example.com',
]);
$ax = xp($fake->written[0]);
check('reserved id sent verbatim', firstText($ax, '//contact:create/contact:id') === 'autonic');
check('reserved id constant', \EppTools\Command\Contact::AUTO_ID === 'autonic');
// The minted handle arrives in creData and nowhere else, so objectName() must read the id — not
// the person's postal name, which also sits under a <name> element in a contact response.
check('minted handle read back from creData', $minted->objectName() === 'C0000042-EXAMPLE');

echo "contact update: chg postalInfo + disclose\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', [
    'chg' => [
        'postalInfo' => ['type' => 'int', 'name' => 'New Name', 'city' => 'Lviv', 'cc' => 'UA'],
        'email' => 'new-contact@example.com',
        'disclose' => ['flag' => true, 'email' => true],
    ],
]);
$ucx = xp($fake->written[0]);
check('contact chg postalInfo name', firstText($ucx, '//contact:chg/contact:postalInfo/contact:name') === 'New Name');
check('contact chg disclose flag=1', $ucx->query('//contact:chg/contact:disclose[@flag="1"]')->length === 1);

echo "contact update: multiple statuses collapse into a single add/rem block\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', [
    'addStatuses' => ['clientUpdateProhibited', 'clientDeleteProhibited'],
    'remStatuses' => ['clientTransferProhibited', 'clientUpdateProhibited'],
]);
$mcx = xp($fake->written[0]);
check('contact update: single add wrapper', $mcx->query('//contact:add')->length === 1);
check('contact update: both statuses inside add', $mcx->query('//contact:add/contact:status')->length === 2);
check('contact update: single rem wrapper', $mcx->query('//contact:rem')->length === 1);
check('contact update: both statuses inside rem', $mcx->query('//contact:rem/contact:status')->length === 2);

echo "domain create: empty secDNS array emits no childless secDNS:create\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('nosec.ua', [
    'years' => 1, 'registrant' => 'REG1', 'contacts' => ['admin' => 'A1', 'tech' => 'T1'],
    'nameservers' => ['ns1.example.net'], 'secDNS' => [],
]);
$nsx = xp($fake->written[0]);
check('empty secDNS -> no secDNS:create element', $nsx->query('//secDNS:create')->length === 0);

echo "config guards (fail fast, no network)\n";
$badPw = new Client(Config::fromArray(['host' => 'h', 'clid' => 'SRV-1', 'password' => '']), $fakePw = new FakeTransport());
$fakePw->queue = [$GREETING];
$badPw->connect();
$threwPw = false;
try {
    $badPw->login();
} catch (\EppTools\Exception\ConfigException $e) {
    $threwPw = true;
}
check('empty password -> ConfigException', $threwPw);
check('no login frame sent', $fakePw->written === []);

$badHost = new Client(Config::fromArray(['host' => '', 'clid' => 'x', 'password' => 'y']), new FakeTransport());
$threwHost = false;
try {
    $badHost->connect();
} catch (\EppTools\Exception\ConfigException $e) {
    $threwHost = true;
}
check('empty host -> ConfigException', $threwHost);

echo "log redaction\n";
[$client] = makeClient([]);
$redact = new ReflectionMethod(Client::class, 'redact');
if (\PHP_VERSION_ID < 80100) {
    $redact->setAccessible(true); // required before 8.1; a no-op (and deprecated on 8.5) after
}
$masked = $redact->invoke($client, '<pw>topsecret</pw><domain:pw>auth123</domain:pw><domain:name>keep.ua</domain:name>');
check('pw masked', strpos($masked, 'topsecret') === false && strpos($masked, 'auth123') === false);
check('non-secret kept', strpos($masked, 'keep.ua') !== false);

echo "response accessors: balance / price / licence / rgp / lang\n";

$balanceXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg lang="uk">Команду виконано успішно</msg></result>'
    . '<resData><balance:infData xmlns:balance="http://registry.example/epp/balance-1.0">'
    . '<balance:creditLimit>0.00</balance:creditLimit><balance:balance>1234.56</balance:balance>'
    . '<balance:availableCredit>1234.56</balance:availableCredit></balance:infData></resData>'
    . '<trID><svTRID>SRV-2</svTRID></trID></response></epp>';
$bal = \EppTools\Response::fromXml($balanceXml);
$b = $bal->balance();
check('balance() creditLimit', $b !== null && $b['creditLimit'] === '0.00');
check('balance() balance', $b !== null && $b['balance'] === '1234.56');
check('balance() availableCredit', $b !== null && $b['availableCredit'] === '1234.56');
check('messageLang() reads uk', $bal->messageLang() === 'uk');

$infoXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg lang="en">Command completed successfully</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:status s="ok"/></domain:infData></resData>'
    . '<extension>'
    . '<registry:infData xmlns:registry="http://registry.example/epp/registry-1.0"><registry:license>TM-123</registry:license></registry:infData>'
    . '<registry:priceData xmlns:registry="http://registry.example/epp/registry-1.0" channel="7">'
    . '<registry:price operation="renewal" currency="UAH">180.00</registry:price>'
    . '<registry:price operation="restore" currency="UAH">1200.00</registry:price></registry:priceData>'
    . '<registry:registrar xmlns:registry="http://registry.example/epp/registry-1.0">EXAMPLE</registry:registrar>'
    . '<rgp:infData xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0"><rgp:rgpStatus s="redemptionPeriod"/></rgp:infData>'
    . '</extension><trID><svTRID>SRV-3</svTRID></trID></response></epp>';
$info = \EppTools\Response::fromXml($infoXml);
check('license() reads the .ua licence', $info->license() === 'TM-123');
$prices = $info->prices();
check('prices() renewal value', isset($prices['renewal']) && $prices['renewal']['value'] === '180.00');
check('prices() renewal currency', isset($prices['renewal']) && $prices['renewal']['currency'] === 'UAH');
check('prices() restore value', isset($prices['restore']) && $prices['restore']['value'] === '1200.00');
// The prices belong to a channel; without its id they cannot be matched to a catalogue row, and a
// domain kept on an older channel prices differently from a new registration in the same zone.
check('priceChannel() reads the channel the prices belong to', $info->priceChannel() === '7');
// sponsor() is the account; this is the handle the registry itself publishes as the registrar.
check('registrarOfRecord() reads the registry-side handle', $info->registrarOfRecord() === 'EXAMPLE');
check('rgpStatus() reads redemptionPeriod', $info->rgpStatus() === ['redemptionPeriod']);
check('balance() null on a non-balance response', $info->balance() === null);

$plainInfo = \EppTools\Response::fromXml(
    '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result><resData>'
    . '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>plain.com.ua</domain:name>'
    . '</domain:infData></resData><trID><svTRID>SRV-4</svTRID></trID></response></epp>'
);
check('priceChannel() is null when no price data came back', $plainInfo->priceChannel() === null);
check('registrarOfRecord() is null when the registry sent none', $plainInfo->registrarOfRecord() === null);

$trnXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1001"><msg>ok</msg></result>'
    . '<resData><domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:trStatus>pending</domain:trStatus></domain:trnData></resData>'
    . '<trID><svTRID>SRV-4</svTRID></trID></response></epp>';
check('transferStatus() reads pending', \EppTools\Response::fromXml($trnXml)->transferStatus() === 'pending');

echo "response accessors: secDNS read-back\n";
$secXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>signed.com.ua</domain:name></domain:infData></resData>'
    . '<extension><secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">'
    . '<secDNS:dsData><secDNS:keyTag>12345</secDNS:keyTag><secDNS:alg>13</secDNS:alg>'
    . '<secDNS:digestType>2</secDNS:digestType><secDNS:digest>ABCDEF0123</secDNS:digest></secDNS:dsData>'
    . '<secDNS:keyData><secDNS:flags>257</secDNS:flags><secDNS:protocol>3</secDNS:protocol>'
    . '<secDNS:alg>13</secDNS:alg><secDNS:pubKey>AwEAAb</secDNS:pubKey></secDNS:keyData>'
    . '</secDNS:infData></extension><trID><svTRID>SRV-5</svTRID></trID></response></epp>';
$sec = \EppTools\Response::fromXml($secXml);
$dsr = $sec->dsRecords();
check('dsRecords() count', count($dsr) === 1);
check('dsRecords() keyTag (int)', isset($dsr[0]) && $dsr[0]['keyTag'] === 12345);
check('dsRecords() digestType (int)', isset($dsr[0]) && $dsr[0]['digestType'] === 2);
check('dsRecords() digest', isset($dsr[0]) && $dsr[0]['digest'] === 'ABCDEF0123');
$kr = $sec->keyRecords();
check('keyRecords() flags (int)', isset($kr[0]) && $kr[0]['flags'] === 257);
check('keyRecords() pubKey', isset($kr[0]) && $kr[0]['pubKey'] === 'AwEAAb');
check('isSigned() true when signed', $sec->isSigned() === true);
check('isSigned() false on a non-DNSSEC info', $info->isSigned() === false);

echo "domain info hosts=sub\n";
[$clientHS, $fakeHS] = makeClient([$GREETING, $OK(), $OK()]);
$clientHS->connect();
$clientHS->login();
$clientHS->domain()->info('example.com.ua', null, 'sub');
$hsFrame = xp(end($fakeHS->written));
$hostsAttr = $hsFrame->query('//domain:info/domain:name/@hosts')->item(0);
check('info hosts=sub attribute', $hostsAttr !== null && $hostsAttr->nodeValue === 'sub');

echo "RFC 8748 fee: check request + create agreement (frame building)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->check(['prem.com.ua'], ['create' => 1, 'renew' => 2]);
$fcx = xp($fake->written[0]);
check('fee:check present', $fcx->query('//e:extension/fee:check')->length === 1);
check('fee:command create', $fcx->query('//fee:check/fee:command[@name="create"]')->length === 1);
check('fee:period years for renew', firstText($fcx, '//fee:command[@name="renew"]/fee:period[@unit="y"]') === '2');

[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('prem.com.ua', [
    'years' => 1, 'registrant' => 'C1', 'contacts' => ['admin' => 'C1', 'tech' => 'C2'],
    'nameservers' => ['ns1.example.net'], 'fee' => ['amount' => '500.00', 'currency' => 'UAH'],
]);
$fcr = xp($fake->written[0]);
check('fee:create agreement amount', firstText($fcr, '//e:extension/fee:create/fee:fee') === '500.00');
check('fee:create agreement currency', firstText($fcr, '//fee:create/fee:currency') === 'UAH');

echo "RFC 8748 fee: response fees() + chargedFee() (response parsing)\n";
$feeChkXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">prem.com.ua</domain:name></domain:cd></domain:chkData></resData>'
    . '<extension><fee:chkData xmlns:fee="urn:ietf:params:xml:ns:epp:fee-1.0">'
    . '<fee:cd><fee:objID>prem.com.ua</fee:objID>'
    . '<fee:command name="create"><fee:period unit="y">1</fee:period><fee:fee>500.00</fee:fee></fee:command>'
    . '<fee:command name="renew"><fee:period unit="y">1</fee:period><fee:fee>450.00</fee:fee></fee:command>'
    . '</fee:cd></fee:chkData></extension><trID><svTRID>SRV-F1</svTRID></trID></response></epp>';
$feeResp = \EppTools\Response::fromXml($feeChkXml);
$fees = $feeResp->fees();
check('fees() has the checked name', isset($fees['prem.com.ua']));
check('fees() create price', ($fees['prem.com.ua']['commands']['create']['fee'] ?? null) === '500.00');
check('fees() renew price', ($fees['prem.com.ua']['commands']['renew']['fee'] ?? null) === '450.00');

$feeCreXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:creData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>prem.com.ua</domain:name>'
    . '<domain:crDate>2026-06-15T00:00:00Z</domain:crDate><domain:exDate>2027-06-15T00:00:00Z</domain:exDate></domain:creData></resData>'
    . '<extension><fee:creData xmlns:fee="urn:ietf:params:xml:ns:epp:fee-1.0">'
    . '<fee:currency>UAH</fee:currency><fee:fee>500.00</fee:fee></fee:creData></extension>'
    . '<trID><svTRID>SRV-F2</svTRID></trID></response></epp>';
$charged = \EppTools\Response::fromXml($feeCreXml)->chargedFee();
check('chargedFee() currency', $charged !== null && $charged['currency'] === 'UAH');
check('chargedFee() amount', $charged !== null && $charged['fee'] === '500.00');

echo "frame: toXml() is idempotent (exactly one clTRID, always last)\n";
$idem = \EppTools\Frame::command('T-1');
$idem->ns($idem->verb('check'), Namespaces::DOMAIN, 'domain:check');
$firstXml = $idem->toXml();
$secondXml = $idem->toXml();
check('a second toXml() returns the same frame', $firstXml === $secondXml);
$ix = xp($secondXml);
check('exactly one clTRID', $ix->query('//e:command/e:clTRID')->length === 1);
check('clTRID is the last child of <command>', $ix->query('//e:command/*[last()]')->item(0)->localName === 'clTRID');

echo "login: a password the <pw> schema type cannot carry fails fast\n";
// $GREETING advertises no loginSec extension, so 18 characters cannot be carried to this server.
[$client, $fake] = makeClient([$GREETING]);
$client->connect();
$longThrew = false;
try {
    $client->login('an-18-char-passwd!');   // 18 chars: pwType allows 6-16
} catch (\EppTools\Exception\ConfigException $e) {
    $longThrew = true;
}
check('18-char newPW -> ConfigException', $longThrew);
check('no login frame sent for an unusable password', $fake->written === []);

echo "login: RFC 8807 carries a password longer than the <pw> element allows\n";
// Same greeting plus the Login Security extension. The long password now travels in
// <loginSec:pw>, and <pw> carries the sentinel that points at it.
$GREETING_LOGINSEC = str_replace(
    '</svcExtension>',
    '<extURI>' . Namespaces::LOGINSEC . '</extURI></svcExtension>',
    $GREETING
);
[$lsClient, $lsFake] = makeClient([$GREETING_LOGINSEC, $OK()], str_repeat('p', 40));
$lsClient->login();
$lsXml = $lsFake->written[0] ?? '';
$lx = xp($lsXml);
check('<pw> carries the sentinel', trim((string) ($lx->query('//e:login/e:pw')->item(0)?->textContent ?? '')) === Namespaces::LOGINSEC_SENTINEL);
$lx->registerNamespace('ls', Namespaces::LOGINSEC);
check('the real password is in <loginSec:pw>', trim((string) ($lx->query('//ls:loginSec/ls:pw')->item(0)?->textContent ?? '')) === str_repeat('p', 40));
check('the extension is announced in <svcs>', str_contains($lsXml, '<extURI>' . Namespaces::LOGINSEC . '</extURI>'));
check('a userAgent identifies the client', $lx->query('//ls:userAgent/ls:app')->length === 1);

echo "login: a short password takes part in the extension without travelling in it\n";
// Participation and relocation are separate decisions. The block goes out so the server will
// return its security events — it sends those only to a client that sent the block — while the
// password itself stays in <pw>, because it fits there and the sentinel would point at nothing.
[$shortClient, $shortFake] = makeClient([$GREETING_LOGINSEC, $OK()]);
$shortClient->login();
$shortXml = $shortFake->written[0] ?? '';
$sx = xp($shortXml);
$sx->registerNamespace('ls', Namespaces::LOGINSEC);
check('<pw> carries the password itself', str_contains($shortXml, '<pw>secret</pw>'));
check('the block is sent so the server will answer with its events', $sx->query('//ls:loginSec')->length === 1);
check('but the password is NOT relocated into it', $sx->query('//ls:loginSec/ls:pw')->length === 0);
check('the userAgent names app, tech and os', $sx->query('//ls:userAgent/ls:app')->length === 1
    && $sx->query('//ls:userAgent/ls:tech')->length === 1
    && $sx->query('//ls:userAgent/ls:os')->length === 1);

echo "login: loginSecurity=false stays off the extension entirely\n";
[$offClient, $offFake] = makeClient([$GREETING_LOGINSEC, $OK()], config: ['loginSecurity' => false]);
$offClient->login();
$offXml = $offFake->written[0] ?? '';
check('no loginSec block when the caller opted out', !str_contains($offXml, 'loginSec:loginSec'));
check('<pw> still carries the password itself', str_contains($offXml, '<pw>secret</pw>'));

echo "login: the server's security events are readable\n";
$eventReply = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>Command completed successfully</msg></result>'
    . '<extension><loginSec:loginSecData xmlns:loginSec="' . Namespaces::LOGINSEC . '">'
    . '<loginSec:event type="certificate" level="warning" exDate="2026-09-15T00:00:00Z">'
    . 'Your client certificate expires in 30 day(s).</loginSec:event>'
    . '<loginSec:event type="cipher" name="AES128-SHA" level="warning">Weak cipher suite.</loginSec:event>'
    . '</loginSec:loginSecData></extension>'
    . '<trID><svTRID>SRV-1</svTRID></trID></response></epp>';
[$evClient, ] = makeClient([$GREETING_LOGINSEC, $eventReply]);
$events = $evClient->login()->securityEvents();
check('both events are read', count($events) === 2);
check('the certificate event keeps its expiry date', ($events[0]['exDate'] ?? '') === '2026-09-15T00:00:00Z');
check('the certificate event keeps its level', ($events[0]['level'] ?? '') === 'warning');
check('the event text is the human sentence', str_contains($events[0]['text'] ?? '', 'expires in 30 day(s)'));
check('the cipher event keeps the suite name', ($events[1]['name'] ?? '') === 'AES128-SHA');
check('a healthy login reports no events', $shortClient->greeting()?->securityEvents() === []);

// The same guard covers Config::$password itself — no network is touched.
$shortPw = new Client(Config::fromArray(['host' => 'h', 'clid' => 'SRV-1', 'password' => 'short']), new FakeTransport());
$shortThrew = false;
try {
    $shortPw->login();
} catch (\EppTools\Exception\ConfigException $e) {
    $shortThrew = true;
}
check('5-char Config::$password -> ConfigException', $shortThrew);

echo "connect: the first frame must be the <greeting>\n";
$notGreeting = new Client(Config::fromArray(['host' => 'h', 'clid' => 'SRV-1', 'password' => 'secret']), $fakeNg = new FakeTransport());
$fakeNg->queue = [$OK(2500)];   // a <response>, e.g. left over from a half-open session
$ngThrew = false;
try {
    $notGreeting->connect();
} catch (\EppTools\Exception\ConnectionException $e) {
    // Storing it silently downgraded login() to the DEFAULT service list.
    $ngThrew = strpos($e->getMessage(), 'not an EPP <greeting>') !== false;
}
check('a <response> as the first frame -> ConnectionException', $ngThrew);
check('greeting not remembered after the failure', $notGreeting->greeting() === null);

echo "host:update cannot rename (this registry ignores host:chg)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$renameThrew = false;
try {
    $client->host()->update('ns1.example.net', ['addAddresses' => ['192.0.2.9'], 'newName' => 'ns2.example.net']);
} catch (\EppTools\Exception\ValidationException $e) {
    $renameThrew = true;
}
check('newName -> ValidationException instead of a discarded host:chg', $renameThrew);
check('no rename frame sent', $fake->written === []);

echo "domain:update with an empty secDNS array emits no childless secDNS:update\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->update('nosec.ua', ['secDNS' => [], 'chg' => ['registrant' => 'C9']]);
$esx = xp($fake->written[0]);
check('empty secDNS -> no secDNS:update element', $esx->query('//secDNS:update')->length === 0);
check('the rest of the update still went out', firstText($esx, '//domain:chg/domain:registrant') === 'C9');

echo "log redaction covers a pw element that carries attributes\n";
[$client] = makeClient([]);
$redactAttr = new ReflectionMethod(Client::class, 'redact');
if (\PHP_VERSION_ID < 80100) {
    $redactAttr->setAccessible(true);
}
$maskedAttr = $redactAttr->invoke($client, '<domain:pw roid="D1-EXAMPLE">auth123</domain:pw>');
check('pw with attributes masked', strpos($maskedAttr, 'auth123') === false);

echo "fee: one operation can be priced at several periods in a single command\n";
// A price table is one round trip, not five. The registry prices every <fee:command> separately.
[$fClient, $fFake] = makeClient([$GREETING, $OK(), $OK()]);
$fClient->connect();
$fClient->domain()->check(['example1.com.ua'], fee: ['create' => [1, 2, 5], 'renew' => 1], currency: 'UAH');
$fx = xp($fFake->written[0]);
check('every period becomes its own fee:command', $fx->query('//fee:check/fee:command')->length === 4);
check('three of them are the same operation', $fx->query('//fee:command[@name="create"]')->length === 3);
$periods = [];
foreach ($fx->query('//fee:command/fee:period') as $n) {
    $periods[] = $n->textContent;
}
check('and the periods keep the order asked', $periods === ['1', '2', '5', '1']);
check('a named currency is carried', firstText($fx, '//fee:check/fee:currency') === 'UAH');
$capThrew = false;
try {
    $fClient->domain()->check(['example1.com.ua'], fee: ['create' => range(1, 21)]);
} catch (\EppTools\Exception\ValidationException $e) {
    $capThrew = true;
}
// The registry refuses a 21st entry; refusing locally names the problem instead of spending a call.
check('a query past the registry cap is refused before it is sent', $capThrew);

$feeReply = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result><resData>'
    . '<domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">example1.com.ua</domain:name></domain:cd></domain:chkData></resData>'
    . '<extension><fee:chkData xmlns:fee="urn:ietf:params:xml:ns:epp:fee-1.0"><fee:currency>UAH</fee:currency>'
    . '<fee:cd avail="1"><fee:objID>example1.com.ua</fee:objID>'
    . '<fee:command name="create"><fee:period unit="y">1</fee:period><fee:fee>100.00</fee:fee></fee:command>'
    . '<fee:command name="create"><fee:period unit="y">2</fee:period><fee:fee>190.00</fee:fee></fee:command>'
    . '<fee:command name="create"><fee:period unit="y">5</fee:period><fee:fee>450.00</fee:fee></fee:command>'
    . '<fee:command name="renew"><fee:period unit="y">1</fee:period><fee:fee>90.00</fee:fee></fee:command>'
    . '</fee:cd></fee:chkData></extension><trID><svTRID>X</svTRID></trID></response></epp>';
$fr = \EppTools\Response::fromXml($feeReply);
// Keyed by operation alone, three create quotes would collapse to one.
check('every quote survives the parse', count($fr->fees()['example1.com.ua']['periods']) === 4);
check('feeFor() reads one period exactly', $fr->feeFor('example1.com.ua', 'create', 5) === '450.00');
check('and a period nobody asked for is null', $fr->feeFor('example1.com.ua', 'create', 7) === null);
check('the commands map still answers for the first period', $fr->fees()['example1.com.ua']['commands']['create']['fee'] === '100.00');

echo "login: only 2200 means the credentials are wrong\n";
// A server refuses <login> for several reasons, and they need opposite responses. Calling them all
// an authentication failure sends the reader to rotate a password that was never the problem.
$refuse = static function (int $code, string $msg): string {
    return '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="' . $code . '"><msg>' . $msg . '</msg></result>'
        . '<trID><svTRID>X</svTRID></trID></response></epp>';
};
$loginError = static function (int $code) use ($GREETING, $refuse): object {
    [$client, $fake] = makeClient([$GREETING, $refuse($code, 'refused')]);
    $client->connect();
    try {
        $client->login();
    } catch (\EppTools\Exception\EppException $e) {
        return $e;
    }
    throw new RuntimeException('login did not fail');
};
check('2200 is an AuthenticationException', $loginError(2200) instanceof \EppTools\Exception\AuthenticationException);
// The session cap: the answer is to reconnect, not to change the password.
check('2502 (session limit) is a SessionException', $loginError(2502) instanceof \EppTools\Exception\SessionException);
check('2501 (server closing) is a SessionException', $loginError(2501) instanceof \EppTools\Exception\SessionException);
check('2307 is a plain CommandException, not an auth failure',
    !($loginError(2307) instanceof \EppTools\Exception\AuthenticationException));

echo "errors: a class exists where the right next step differs\n";
$errFor = static function (int $code) use ($GREETING): \EppTools\Exception\CommandException {
    $xml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="' . $code . '"><msg>refused</msg>'
        . '<extValue><value><domain:name xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">taken.com.ua</domain:name></value>'
        . '<reason lang="en">Already registered</reason></extValue></result>'
        . '<trID><svTRID>X</svTRID></trID></response></epp>';
    [$client] = makeClient([$GREETING, $xml]);
    $client->connect();
    try {
        $client->domain()->check(['taken.com.ua']);
    } catch (\EppTools\Exception\CommandException $e) {
        return $e;
    }
    throw new RuntimeException('no exception');
};
check('2104 is InsufficientFundsException', $errFor(2104) instanceof \EppTools\Exception\InsufficientFundsException);
check('2202 is AuthorizationException', $errFor(2202) instanceof \EppTools\Exception\AuthorizationException);
check('2302 is ObjectExistsException', $errFor(2302) instanceof \EppTools\Exception\ObjectExistsException);
check('2303 is ObjectDoesNotExistException', $errFor(2303) instanceof \EppTools\Exception\ObjectDoesNotExistException);
check('2305 is ObjectStatusException', $errFor(2305) instanceof \EppTools\Exception\ObjectStatusException);
check('2308 is PolicyException', $errFor(2308) instanceof \EppTools\Exception\PolicyException);
check('2502 is SessionException', $errFor(2502) instanceof \EppTools\Exception\SessionException);
check('2005 stays a plain CommandException', get_class($errFor(2005)) === \EppTools\Exception\CommandException::class);
// Retrying a 2302 cannot make the name free; retrying a 2104 cannot pay for it.
check('only the transient ones are retryable', $errFor(2400)->isRetryable() && $errFor(2502)->isRetryable()
    && !$errFor(2302)->isRetryable() && !$errFor(2104)->isRetryable());
check('the message names WHICH object was refused', str_ends_with($errFor(2302)->getMessage(), "('taken.com.ua')"));
check('subject() returns it too', $errFor(2302)->subject() === 'taken.com.ua');
check('reasons() carries the extra detail', in_array('Already registered', $errFor(2302)->reasons(), true));

echo "secDNS: a DS record can carry the DNSKEY it was computed from\n";
// RFC 5910 allows the nesting so a registry can verify the digest against the key. The element
// ORDER inside dsData is fixed by the schema, so the assertion is on structure, not just presence.
[$kClient, $kFake] = makeClient([$GREETING, $OK(), $OK()]);
$kClient->connect();
$kClient->domain()->createBuilder('example3.com.ua')->years(1)->registrant('C1')
    ->dsRecordWithKey(12345, 8, 2, str_repeat('d', 64), 257, 3, 8, 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyAhIiMkJSYnKCkqKywtLi8wMTIzNDU2Nzg5Ojs8PT4/QA==')->send();
$kx = xp($kFake->written[0]);
check('the keyData is INSIDE the dsData, not beside it', $kx->query('//secDNS:dsData/secDNS:keyData')->length === 1);
check('and the DS fields are still there', firstText($kx, '//secDNS:dsData/secDNS:keyTag') === '12345');
check('with the public key nested under it', firstText($kx, '//secDNS:dsData/secDNS:keyData/secDNS:pubKey') === 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyAhIiMkJSYnKCkqKywtLi8wMTIzNDU2Nzg5Ojs8PT4/QA==');
// A standalone keyData must still land beside the DS records, not inside one.
$kClient->domain()->create('y.com.ua', ['secDNS' => ['keyData' => [['flags' => 257, 'protocol' => 3, 'alg' => 8, 'pubKey' => 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyAhIiMkJSYnKCkqKywtLi8wMTIzNDU2Nzg5Ojs8PT4/QA==']]]]);
$ky = xp($kFake->written[1]);
check('a standalone keyData stays a sibling', $ky->query('//secDNS:create/secDNS:keyData')->length === 1
    && $ky->query('//secDNS:dsData')->length === 0);

echo "authInfo: clearing is not the same as emptying\n";
// After a leak this is the only operation that helps. An empty <pw/> stores the empty string, which
// the holder can still present — the domain stays exactly as movable as it was.
[$aClient, $aFake] = makeClient([$GREETING, $OK(), $OK(), $OK()]);
$aClient->connect();
$aClient->domain()->updateBuilder('example3.com.ua')->clearAuthInfo()->send();
check('clearAuthInfo() emits <domain:null/>', str_contains($aFake->written[0], '<domain:null/>'));
check('and no <pw> element at all', !str_contains($aFake->written[0], '<domain:pw>'));
$aClient->domain()->update('example3.com.ua', ['chg' => ['authInfo' => 'N3w-Pw']]);
check('an ordinary change still emits <pw>', str_contains($aFake->written[1], '<domain:pw>N3w-Pw</domain:pw>'));
$bothThrew = false;
try {
    $aClient->domain()->update('example3.com.ua', ['chg' => ['authInfo' => 'a', 'clearAuthInfo' => true]]);
} catch (\EppTools\Exception\ValidationException $e) {
    $bothThrew = true;
}
// The schema has one choice: a password, or nothing. Half-applying either would be worse.
check('setting and clearing at once is refused, not half-applied', $bothThrew);
// RFC 5733 has no nullable form for a contact, so the SDK must not offer one.
check('contact:update has no clearAuthInfo', !method_exists(\EppTools\Builder\ContactUpdateBuilder::class, 'clearAuthInfo'));

echo "poll drain: a notice is acknowledged only after it has been handled\n";
// An ack DELETES the notice at the registry. A loop that acks first and processes second loses
// every notice whose processing fails, with nothing left to retry from.
$notice = static function (string $id, string $text): string {
    return '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="1301"><msg>Command completed successfully; ack to dequeue</msg></result>'
        . '<msgQ count="2" id="' . $id . '"><qDate>2026-08-16T09:00:00Z</qDate>'
        . '<msg>' . $text . '</msg></msgQ>'
        . '<trID><svTRID>SRV-1</svTRID></trID></response></epp>';
};
$emptyQueue = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1300"><msg>Command completed successfully; no messages</msg></result>'
    . '<trID><svTRID>SRV-1</svTRID></trID></response></epp>';

[$pClient, $pFake] = makeClient([$GREETING, $notice('11', 'first'), $OK(), $notice('12', 'second'), $OK(), $emptyQueue]);
$pClient->connect();
$seen = [];
$count = $pClient->poll()->drain(static function (\EppTools\Response $n) use (&$seen): void {
    $seen[] = $n->queueMessage();
});
check('drain returns how many notices were handled', $count === 2);
check('and hands the NOTICE text to the callback, not the result banner', $seen === ['first', 'second']);
$acked = [];
foreach ($pFake->written as $sent) {
    if (preg_match('~msgID="(\d+)"~', $sent, $m) === 1) {
        $acked[] = $m[1];
    }
}
check('each notice is acked exactly once, in order', $acked === ['11', '12']);
check('it stops on the empty queue rather than looping', count($pFake->written) === 5);

// The property that matters: a failing handler must NOT destroy the notice.
[$fClient, $fFake] = makeClient([$GREETING, $notice('21', 'boom'), $OK(), $emptyQueue]);
$fClient->connect();
$threw = false;
try {
    $fClient->poll()->drain(static function (\EppTools\Response $n): void {
        throw new RuntimeException('handler failed');
    });
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'handler failed';
}
check('a failing handler surfaces its own exception', $threw);
$ackedAfterFailure = false;
foreach ($fFake->written as $sent) {
    if (str_contains($sent, 'msgID=')) {
        $ackedAfterFailure = true;
    }
}
check('and the notice is NOT acked, so nothing is lost', $ackedAfterFailure === false);

// A queue that fills faster than it drains would otherwise never let the call return.
[$lClient, $lFake] = makeClient([$GREETING, $notice('31', 'a'), $OK(), $notice('32', 'b'), $OK(), $notice('33', 'c'), $OK()]);
$lClient->connect();
$limited = $lClient->poll()->drain(static function (\EppTools\Response $n): void {
}, 2);
check('a limit stops the drain early', $limited === 2);

echo "builders: the fluent form and the array form are the same command\n";
// The whole design rests on send() being a thin façade over the ordinary method. Proved by
// comparing the FRAMES, not the option arrays: an equal array could still be assembled into a
// different frame, and it is the frame the registry sees. clTRID is stripped — it is unique per
// command by construction.
$frameOf = static function (callable $call) use ($GREETING, $OK): string {
    [$client, $fake] = makeClient([$GREETING, $OK(), $OK(), $OK()]);
    $client->connect();
    $call($client);

    return (string) preg_replace('~<clTRID>[^<]*</clTRID>~', '', $fake->written[0] ?? '');
};
$sameFrame = static function (string $label, callable $viaBuilder, callable $viaArray) use ($frameOf): void {
    check($label, $frameOf($viaBuilder) === $frameOf($viaArray));
};

$sameFrame(
    'domain:create built step by step matches the array call exactly',
    static fn (Client $c) => $c->domain()->createBuilder('example3.com.ua')
        ->years(2)->registrant('acme-01')
        ->adminContact('acme-01')->techContact('acme-ns1')->techContact('acme-ns2')
        ->nameserver('ns1.acme.example')->nameserver('ns2.acme.example')
        ->authInfo('D0main-Pw')->license('TM-1')
        ->dsRecord(12345, 8, 2, str_repeat('AB', 32))->maxSigLife(604800)
        ->maxFee('180.00', 'UAH')->send(),
    static fn (Client $c) => $c->domain()->create('example3.com.ua', [
        'years' => 2, 'registrant' => 'acme-01',
        'contacts' => ['admin' => ['acme-01'], 'tech' => ['acme-ns1', 'acme-ns2']],
        'nameservers' => ['ns1.acme.example', 'ns2.acme.example'],
        'authInfo' => 'D0main-Pw', 'license' => 'TM-1',
        'secDNS' => ['dsData' => [['keyTag' => 12345, 'alg' => 8, 'digestType' => 2, 'digest' => str_repeat('AB', 32)]], 'maxSigLife' => 604800],
        'fee' => ['amount' => '180.00', 'currency' => 'UAH'],
    ]),
);
$sameFrame(
    'domain:create with inline glue matches the array call exactly',
    static fn (Client $c) => $c->domain()->createBuilder('glue.com.ua')
        ->years(1)->registrant('acme-01')
        ->nameserverWithGlue('ns1.glue.com.ua', '192.0.2.1', '2001:db8::1')
        ->nameserverWithGlue('ns2.glue.com.ua', '192.0.2.2')
        ->send(),
    static fn (Client $c) => $c->domain()->create('glue.com.ua', [
        'years' => 1, 'registrant' => 'acme-01',
        'nameservers' => [
            ['name' => 'ns1.glue.com.ua', 'addresses' => ['192.0.2.1', '2001:db8::1']],
            ['name' => 'ns2.glue.com.ua', 'addresses' => ['192.0.2.2']],
        ],
    ]),
);
$sameFrame(
    'domain:update delta lands in the same add/rem/chg blocks',
    static fn (Client $c) => $c->domain()->updateBuilder('example3.com.ua')
        ->addNameserver('ns3.acme.example')->remNameserver('ns1.acme.example')
        ->addStatus('clientHold')->remStatus('clientTransferProhibited')
        ->addContact('tech', 'acme-ns9')
        ->changeRegistrant('acme-02')->changeAuthInfo('N3w-Pw')->send(),
    static fn (Client $c) => $c->domain()->update('example3.com.ua', [
        'add' => ['ns' => ['ns3.acme.example'], 'statuses' => ['clientHold'], 'contacts' => ['tech' => ['acme-ns9']]],
        'rem' => ['ns' => ['ns1.acme.example'], 'statuses' => ['clientTransferProhibited']],
        'chg' => ['registrant' => 'acme-02', 'authInfo' => 'N3w-Pw'],
    ]),
);
$sameFrame(
    'contact:create with both postal forms matches the array call',
    static fn (Client $c) => $c->contact()->createBuilder('acme-01', 'billing@acme.example')
        ->internationalAddress(name: 'ACME LLC', city: 'Kyiv', countryCode: 'UA', street: ['1 Main St'], org: 'ACME LLC', postalCode: '01001')
        ->localizedAddress(name: 'ТОВ АКМЕ', city: 'Київ', countryCode: 'UA')
        ->voice('+380.441234567')->authInfo('C0ntact-Pw')->withhold('voice', 'email')->send(),
    static fn (Client $c) => $c->contact()->create('acme-01', [
        'email' => 'billing@acme.example',
        'postalInfos' => [
            ['type' => 'int', 'name' => 'ACME LLC', 'city' => 'Kyiv', 'cc' => 'UA', 'street' => ['1 Main St'], 'org' => 'ACME LLC', 'pc' => '01001'],
            ['type' => 'loc', 'name' => 'ТОВ АКМЕ', 'city' => 'Київ', 'cc' => 'UA'],
        ],
        'voice' => '+380.441234567', 'authInfo' => 'C0ntact-Pw',
        'disclose' => ['flag' => false, 'voice' => true, 'email' => true],
    ]),
);
$sameFrame(
    'contact:update assembles the same chg block, statuses and disclosure',
    static fn (Client $c) => $c->contact()->updateBuilder('acme-01')
        ->changeEmail('new@acme.example')->changeVoice('+380.441234567')->changeFax('')
        ->changeInternationalAddress(name: 'ACME LLC', city: 'Lviv', countryCode: 'UA', org: '', postalCode: '79000')
        ->changeAuthInfo('N3w-C0ntact-Pw')->withhold('voice', 'email')
        ->addStatus('clientUpdateProhibited')->remStatus('clientDeleteProhibited')->send(),
    static fn (Client $c) => $c->contact()->update('acme-01', [
        'chg' => [
            'email' => 'new@acme.example', 'voice' => '+380.441234567', 'fax' => '',
            'postalInfo' => ['type' => 'int', 'name' => 'ACME LLC', 'city' => 'Lviv', 'cc' => 'UA', 'org' => '', 'pc' => '79000'],
            'authInfo' => 'N3w-C0ntact-Pw',
            'disclose' => ['flag' => false, 'voice' => true, 'email' => true],
        ],
        'addStatuses' => ['clientUpdateProhibited'],
        'remStatuses' => ['clientDeleteProhibited'],
    ]),
);
$sameFrame(
    'host:update addresses and statuses match the array call',
    static fn (Client $c) => $c->host()->updateBuilder('ns1.acme.example')
        ->addAddress('192.0.2.10')->addAddress('2001:db8::10')
        ->remAddress('192.0.2.9')->addStatus('clientUpdateProhibited')->send(),
    static fn (Client $c) => $c->host()->update('ns1.acme.example', [
        'addAddresses' => ['192.0.2.10', '2001:db8::10'],
        'remAddresses' => ['192.0.2.9'], 'addStatuses' => ['clientUpdateProhibited'],
    ]),
);

// A builder is a command that has not happened yet.
[$bClient, $bFake] = makeClient([$GREETING, $OK(), $OK()]);
$bClient->connect();
$pending = $bClient->domain()->createBuilder('example3.com.ua')->years(1)->registrant('C1');
check('building sends nothing', $bFake->written === []); // connect() reads the greeting, writes nothing
check('toOptions() shows what would be sent', $pending->toOptions() === ['years' => 1, 'registrant' => 'C1']);
$pending->send();
$reSent = false;
try {
    $pending->send();
} catch (\EppTools\Exception\ValidationException $e) {
    $reSent = true;
}
// Sending twice is two registrations and two charges, and the second is never what was meant.
check('a builder refuses to be sent twice', $reSent);

echo "errors: a bad argument is not the same as a bad configuration\n";
// The distinction has a consequence: a service answering HTTP maps one to the caller's 4xx and the
// other to its own 5xx. Sharing a class meant an operator's own misconfiguration could be reported
// to a customer as their mistake.
$argFails = static function (callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (\EppTools\Exception\ValidationException $e) {
        return true;
    }
};
[$vClient] = makeClient([$GREETING]);
check("a fee that is not a decimal", $argFails(static fn () => $vClient->domain()->createBuilder('x.ua')->maxFee('100,00')));
check('a disclose field that does not exist', $argFails(static fn () => $vClient->contact()->createBuilder('c1', 'contact@example.com')->withhold('passport')));
check('removing all DNSSEC and naming records at once', $argFails(static fn () => $vClient->domain()->updateBuilder('x.ua')->removeAllDnssec()->remDsRecord(1, 8, 2, 'AB')));
check('an unknown option key', $argFails(static fn () => $vClient->domain()->create('x.ua', ['yeras' => 1])));

echo "contact: which postal fields can be CLEARED is the schema's decision, not ours\n";
// contact-1.0.xsd: optPostalLineType (org, street, sp) and pcType have no minLength, so those clear
// by being sent empty. postalLineType (name, city) has minLength 1 and ccType is exactly two
// characters, so an empty one of those is schema-invalid — and an invalid frame comes back as a bare
// 2001 that names no element, the least useful error in EPP.
[$pClient, $pFake] = makeClient([$GREETING, $OK(), $OK()]);
$pClient->connect();

check(
    'clearing sp WITHOUT the required parts of <addr> is refused here, not by the server',
    $argFails(static fn () => $pClient->contact()->update('C-1', ['chg' => ['postalInfo' => ['type' => 'loc', 'sp' => '']]])),
);
check(
    'and the message names the part that is missing',
    (static function () use ($pClient): bool {
        try {
            $pClient->contact()->update('C-1', ['chg' => ['postalInfo' => ['type' => 'loc', 'sp' => '']]]);
        } catch (\EppTools\Exception\ValidationException $e) {
            return str_contains($e->getMessage(), 'city');
        }

        return false;
    })(),
);
check(
    'a name cannot be cleared at all — there is no empty postalLineType',
    $argFails(static fn () => $pClient->contact()->update('C-1', ['chg' => ['postalInfo' => ['type' => 'loc', 'name' => '', 'city' => 'Lviv', 'cc' => 'UA']]])),
);

// The whole point of the guard is that the CORRECT call still works and still clears.
$pClient->contact()->update('C-1', ['chg' => ['postalInfo' => [
    'type' => 'loc', 'sp' => '', 'city' => 'Lviv', 'cc' => 'UA',
]]]);
$px = xp($pFake->written[0]);
check('sp goes out as an empty element, which is what clears it', $px->query('//contact:addr/contact:sp')->length === 1
    && firstText($px, '//contact:addr/contact:sp') === '');
check('and the required parts travel with it', firstText($px, '//contact:addr/contact:city') === 'Lviv'
    && firstText($px, '//contact:addr/contact:cc') === 'UA');

// org is the field the documentation names first, and it needs no address at all.
$pClient->contact()->update('C-1', ['chg' => ['postalInfo' => ['type' => 'loc', 'org' => '']]]);
$ox = xp($pFake->written[1]);
check('clearing org alone sends no <addr> and needs no city', $ox->query('//contact:addr')->length === 0
    && $ox->query('//contact:postalInfo/contact:org')->length === 1);
check('ValidationException is still an EppException', is_subclass_of(\EppTools\Exception\ValidationException::class, \EppTools\Exception\EppException::class));
check('but NOT a ConfigException', !is_subclass_of(\EppTools\Exception\ValidationException::class, \EppTools\Exception\ConfigException::class));

echo "transport: a runaway frame length is TERMINAL, not just an exception\n";
// The four length bytes are already consumed when the check fires, so the stream sits at an unknown
// offset. Leaving it open means the NEXT command reads from the middle of this frame — the
// off-by-one across billable transforms that the length check exists to prevent. The node and
// Python transports both latch; PHP threw and stayed "connected".
$conn = new EppTools\Connection(Config::fromArray(['host' => 'h', 'clid' => 'SRV-1', 'password' => 'secret']));
$memory = fopen('php://memory', 'r+');
fwrite($memory, pack('N', 99_999_999) . str_repeat('x', 16));
rewind($memory);
$streamProp = new ReflectionProperty(EppTools\Connection::class, 'stream');
if (\PHP_VERSION_ID < 80100) {
    $streamProp->setAccessible(true);
}
$streamProp->setValue($conn, $memory);
check('the connection starts out open', $conn->isOpen() === true);
$lenThrew = false;
try {
    $conn->readFrame();
} catch (\EppTools\Exception\ConnectionException $e) {
    $lenThrew = str_contains($e->getMessage(), 'Invalid EPP frame length');
}
check('a runaway length prefix raises ConnectionException', $lenThrew);
check('and the connection is CLOSED, so no command can ride the desynchronised stream', $conn->isOpen() === false);

echo "extValue: a relocated RFC 9038 payload keeps its content\n";
// A container's recursive textContent fused the children into "120.00500.00" — a string that reads
// like a figure and is not one. The children must survive by NAME.
$relocated = EppTools\Response::fromXml(
    '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="2005"><msg>err</msg><extValue><value>'
    . '<balance:infData xmlns:balance="http://registry.example/epp/balance-1.0">'
    . '<balance:balance>120.00</balance:balance><balance:creditLimit>500.00</balance:creditLimit>'
    . '</balance:infData></value><reason lang="en">unhandled namespace</reason></extValue></result>'
    . '<trID><svTRID>X</svTRID></trID></response></epp>'
);
$ev = $relocated->extValues()[0];
check('a container carries no text of its own', $ev['text'] === '');
check('and its children survive by name', $ev['values'] === ['balance' => '120.00', 'creditLimit' => '500.00']);
check('the element and its namespace are reported', $ev['element'] === 'infData'
    && $ev['namespace'] === 'http://registry.example/epp/balance-1.0');
check('the payload can be re-parsed from xml', str_contains($ev['xml'], '120.00'));

// The ordinary case must not regress: a leaf still answers with its value.
$leaf = EppTools\Response::fromXml(
    '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="2005"><msg>err</msg><extValue><value>'
    . '<domain:name xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">bad..name</domain:name></value>'
    . '<reason lang="en">Invalid label</reason></extValue></result>'
    . '<trID><svTRID>X</svTRID></trID></response></epp>'
)->extValues()[0];
check('a leaf still reports which value was rejected', $leaf['text'] === 'bad..name');
check('and has no children', $leaf['values'] === []);
check('the reason and its language come through', $leaf['reason'] === 'Invalid label' && $leaf['lang'] === 'en');

echo "RFC 8807: the sentinel goes only in the element whose value was relocated\n";
// The sentinel means "the real value is in the matching loginSec element". Putting it in an element
// whose value was NOT relocated points the server at something that is not there — which is what a
// frame-wide flag did to every rotation across the 16-character boundary.
$LOGINSEC_GREETING = str_replace(
    '</svcExtension>',
    '<extURI>' . Namespaces::LOGINSEC . '</extURI></svcExtension>',
    $GREETING,
);
$SENTINEL = Namespaces::LOGINSEC_SENTINEL;
$LONG = str_repeat('a', 40);

$loginFrame = static function (string $password, ?string $newPassword) use ($LOGINSEC_GREETING, $OK): DOMXPath {
    [$client, $fake] = makeClient([$LOGINSEC_GREETING, $OK()], $password);
    $client->connect();
    $client->login($newPassword);

    $xpath = xp($fake->written[0]);
    $xpath->registerNamespace('loginSec', Namespaces::LOGINSEC);

    return $xpath;
};

// Short -> long: only newPW moves. pw must stay LITERAL, or the server is told to look in an
// extension element that was never emitted and the login is rejected.
$x = $loginFrame('short1', $LONG);
check('rotating short -> long keeps <pw> literal', firstText($x, '//e:login/e:pw') === 'short1');
check('and marks only <newPW> with the sentinel', firstText($x, '//e:login/e:newPW') === $SENTINEL);
check('the new password travels in loginSec:newPW', firstText($x, '//loginSec:newPW') === $LONG);
check('and no loginSec:pw is emitted for a short current password', $x->query('//loginSec:pw')->length === 0);

// Long -> short: the mirror image. newPW must stay literal, or the account's new password becomes
// the sentinel string itself.
$x = $loginFrame($LONG, 'short2');
check('rotating long -> short marks <pw> with the sentinel', firstText($x, '//e:login/e:pw') === $SENTINEL);
check('and keeps <newPW> literal', firstText($x, '//e:login/e:newPW') === 'short2');
check('the current password travels in loginSec:pw', firstText($x, '//loginSec:pw') === $LONG);
check('and no loginSec:newPW is emitted for a short new password', $x->query('//loginSec:newPW')->length === 0);

// Long -> long: both relocate.
$x = $loginFrame($LONG, str_repeat('b', 40));
check('long -> long relocates both', firstText($x, '//e:login/e:pw') === $SENTINEL
    && firstText($x, '//e:login/e:newPW') === $SENTINEL);
check('and both loginSec values are present', $x->query('//loginSec:pw')->length === 1
    && $x->query('//loginSec:newPW')->length === 1);

// Short -> short: neither value is relocated, so neither loginSec password element appears — even
// though the block itself does, to take part in the extension.
$x = $loginFrame('short1', 'short2');
check('short -> short relocates neither password', $x->query('//loginSec:pw')->length === 0
    && $x->query('//loginSec:newPW')->length === 0);
check('and both passwords stay literal', firstText($x, '//e:login/e:pw') === 'short1'
    && firstText($x, '//e:login/e:newPW') === 'short2');

// Opting out removes the block outright, so a caller who wants the pre-8807 frame can have it —
// but a password that cannot fit in <pw> still travels in the extension, since there is nowhere
// else for it to go and dropping it would send the wrong password rather than none.
$optOut = static function (string $password, ?string $newPassword) use ($LOGINSEC_GREETING, $OK): DOMXPath {
    [$client, $fake] = makeClient([$LOGINSEC_GREETING, $OK()], $password, ['loginSecurity' => false]);
    $client->connect();
    $client->login($newPassword);
    $xpath = xp($fake->written[0]);
    $xpath->registerNamespace('loginSec', Namespaces::LOGINSEC);

    return $xpath;
};
$x = $optOut('short1', 'short2');
check('opting out sends no loginSec block for short passwords', $x->query('//loginSec:loginSec')->length === 0);
$x = $optOut($LONG, null);
check('opting out cannot suppress a password that does not fit <pw>', $x->query('//loginSec:pw')->length === 1);

echo "response accessors read every object the registry answers with\n";
// One fixture per object type. These are what a customer reaches for first, and the failure they
// produce is silent: an accessor that finds the wrong element returns a plausible-looking string.
$infData = static fn (string $inner): string => '<?xml version="1.0"?>'
    . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
    . '<resData>' . $inner . '</resData><trID><svTRID>X</svTRID></trID></response></epp>';

$dom = EppTools\Response::fromXml($infData(
    '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:registrant>c-reg</domain:registrant>'
    . '<domain:contact type="admin">c-admin</domain:contact>'
    . '<domain:contact type="tech">c-t1</domain:contact><domain:contact type="tech">c-t2</domain:contact>'
    . '<domain:contact type="billing">c-bill</domain:contact>'
    . '<domain:ns><domain:hostAttr><domain:hostName>NS1.Example.NET</domain:hostName>'
    . '<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>'
    . '<domain:hostAddr>198.51.100.7</domain:hostAddr></domain:hostAttr></domain:ns>'
    . '<domain:host>ns1.example.com.ua</domain:host>'
    . '<domain:authInfo><domain:pw>auth-1</domain:pw></domain:authInfo></domain:infData>'
));
check('role contacts are addressable one role at a time', $dom->techContacts() === ['c-t1', 'c-t2']);
check('and admin/billing are separate', $dom->adminContacts() === ['c-admin'] && $dom->billingContacts() === ['c-bill']);
// Registries disagree on `tech` vs `Tech`; an exact match reports "no technical contact" for a
// domain that has two.
check('a role is matched case-insensitively', $dom->contactsFor('TECH') === ['c-t1', 'c-t2']);
check('a role nobody holds is an empty list, not an error', $dom->contactsFor('reseller') === []);
check('allContacts() includes the registrant', in_array('c-reg', $dom->allContacts(), true));
check('subordinate hosts are listed (they block a delete)', $dom->subordinateHosts() === ['ns1.example.com.ua']);
$glue = $dom->nameserverAddresses();
check('inline glue is keyed by nameserver, not flattened', array_keys($glue) === ['ns1.example.net']);
check('and an addr with no @ip defaults to v4', $glue['ns1.example.net'][1] === ['ip' => '198.51.100.7', 'version' => 'v4']);
// The bug this pins: a document-wide addr search made a DOMAIN look like a well-addressed host.
check('hostAddresses() stays empty on a domain', $dom->hostAddresses() === []);
check('authInfo() surfaces the transfer secret', $dom->authInfo() === 'auth-1');

$ct = EppTools\Response::fromXml($infData(
    '<contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">'
    . '<contact:id>c-reg</contact:id>'
    . '<contact:postalInfo type="int"><contact:name>Ivan Petrenko</contact:name>'
    . '<contact:addr><contact:street>1 Main St</contact:street><contact:city>Kyiv</contact:city>'
    . '<contact:cc>UA</contact:cc></contact:addr></contact:postalInfo>'
    . '<contact:postalInfo type="loc"><contact:name>Іван Петренко</contact:name>'
    . '<contact:addr><contact:city>Київ</contact:city><contact:cc>UA</contact:cc></contact:addr></contact:postalInfo>'
    . '<contact:fax>+380.441234568</contact:fax>'
    . '<contact:disclose flag="0"><contact:email/></contact:disclose></contact:infData>'
));
// objectName() searched the whole document for <name> and found the person, so contact:info
// answered with a full name where the caller asked for the handle — and 2303 on the next command.
check('objectName() on a contact is the HANDLE, not the postal name', $ct->objectName() === 'c-reg');
check('both postal forms are kept apart', $ct->postalInfo()['loc']['name'] === 'Іван Петренко');
check('the international form stays available for printing anywhere', $ct->postalInfo()['int']['city'] === 'Kyiv');
check('a missing postal part is empty, never null', $ct->postalInfo()['loc']['pc'] === '');
check('fax is read', $ct->fax() === '+380.441234568');
check('disclose keeps the flag with the list', $ct->disclose() === ['flag' => false, 'elements' => ['email']]);
check('a contact addr container is not read as glue', $ct->hostAddresses() === []);

$hostRes = EppTools\Response::fromXml($infData(
    '<host:infData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:name>ns1.example.com.ua</host:name>'
    . '<host:addr ip="v6">2001:db8::53</host:addr><host:addr>203.0.113.9</host:addr></host:infData>'
));
check('a host object reports its own glue', $hostRes->hostAddresses() === [
    ['ip' => '2001:db8::53', 'version' => 'v6'],
    ['ip' => '203.0.113.9', 'version' => 'v4'],
]);

$trnRes = EppTools\Response::fromXml($infData(
    '<domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:trStatus>pending</domain:trStatus>'
    . '<domain:reID>ACME</domain:reID><domain:acID>EXAMPLE</domain:acID>'
    . '<domain:acDate>2026-08-21T09:00:00Z</domain:acDate></domain:trnData>'
));
// transferStatus() says a transfer is pending without saying whose, or by when it auto-approves.
check('a transfer notice carries the counterparty and the deadline', ($trnRes->transfer() ?? [])['requestedBy'] === 'ACME'
    && ($trnRes->transfer() ?? [])['actBy'] === '2026-08-21T09:00:00Z');

$chkRes = EppTools\Response::fromXml(
    '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result><resData>'
    . '<domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">free.com.ua</domain:name></domain:cd>'
    . '<domain:cd><domain:name avail="0">taken.com.ua</domain:name><domain:reason>In use</domain:reason></domain:cd>'
    . '</domain:chkData></resData><extension>'
    . '<fee:chkData xmlns:fee="urn:ietf:params:xml:ns:epp:fee-1.0"><fee:currency>UAH</fee:currency>'
    . '<fee:cd avail="1"><fee:objID>free.com.ua</fee:objID><fee:class>premium</fee:class>'
    . '<fee:command name="create"><fee:period unit="y">1</fee:period><fee:fee>5000.00</fee:fee></fee:command>'
    . '</fee:cd></fee:chkData></extension><trID><svTRID>X</svTRID></trID></response></epp>'
);
check('an unavailable name reports why', $chkRes->unavailableReason('taken.com.ua') === 'In use');
check('an available name has no reason', $chkRes->unavailableReason('free.com.ua') === null);
check('a name nobody asked about is null, not a false reason', $chkRes->unavailableReason('other.com.ua') === null);
// Charging a premium at the standard price is a loss taken silently on every such registration.
check('a premium name is flagged', $chkRes->isPremium('free.com.ua') === true && $chkRes->feeClass('free.com.ua') === 'premium');

// ---------------------------------------------------------------------------------------------
// PLAIN WORDS AND EPP'S ABBREVIATIONS BUILD THE SAME FRAME.
//
// The value of an alias is that it is not a second code path. So this does not check that
// `remove` "works" — it checks that the bytes on the wire are IDENTICAL to the ones `rem`
// produces, which is the only claim that stays true when the frame builder changes.
//
// It also pins the direction of precedence: a caller migrating one call at a time will pass both
// for a while, and the plain word has to win, because that is the spelling they are moving TO.
$updateOpts = static function (array $extra): array {
    return array_merge([
        'add' => ['ns' => ['ns1.plain.ua'], 'statuses' => ['clientHold']],
        'secDNS' => ['maxSigLife' => 604800],
    ], $extra);
};

[$clientShort, $fakeShort] = makeClient([$GREETING, $OK()]);
$clientShort->connect();
$clientShort->domain()->update('plain.ua', $updateOpts([
    'rem' => ['ns' => ['ns9.plain.ua']],
    'chg' => ['registrant' => 'C-1'],
]));

[$clientPlain, $fakePlain] = makeClient([$GREETING, $OK()]);
$clientPlain->connect();
$clientPlain->domain()->update('plain.ua', $updateOpts([
    'remove' => ['ns' => ['ns9.plain.ua']],
    'change' => ['registrant' => 'C-1'],
]));

check(
    "domain:update 'remove'/'change' build the same frame as 'rem'/'chg'",
    $fakeShort->written[0] === $fakePlain->written[0],
);

// Both at once: the plain word wins, so a half-migrated codebase behaves predictably.
[$clientBoth, $fakeBoth] = makeClient([$GREETING, $OK()]);
$clientBoth->connect();
$clientBoth->domain()->update('plain.ua', $updateOpts([
    'rem'    => ['ns' => ['ns-ignored.plain.ua']],
    'remove' => ['ns' => ['ns9.plain.ua']],
    'chg'    => ['registrant' => 'C-IGNORED'],
    'change' => ['registrant' => 'C-1'],
]));
check(
    'when both spellings are sent, the plain word is the one that reaches the wire',
    $fakeBoth->written[0] === $fakePlain->written[0],
);

// secDNS is a nested block with its own key check, so it needs its own case: a `remove` there used
// to be an unknown key, refused before the frame was built.
[$clientSec, $fakeSec] = makeClient([$GREETING, $OK()]);
$clientSec->connect();
$clientSec->domain()->update('plain.ua', ['secDNS' => ['removeAll' => true]]);
$secXp = xp($fakeSec->written[0]);
check(
    "domain:update secDNS 'removeAll' reaches the wire as <secDNS:all>",
    firstText($secXp, '//secDNS:rem/secDNS:all') === 'true',
);

// contact and host carry the same vocabulary and were renamed with it.
[$clientC1, $fakeC1] = makeClient([$GREETING, $OK()]);
$clientC1->connect();
$clientC1->contact()->update('C-1', ['remStatuses' => ['clientDeleteProhibited'], 'chg' => ['email' => 'contact@example.com']]);
[$clientC2, $fakeC2] = makeClient([$GREETING, $OK()]);
$clientC2->connect();
$clientC2->contact()->update('C-1', ['removeStatuses' => ['clientDeleteProhibited'], 'change' => ['email' => 'contact@example.com']]);
check(
    "contact:update 'removeStatuses'/'change' build the same frame",
    $fakeC1->written[0] === $fakeC2->written[0],
);

[$clientH1, $fakeH1] = makeClient([$GREETING, $OK()]);
$clientH1->connect();
$clientH1->host()->update('ns1.plain.ua', ['remAddresses' => ['192.0.2.9'], 'remStatuses' => ['clientUpdateProhibited']]);
[$clientH2, $fakeH2] = makeClient([$GREETING, $OK()]);
$clientH2->connect();
$clientH2->host()->update('ns1.plain.ua', ['removeAddresses' => ['192.0.2.9'], 'removeStatuses' => ['clientUpdateProhibited']]);
check(
    "host:update 'removeAddresses'/'removeStatuses' build the same frame",
    $fakeH1->written[0] === $fakeH2->written[0],
);

// AND AN UNKNOWN KEY IS STILL REFUSED. The alias must not become a hole in the check that catches
// 'secdns' for 'secDNS' — the whole reason this library validates option keys at all.
$refused = null;
try {
    [$clientBad, ] = makeClient([$GREETING, $OK()]);
    $clientBad->connect();
    $clientBad->domain()->update('plain.ua', ['removes' => ['ns' => ['x.ua']]]);
} catch (\Throwable $e) {
    $refused = $e;
}
check('a near-miss spelling is still refused, not silently dropped', $refused !== null);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);


