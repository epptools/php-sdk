<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Command\Host;
use EppTools\Response;

/**
 * Changes a nameserver's glue addresses or statuses.
 *
 *     $response = $client->host()->updateBuilder('ns1.acme.example')
 *         ->addAddresses('192.0.2.10', '2001:db8::10')
 *         ->remAddresses('192.0.2.9')
 *         ->send();
 *
 * There is no rename: the registry does not implement host:chg, so a rename request is discarded
 * and the command still answers 1000. Create the new host, repoint the domains that use it with
 * domain:update, then delete the old one.
 */
final class HostUpdateBuilder extends Builder
{
    public function __construct(private Host $handler, private string $name)
    {
    }

    /** Add one glue address. Accumulates; see addAddresses() for several at once. */
    public function addAddress(string $ip): self
    {
        return $this->addAddresses($ip);
    }

    /** Add glue addresses. IPv4 and IPv6 are told apart automatically. Accumulates. */
    public function addAddresses(string ...$ips): self
    {
        return $this->push('addAddresses', $ips);
    }

    /** Remove one glue address. Accumulates. */
    public function remAddress(string $ip): self
    {
        return $this->remAddresses($ip);
    }

    /** Remove glue addresses. Accumulates. */
    public function remAddresses(string ...$ips): self
    {
        return $this->push('remAddresses', $ips);
    }

    /** Set a client-side status. */
    public function addStatus(string ...$statuses): self
    {
        return $this->push('addStatuses', $statuses);
    }

    /** Clear a client-side status. */
    public function remStatus(string ...$statuses): self
    {
        return $this->push('remStatuses', $statuses);
    }

    public function send(): Response
    {
        $this->markSent();

        return $this->handler->update($this->name, $this->options);
    }

    /** @param list<string> $values */
    private function push(string $key, array $values): self
    {
        $existing = (array) ($this->options[$key] ?? []);
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $existing[] = $value;
            }
        }
        $this->options[$key] = $existing;

        return $this;
    }
}
