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
        if ($ids === []) {
            // contact:checkType requires at least one <contact:id>, so an empty list builds a
            // childless <contact:check/> the server never parses: a bare 2001 for a question the
            // caller thinks they asked.
            throw new ValidationException(
                'contact:check needs at least one handle — RFC 5733 requires a <contact:id>, so an '
                . 'empty list is a frame the registry refuses rather than an empty answer',
            );
        }

        // A blank handle is refused, not dropped. See Options::identifiers() for why this list is the
        // one place a blank is not filtered.
        $ids = Options::identifiers($ids, 'contact:check', 'contact:id');

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
            // The flat form spreads the postal fields across the top level, beside email, voice and
            // the rest, so only the postal keys are lifted out of it - passing the whole option map
            // into a block that then checks its own keys would refuse every non-postal option in it.
            $flat = array_intersect_key($options, array_flip(self::POSTAL_KEYS));
            $this->appendPostalInfo($frame, $c, $flat);
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

        // Worked out BEFORE the frame exists, because Client::frame() stamps a clTRID and a command
        // that turns out to ask for nothing should not spend one on the way out.
        //
        // Trimmed and blank-dropped here, not only in the builder: contact:statusValueType is an
        // enumeration and the empty string is not a member of it, so one blank status costs the whole
        // update — and a list that held nothing but blanks must not open a childless <contact:add/>,
        // which is refused in its own right. See Options::nonEmptyStrings().
        $addStatuses = Options::nonEmptyStrings($options['addStatuses'] ?? []);
        $remStatuses = Options::nonEmptyStrings($options['remStatuses'] ?? []);

        $chg = (array) ($options['chg'] ?? []);
        $pis = $chg['postalInfos'] ?? (isset($chg['postalInfo']) ? [$chg['postalInfo']] : null);
        $postalInfos = is_array($pis) ? $pis : [];
        // Whether a child will be emitted, rather than whether the caller passed the key. RFC 5733
        // says of the chg block: "At least one child element MUST be present" — and 'disclose' => []
        // or 'postalInfos' => [] are keys that produce none, so on their own they would open a
        // <contact:chg/> the schema and the server both refuse.
        $changing = $postalInfos !== [] || isset($chg['voice']) || isset($chg['fax'])
            || isset($chg['email']) || isset($chg['authInfo']) || !empty($chg['disclose']);

        // RFC 5733 section 3.2.5: "At least one <contact:add>, <contact:rem>, or <contact:chg> element
        // MUST be provided if the command is not being extended." contact:updateType makes all three
        // optional, so the schema cannot say this and an update that asks for nothing has always been
        // valid XML — answered with 2003, or with 1000 and no change made. This library sends no
        // extension with a contact:update, so there is no case where the empty form is legitimate.
        if ($addStatuses === [] && $remStatuses === [] && !$changing) {
            throw new ValidationException(
                'contact:update asks for nothing: RFC 5733 requires at least one of addStatuses, '
                . 'remStatuses or chg, so this frame describes no change and the registry has nothing '
                . 'to apply. Check whether the delta you assembled came out empty — a list of statuses '
                . 'holding only blanks filters down to nothing.',
            );
        }

        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::CONTACT, 'contact:update');
        $frame->ns($update, Namespaces::CONTACT, 'contact:id', $id);
        // contact:updateType allows a SINGLE add/rem block (each holding up to 7 statuses), so the
        // wrapper is created once and every status appended into it — emitting one <contact:add> per
        // status is rejected by the server XSD (add maxOccurs=1).
        if ($addStatuses !== []) {
            $add = $frame->ns($update, Namespaces::CONTACT, 'contact:add');
            foreach ($addStatuses as $status) {
                $frame->ns($add, Namespaces::CONTACT, 'contact:status', null, ['s' => $status]);
            }
        }
        if ($remStatuses !== []) {
            $rem = $frame->ns($update, Namespaces::CONTACT, 'contact:rem');
            foreach ($remStatuses as $status) {
                $frame->ns($rem, Namespaces::CONTACT, 'contact:status', null, ['s' => $status]);
            }
        }
        if ($changing) {
            $block = $frame->ns($update, Namespaces::CONTACT, 'contact:chg');
            // RFC 5733 chg order: postalInfo*, voice?, fax?, email?, authInfo?, disclose?
            foreach ($postalInfos as $pi) {
                $this->appendPostalInfo($frame, $block, (array) $pi, partial: true);
            }
            if (isset($chg['voice'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:voice', (string) $chg['voice']);
            }
            if (isset($chg['fax'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:fax', (string) $chg['fax']);
            }
            if (isset($chg['email'])) {
                // Not a clearable field: RFC 5733 types it minTokenType (minLength 1), so an empty
                // one is a schema-invalid frame answered with a bare 2001 that names nothing -
                // the same refusal contact:create already makes.
                if ((string) $chg['email'] === '') {
                    throw new ValidationException(
                        "contact:update cannot clear 'email' — RFC 5733 requires a non-empty one",
                    );
                }
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
        // The five operations are an epp-1.0.xsd enumeration. A frame carrying any other verb is never
        // parsed, so the answer is a bare 2001 and the transfer stays where it was — with the
        // deadline on an incoming one still running.
        $op = Options::oneOf($op, self::TRANSFER_OPS, "transfer 'op'", 'RFC 5730 transferOpType');
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

    /** Every key a postalInfo block understands; `type` picks the int or loc form. */
    private const POSTAL_KEYS = ['type', 'name', 'org', 'street', 'city', 'sp', 'pc', 'cc'];

    /** Every field that can be disclosed or withheld, plus the flag that says which. */
    private const DISCLOSE_KEYS = ['flag', 'name', 'org', 'addr', 'voice', 'fax', 'email'];

    /** The two forms a postal block and a disclosable field come in (contact:postalInfoEnumType). */
    private const POSTAL_TYPES = ['int', 'loc'];

    /** The five transfer operations epp-1.0.xsd enumerates (transferOpType). */
    private const TRANSFER_OPS = ['request', 'approve', 'reject', 'cancel', 'query'];

    /**
     * Build one <contact:postalInfo> from type/name/org/street/city/sp/pc/cc.
     *
     * @param array<string, mixed> $pi
     * @param bool $partial true inside <contact:chg>. The block is still sent WHOLE — see below; only
     *        the optional children (org, sp, pc) distinguish "absent" from "present but empty", which
     *        is how they are cleared.
     */
    private function appendPostalInfo(Frame $frame, \DOMElement $parent, array $pi, bool $partial = false): void
    {
        // The key check reaches INSIDE the block, not just the option that carries it. `postalCode`
        // for `pc` was accepted and dropped, and on an update a postalInfo REPLACES the stored one,
        // so the registrant's postcode was deleted behind a 1000.
        Options::check($pi, self::POSTAL_KEYS, 'contact postalInfo');
        // int or loc, and nothing else: contact:postalInfoEnumType is a two-value enumeration, so
        // 'ascii' or 'INT' is a frame the server never parses rather than a form it does not hold.
        $type = Options::oneOf($pi['type'] ?? 'int', self::POSTAL_TYPES, "postalInfo 'type'", 'RFC 5733 postalInfoEnumType');
        $block = $frame->ns($parent, Namespaces::CONTACT, 'contact:postalInfo', null, ['type' => $type]);

        // A postalInfo inside <contact:chg> REPLACES the stored one. It is not merged field by field.
        //
        // RFC 5733 can be read the other way: in chgPostalInfoType each of name/org/addr is optional,
        // which looks like "omit it and the registry keeps what it holds". That reading is not safe.
        // Against a registry that replaces, a chg carrying only <contact:org/> answers **1000** and
        // leaves the contact with NO postalInfo at all — name, street, city, postal code and country
        // gone, in both the int and loc blocks — and a complete block sent without an <org> removes
        // the organisation just as surely.
        //
        // So the short form does not fail, it DESTROYS, and it reports success while doing it. A
        // client cannot tell a replacing registry from a merging one, and the cost of guessing wrong
        // is a registrant's postal address. Every change therefore carries the whole block.
        foreach (['name', 'city', 'cc'] as $required) {
            if (trim((string) ($pi[$required] ?? '')) === '') {
                throw new ValidationException(sprintf(
                    'postalInfo: a <contact:postalInfo> is REPLACED as a whole, not merged, so every change '
                    . 'must carry the complete block — "%s" is missing. (A registry that replaces answers 1000 '
                    . 'and silently drops everything you left out.) Read the current block with contact()->info() '
                    . 'and send it back with your change applied.',
                    $required,
                ));
            }
        }

        // The loop above is the only guard the name needs. A second identical test stood here, and a
        // check that cannot fire is worse than none: a reader counts it as protection and stops
        // looking for the one that does the work.
        //
        // What it knew is worth keeping, because it is the rule that decides which fields below are
        // emitted empty and which are refused: contact-1.0.xsd gives name and city postalLineType
        // (minLength 1) and cc ccType (exactly two characters), so none of those three can be sent
        // empty at all — while org, street, sp and pc have no minimum, which is how they are cleared.
        $frame->ns($block, Namespaces::CONTACT, 'contact:name', (string) $pi['name']);

        if ($partial ? array_key_exists('org', $pi) : !empty($pi['org'])) {
            // org is optPostalLineType, which HAS no minLength — so an empty one is legal and is
            // exactly how an organisation is removed.
            $frame->ns($block, Namespaces::CONTACT, 'contact:org', (string) ($pi['org'] ?? ''));
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
     * Build a <contact:disclose flag="0|1"> block. name/org/addr take a type (int|loc),
     * passed as a list; voice/fax/email are bare flags toggled by a truthy value.
     *
     * Example: ['flag' => false, 'addr' => ['int'], 'voice' => true, 'email' => true]
     */
    private function appendDisclose(Frame $frame, \DOMElement $parent, array $disclose): void
    {
        // A misspelled field here is a privacy instruction that reads as applied and is not: the
        // frame goes out as a childless <contact:disclose flag="0"/>, the registry answers 1000, and
        // the address the registrant asked to withhold stays published.
        Options::check($disclose, self::DISCLOSE_KEYS, 'contact disclose');
        $flag = !empty($disclose['flag']) ? '1' : '0';
        $disc = $frame->ns($parent, Namespaces::CONTACT, 'contact:disclose', null, ['flag' => $flag]);
        foreach (['name', 'org', 'addr'] as $field) {
            if (!isset($disclose[$field])) {
                continue;
            }
            foreach ((array) $disclose[$field] as $type) {
                // Same two-value enumeration as a postal block's type, for the same reason: a
                // disclosure instruction naming a form the schema does not know is not a preference
                // the registry declines, it is a frame it never reads — so the field the registrant
                // asked to withhold stays published and the command reports an error naming nothing.
                $form = Options::oneOf($type, self::POSTAL_TYPES, "disclose '{$field}' form", 'RFC 5733 postalInfoEnumType');
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}", null, ['type' => $form]);
            }
        }
        foreach (['voice', 'fax', 'email'] as $field) {
            if (!empty($disclose[$field])) {
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}");
            }
        }
    }
}

