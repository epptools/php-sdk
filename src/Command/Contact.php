<?php

declare(strict_types=1);

namespace EppTools\Command;

use EppTools\Client;
use EppTools\Exception\ValidationException;
use EppTools\Frame;
use EppTools\Namespaces;
use EppTools\Builder\ContactCreateBuilder;
use EppTools\Builder\ContactUpdateBuilder;
use EppTools\Options;
use EppTools\Response;

/**
 * Contact object commands (RFC 5733), including disclosure preferences and localized
 * (int/loc) postal-info blocks. Reached via Client::contact().
 */
final class Contact
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param string[] $ids */
    public function check(array $ids): Response
    {
        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::CONTACT, 'contact:check');
        foreach ($ids as $id) {
            $frame->ns($check, Namespaces::CONTACT, 'contact:id', $id);
        }

        return $this->client->request($frame);
    }

    public function info(string $id, ?string $authInfo = null): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::CONTACT, 'contact:info');
        $frame->ns($info, Namespaces::CONTACT, 'contact:id', $id);
        if ($authInfo !== null) {
            $ai = $frame->ns($info, Namespaces::CONTACT, 'contact:authInfo');
            $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /**
     * The reserved id that asks the registry to CHOOSE the handle instead of you naming it.
     *
     * Send it in place of a contact id on create. It is a request, not a name — the handle the
     * registry mints comes back in the response, and that reply is the only place it appears, so
     * store what Response::objectName() gives you.
     */
    public const AUTO_ID = 'autonic';

    /**
     * Create a contact and let the registry choose the handle. Read it back with objectName():
     *
     *     $handle = $client->contact()->createAuto(['email' => 'contact@example.com', ...])->objectName();
     *
     * Useful when you have no naming scheme of your own, and when you would otherwise have to
     * retry around 2302 because someone else took the handle first. Every call mints a fresh one,
     * so a repeat is a second contact, never a collision.
     *
     * @param array<string, mixed> $options as create()
     */
    public function createAuto(array $options = []): Response
    {
        return $this->create(self::AUTO_ID, $options);
    }

    /**
     * Build a contact step by step. The id and e-mail are required by the registry, so they are
     * arguments here rather than steps you can forget. See Domain::createBuilder().
     *
     * Pass Contact::AUTO_ID as the id to have the registry mint the handle.
     */
    public function createBuilder(string $id, string $email): ContactCreateBuilder
    {
        return new ContactCreateBuilder($this, $id, $email);
    }

    /** Build a contact change step by step. */
    public function updateBuilder(string $id): ContactUpdateBuilder
    {
        return new ContactUpdateBuilder($this, $id);
    }

    /**
     * Every option contact:create understands. The postal fields may be given flat (one int block)
     * or as `postalInfos`, so both spellings are accepted here.
     */
    private const CREATE_KEYS = [
        'postalInfos', 'type', 'name', 'org', 'street', 'city', 'sp', 'pc', 'cc',
        'voice', 'fax', 'email', 'authInfo', 'disclose',
    ];

    /** Every option contact:update understands. */
    private const UPDATE_KEYS = ['chg', 'addStatuses', 'remStatuses'];

    /** Every key a contact:update `chg` block understands. */
    private const CHG_KEYS = ['postalInfo', 'postalInfos', 'voice', 'fax', 'email', 'authInfo', 'disclose'];

    public function create(string $id, array $options = []): Response
    {
        Options::check($options, self::CREATE_KEYS, 'contact:create');

        $frame = $this->client->frame();
        $c = $frame->ns($frame->verb('create'), Namespaces::CONTACT, 'contact:create');
        $frame->ns($c, Namespaces::CONTACT, 'contact:id', $id);

        $postalInfos = $options['postalInfos'] ?? null;
        if (is_array($postalInfos) && $postalInfos !== []) {
            foreach ($postalInfos as $pi) {
                $this->appendPostalInfo($frame, $c, (array) $pi);
            }
        } else {
            $this->appendPostalInfo($frame, $c, $options); // one block from the flat keys
        }

        if (!empty($options['voice'])) {
            $frame->ns($c, Namespaces::CONTACT, 'contact:voice', (string) $options['voice']);
        }
        if (!empty($options['fax'])) {
            $frame->ns($c, Namespaces::CONTACT, 'contact:fax', (string) $options['fax']);
        }
        $email = (string) ($options['email'] ?? '');
        if ($email === '') {
            // RFC 5733 requires a contact email (emailType minLength 1). Fail fast client-side with a
            // clear message instead of sending an empty <email/> the server rejects with an opaque 2005.
            // A ValidationException (not \InvalidArgumentException), so a caller that catches
            // EppException around its EPP calls handles this like any other refusal.
            throw new ValidationException("contact:create requires a non-empty 'email'");
        }
        $frame->ns($c, Namespaces::CONTACT, 'contact:email', $email);
        $ai = $frame->ns($c, Namespaces::CONTACT, 'contact:authInfo');
        $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', (string) ($options['authInfo'] ?? ''));
        if (!empty($options['disclose'])) {
            $this->appendDisclose($frame, $c, (array) $options['disclose']);
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{chg?:array{postalInfo?:array<string,mixed>,postalInfos?:array<int,array<string,mixed>>,
     *     voice?:string,fax?:string,email?:string,authInfo?:string,disclose?:array<string,mixed>},
     *     addStatuses?:string[],remStatuses?:string[]} $options
     */
    public function update(string $id, array $options = []): Response
    {
        // Plain words first - see Options::canonicalise().
        $options = Options::canonicalise($options, ['change' => 'chg', 'removeStatuses' => 'remStatuses']);

        Options::check($options, self::UPDATE_KEYS, 'contact:update');
        if (isset($options['chg']) && is_array($options['chg'])) {
            Options::check($options['chg'], self::CHG_KEYS, "contact:update 'chg'");
        }

        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::CONTACT, 'contact:update');
        $frame->ns($update, Namespaces::CONTACT, 'contact:id', $id);
        // contact:updateType allows a SINGLE add/rem block (each holding up to 7 statuses), so the
        // wrapper is created once and every status appended into it — emitting one <contact:add> per
        // status is rejected by the server XSD (add maxOccurs=1).
        $addStatuses = (array) ($options['addStatuses'] ?? []);
        if ($addStatuses !== []) {
            $add = $frame->ns($update, Namespaces::CONTACT, 'contact:add');
            foreach ($addStatuses as $status) {
                $frame->ns($add, Namespaces::CONTACT, 'contact:status', null, ['s' => (string) $status]);
            }
        }
        $remStatuses = (array) ($options['remStatuses'] ?? []);
        if ($remStatuses !== []) {
            $rem = $frame->ns($update, Namespaces::CONTACT, 'contact:rem');
            foreach ($remStatuses as $status) {
                $frame->ns($rem, Namespaces::CONTACT, 'contact:status', null, ['s' => (string) $status]);
            }
        }
        $chg = $options['chg'] ?? [];
        if ($chg !== []) {
            $block = $frame->ns($update, Namespaces::CONTACT, 'contact:chg');
            // RFC 5733 chg order: postalInfo*, voice?, fax?, email?, authInfo?, disclose?
            $pis = $chg['postalInfos'] ?? (isset($chg['postalInfo']) ? [$chg['postalInfo']] : null);
            if (is_array($pis)) {
                foreach ($pis as $pi) {
                    $this->appendPostalInfo($frame, $block, (array) $pi, partial: true);
                }
            }
            if (isset($chg['voice'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:voice', (string) $chg['voice']);
            }
            if (isset($chg['fax'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:fax', (string) $chg['fax']);
            }
            if (isset($chg['email'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:email', (string) $chg['email']);
            }
            if (isset($chg['authInfo'])) {
                $ai = $frame->ns($block, Namespaces::CONTACT, 'contact:authInfo');
                $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', (string) $chg['authInfo']);
            }
            if (!empty($chg['disclose'])) {
                $this->appendDisclose($frame, $block, (array) $chg['disclose']);
            }
        }

        return $this->client->request($frame);
    }

    public function delete(string $id): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::CONTACT, 'contact:delete');
        $frame->ns($del, Namespaces::CONTACT, 'contact:id', $id);

        return $this->client->request($frame);
    }

    public function transfer(string $op, string $id, ?string $authInfo = null): Response
    {
        $frame = $this->client->frame();
        $transfer = $frame->verb('transfer');
        $transfer->setAttribute('op', $op);
        $c = $frame->ns($transfer, Namespaces::CONTACT, 'contact:transfer');
        $frame->ns($c, Namespaces::CONTACT, 'contact:id', $id);
        if ($authInfo !== null) {
            $ai = $frame->ns($c, Namespaces::CONTACT, 'contact:authInfo');
            $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /** Build one <contact:postalInfo> block from name/org/street/city/sp/pc/cc/type. */
    /**
     * Build one <contact:postalInfo>.
     *
     * @param array<string, mixed> $pi
     * @param bool $partial on an update, PRESENCE decides. A key you leave out is not sent, so the
     *        registry keeps what it holds; a key present but EMPTY is sent as an empty element,
     *        which is how an optional field (org, sp, pc) is cleared. On a create every field is
     *        sent, because there is nothing to merge with.
     */
    private function appendPostalInfo(Frame $frame, \DOMElement $parent, array $pi, bool $partial = false): void
    {
        $block = $frame->ns($parent, Namespaces::CONTACT, 'contact:postalInfo', null, ['type' => (string) ($pi['type'] ?? 'int')]);
        $send = static fn (string $key): bool => $partial ? array_key_exists($key, $pi) : true;

        if ($send('name')) {
            // WHICH FIELDS CAN BE EMPTIED IS FIXED BY THE SCHEMA, not by us. `name` is
            // postalLineType, minLength 1, so there is NO WAY to clear a name — an empty element is
            // schema-invalid and the server answers a bare 2001 naming no field. Refused here, where
            // the message can say so.
            self::requireNotEmpty($pi['name'] ?? '', 'name');
            $frame->ns($block, Namespaces::CONTACT, 'contact:name', (string) $pi['name']);
        }
        if ($partial ? array_key_exists('org', $pi) : !empty($pi['org'])) {
            // org is optPostalLineType, which HAS no minLength — so an empty one is legal and is
            // exactly how an organisation is removed.
            $frame->ns($block, Namespaces::CONTACT, 'contact:org', (string) ($pi['org'] ?? ''));
        }

        // <addr> is a sequence with required city and cc, so it is emitted whole or not at all.
        $addrKeys = ['street', 'city', 'sp', 'pc', 'cc'];
        $touchesAddr = !$partial || array_filter($addrKeys, static fn (string $k): bool => array_key_exists($k, $pi)) !== [];
        if (!$touchesAddr) {
            return;
        }

        // AND "WHOLE" MEANS THE CALLER HAS TO SUPPLY THE REQUIRED PARTS. This used to substitute an
        // empty string for whatever was missing, so clearing one optional field — `['sp' => '']`,
        // the documented way to remove a state — emitted <city/> and <cc/> alongside it. city is
        // postalLineType (minLength 1) and cc is ccType (exactly 2 characters): the frame was
        // schema-invalid, and what came back was a bare 2001 that names no element. A caller doing
        // precisely what the manual told them to got an error that pointed at nothing.
        foreach (['city', 'cc'] as $required) {
            if (($pi[$required] ?? '') === '') {
                throw new ValidationException(sprintf(
                    'postalInfo: changing any part of the address means sending the whole <contact:addr>, '
                    . 'and RFC 5733 makes "%s" a required part of it. Read the current address with '
                    . 'contact()->info() and send city and cc back unchanged alongside what you are changing.',
                    $required,
                ));
            }
        }

        $addr = $frame->ns($block, Namespaces::CONTACT, 'contact:addr');
        foreach ((array) ($pi['street'] ?? []) as $line) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:street', (string) $line);
        }
        $frame->ns($addr, Namespaces::CONTACT, 'contact:city', (string) ($pi['city'] ?? ''));
        if ($partial ? array_key_exists('sp', $pi) : !empty($pi['sp'])) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:sp', (string) ($pi['sp'] ?? ''));
        }
        if ($partial ? array_key_exists('pc', $pi) : !empty($pi['pc'])) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:pc', (string) ($pi['pc'] ?? ''));
        }
        $frame->ns($addr, Namespaces::CONTACT, 'contact:cc', (string) $pi['cc']);
    }

    /**
     * Refuse an empty value for an element whose schema type forbids one.
     *
     * The distinction is not a house rule, it is contact-1.0.xsd: `optPostalLineType` (org, street,
     * sp) has no minLength and `pcType` has none either, so those four clear by being sent empty.
     * `postalLineType` (name, city) has minLength 1 and `ccType` is exactly two characters, so an
     * empty one of those cannot be sent at all. Getting it wrong costs a round trip and returns a
     * bare 2001 with no field named — which is the least useful error in EPP.
     */
    private static function requireNotEmpty(mixed $value, string $field): void
    {
        if (trim((string) $value) === '') {
            throw new ValidationException(sprintf(
                'postalInfo: "%s" cannot be empty — RFC 5733 gives it a schema type with a minimum '
                . 'length, so there is no way to clear it. Omit the key to leave it unchanged.',
                $field,
            ));
        }
    }

    /**
     * Build a <contact:disclose flag="0|1"> block. name/org/addr take a type (int|loc),
     * passed as a list; voice/fax/email are bare flags toggled by a truthy value.
     *
     * Example: ['flag' => false, 'addr' => ['int'], 'voice' => true, 'email' => true]
     */
    private function appendDisclose(Frame $frame, \DOMElement $parent, array $disclose): void
    {
        $flag = !empty($disclose['flag']) ? '1' : '0';
        $disc = $frame->ns($parent, Namespaces::CONTACT, 'contact:disclose', null, ['flag' => $flag]);
        foreach (['name', 'org', 'addr'] as $field) {
            if (!isset($disclose[$field])) {
                continue;
            }
            foreach ((array) $disclose[$field] as $type) {
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}", null, ['type' => (string) $type]);
            }
        }
        foreach (['voice', 'fax', 'email'] as $field) {
            if (!empty($disclose[$field])) {
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}");
            }
        }
    }
}

