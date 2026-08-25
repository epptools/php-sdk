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
 * Renaming is NOT supported: the registry does not implement host:chg, so update() rejects a
 * 'newName' option instead of pretending it worked.
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
        foreach ($addresses as $ip) {
            $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
            $frame->ns($create, Namespaces::HOST, 'host:addr', (string) $ip, ['ip' => $version]);
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
            // The registry reads only the add/rem blocks of host:update, so a <host:chg><host:name>
            // rename is discarded behind a 1000 and leaves the caller believing the host was
            // renamed. Refusing it here is the only way the caller finds out.
            throw new ValidationException(
                'host:update cannot rename a nameserver at this registry — create the new host, '
                . 'repoint the domains that use it, then delete the old one'
            );
        }
        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::HOST, 'host:update');
        $frame->ns($update, Namespaces::HOST, 'host:name', $name);

        foreach (['add' => ['addAddresses', 'addStatuses'], 'rem' => ['remAddresses', 'remStatuses']] as $op => [$addrKey, $statusKey]) {
            $addrs = (array) ($options[$addrKey] ?? []);
            $statuses = (array) ($options[$statusKey] ?? []);
            if ($addrs === [] && $statuses === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::HOST, "host:{$op}");
            foreach ($addrs as $ip) {
                $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
                $frame->ns($block, Namespaces::HOST, 'host:addr', (string) $ip, ['ip' => $version]);
            }
            foreach ($statuses as $status) {
                $frame->ns($block, Namespaces::HOST, 'host:status', null, ['s' => (string) $status]);
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

