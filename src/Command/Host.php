<?php

declare(strict_types=1);

namespace EppTools\Command;

use EppTools\Client;
use EppTools\Exception\ValidationException;
use EppTools\Namespaces;
use EppTools\Builder\HostUpdateBuilder;
use EppTools\Options;
use EppTools\Response;

/**
 * Host (nameserver) object commands (RFC 5732). Reached via Client::host().
 *
 * Renaming is NOT supported. RFC 5732 does define the element for it — a <host:chg> carrying a new
 * <host:name> — but implementing it is each registry's own decision, so update() rejects a 'newName'
 * option instead of sending something that may be discarded and reported as success.
 */
final class Host
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param string[] $names */
    public function check(array $names): Response
    {
        if ($names === []) {
            // host:checkType requires at least one <host:name>, so an empty list builds a childless
            // <host:check/> the server never parses: a bare 2001 for a question the caller thinks
            // they asked.
            throw new ValidationException(
                'host:check needs at least one name — RFC 5732 requires a <host:name>, so an empty '
                . 'list is a frame the registry refuses rather than an empty answer',
            );
        }

        // A blank name is refused, not dropped. See Options::identifiers() for why this list is the one
        // place a blank is not filtered.
        $names = Options::identifiers($names, 'host:check', 'host:name');

        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::HOST, 'host:check');
        foreach ($names as $name) {
            $frame->ns($check, Namespaces::HOST, 'host:name', $name);
        }

        return $this->client->request($frame);
    }

    public function info(string $name): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::HOST, 'host:info');
        $frame->ns($info, Namespaces::HOST, 'host:name', $name);

        return $this->client->request($frame);
    }

    /** @param string[] $addresses IPv4 or IPv6 literals; the version is auto-detected. */
    public function create(string $name, array $addresses = []): Response
    {
        $frame = $this->client->frame();
        $create = $frame->ns($frame->verb('create'), Namespaces::HOST, 'host:create');
        $frame->ns($create, Namespaces::HOST, 'host:name', $name);
        // Trimmed and blank-dropped here, not only in the builder: host:addrStringType has minLength
        // 3, so one empty entry — a stray comma in a form field — makes the whole frame one the
        // schema refuses, and the nameserver is not created at all.
        foreach (Options::nonEmptyStrings($addresses) as $ip) {
            $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
            $frame->ns($create, Namespaces::HOST, 'host:addr', $ip, ['ip' => $version]);
        }

        return $this->client->request($frame);
    }

    /** Build a nameserver change step by step. See Domain::createBuilder(). */
    public function updateBuilder(string $name): HostUpdateBuilder
    {
        return new HostUpdateBuilder($this, $name);
    }

    /**
     * Every option host:update understands. `newName` is listed so the rename attempt below reaches
     * its own explanatory error rather than a bare "unknown option".
     */
    private const UPDATE_KEYS = ['addAddresses', 'addStatuses', 'remAddresses', 'remStatuses', 'newName'];

    public function update(string $name, array $options = []): Response
    {
        // Plain words first - see Options::canonicalise().
        $options = Options::canonicalise($options, [
            'removeAddresses' => 'remAddresses',
            'removeStatuses'  => 'remStatuses',
        ]);

        Options::check($options, self::UPDATE_KEYS, 'host:update');
        if (isset($options['newName'])) {
            // RFC 5732 defines <host:chg><host:name> for this, but a registry that does not read the
            // chg block applies the rest of the command, drops the rename and still answers 1000 —
            // leaving the caller believing the host was renamed while every domain delegated to it
            // still points at the old name. Refusing here is the only way they find out.
            throw new ValidationException(
                'host:update cannot rename a nameserver at this registry — create the new host, '
                . 'repoint the domains that use it, then delete the old one'
            );
        }
        // Worked out BEFORE the frame exists, because Client::frame() stamps a clTRID and a command
        // that turns out to ask for nothing should not spend one on the way out.
        //
        // Blanks go before the block is opened, so a list that held nothing but blanks does not open a
        // childless <host:add/> either. host:addrStringType has minLength 3 and host:statusValueType
        // is an enumeration the empty string is no member of, so either one empty entry costs the
        // whole update. See Options::nonEmptyStrings().
        $blocks = [];
        foreach (['add' => ['addAddresses', 'addStatuses'], 'rem' => ['remAddresses', 'remStatuses']] as $op => [$addrKey, $statusKey]) {
            $blocks[$op] = [
                'addrs' => Options::nonEmptyStrings($options[$addrKey] ?? []),
                'statuses' => Options::nonEmptyStrings($options[$statusKey] ?? []),
            ];
        }

        // RFC 5732 section 3.2.5: "At least one <host:add>, <host:rem>, or <host:chg> element MUST be
        // provided if the command is not being extended." host:updateType makes all three optional, so
        // the schema cannot say this and an update that asks for nothing has always been valid XML —
        // answered with 2003, or with 1000 and no change made. This library sends no extension with a
        // host:update, and refuses the only chg the RFC defines (see newName above), so there is no
        // case where the empty form is legitimate.
        if ($blocks['add']['addrs'] === [] && $blocks['add']['statuses'] === []
            && $blocks['rem']['addrs'] === [] && $blocks['rem']['statuses'] === []) {
            throw new ValidationException(
                'host:update asks for nothing: RFC 5732 requires at least one of addAddresses, '
                . 'addStatuses, remAddresses or remStatuses, so this frame describes no change and the '
                . 'registry has nothing to apply. Check whether the delta you assembled came out '
                . 'empty — a list holding only blanks filters down to nothing.',
            );
        }

        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::HOST, 'host:update');
        $frame->ns($update, Namespaces::HOST, 'host:name', $name);

        foreach (['add', 'rem'] as $op) {
            ['addrs' => $addrs, 'statuses' => $statuses] = $blocks[$op];
            if ($addrs === [] && $statuses === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::HOST, "host:{$op}");
            foreach ($addrs as $ip) {
                $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
                $frame->ns($block, Namespaces::HOST, 'host:addr', $ip, ['ip' => $version]);
            }
            foreach ($statuses as $status) {
                $frame->ns($block, Namespaces::HOST, 'host:status', null, ['s' => $status]);
            }
        }

        return $this->client->request($frame);
    }

    public function delete(string $name, bool $force = false): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::HOST, 'host:delete');
        $frame->ns($del, Namespaces::HOST, 'host:name', $name);
        if ($force) {
            // Registry extension: detach the host from every domain before deleting it. Not every
            // registry offers this, so the URI comes from the greeting and its absence is reported
            // rather than guessed at — a forced delete sent without the extension the server knows
            // is an ORDINARY delete, which fails on a host that is still in use and leaves the
            // caller wondering why `force` did nothing.
            $uri = $this->client->requireRegistryExtUri('host:delete with force');
            $u = $frame->ns($frame->extension(), $uri, 'registry:delete');
            $frame->ns($u, $uri, 'registry:deleteNS', null, ['confirm' => 'yes']);
        }

        return $this->client->request($frame);
    }
}

