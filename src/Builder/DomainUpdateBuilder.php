<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Command\Domain;
use EppTools\Exception\ValidationException;
use EppTools\Response;

/**
 * Changes a domain, one named step at a time.
 *
 *     $response = $client->domain()->updateBuilder('your-brand.com.ua')
 *         ->addNameservers('ns3.acme.example')
 *         ->remStatus('clientHold')
 *         ->changeRegistrant('acme-02')
 *         ->send();
 *
 * An EPP update is a DELTA, not a replacement: what you do not mention is left alone. The method
 * names say which of the three blocks each change lands in — add, rem or chg — because that
 * distinction is the whole semantics of the command.
 */
final class DomainUpdateBuilder extends Builder
{
    public function __construct(private Domain $handler, private string $name)
    {
    }

    /** Delegate to one more nameserver. Accumulates; see addNameservers() for several at once. */
    public function addNameserver(string $host): self
    {
        return $this->addNameservers($host);
    }

    /** Delegate to these nameservers as well as the ones already there. Accumulates. */
    public function addNameservers(string ...$hosts): self
    {
        return $this->push('add', 'ns', $hosts);
    }

    /** Stop delegating to one nameserver. Accumulates. */
    public function remNameserver(string $host): self
    {
        return $this->remNameservers($host);
    }

    /** Stop delegating to these nameservers. Accumulates. */
    public function remNameservers(string ...$hosts): self
    {
        return $this->push('rem', 'ns', $hosts);
    }

    /** Attach contacts in a role, keeping the ones already there. */
    public function addContact(string $role, string ...$handles): self
    {
        return $this->contactDelta('add', $role, $handles);
    }

    /** Detach contacts from a role. */
    public function remContact(string $role, string ...$handles): self
    {
        return $this->contactDelta('rem', $role, $handles);
    }

    /**
     * Set a client-side status, e.g. `clientHold` (takes the domain out of DNS) or
     * `clientTransferProhibited`. Server-set statuses cannot be changed from here.
     */
    public function addStatus(string ...$statuses): self
    {
        return $this->push('add', 'statuses', $statuses);
    }

    /** Clear a client-side status. */
    public function remStatus(string ...$statuses): self
    {
        return $this->push('rem', 'statuses', $statuses);
    }

    /**
     * Hand the domain to a different holder.
     *
     * Many registries treat this as a change of ownership with its own rules and paperwork, so a
     * refusal here is usually policy rather than a malformed command.
     */
    public function changeRegistrant(string $handle): self
    {
        $chg = (array) ($this->options['chg'] ?? []);
        $chg['registrant'] = $handle;
        $this->options['chg'] = $chg;

        return $this;
    }

    /**
     * Replace the transfer secret. Do this after the old one has been shared with anyone who should
     * no longer be able to move the domain.
     */
    public function changeAuthInfo(string $password): self
    {
        $chg = (array) ($this->options['chg'] ?? []);
        $chg['authInfo'] = $password;
        $this->options['chg'] = $chg;

        return $this;
    }

    /**
     * REMOVE the transfer secret entirely, so no code will move this domain.
     *
     * This is the answer to a leak, and it is not the same as setting an empty one: an empty
     * password is a value the holder can still present, so the domain stays exactly as movable as
     * it was. Only this clears it. Use it when a code has gone somewhere it should not have, then
     * set a fresh one with changeAuthInfo() when the customer needs it again.
     */
    public function clearAuthInfo(): self
    {
        $chg = (array) ($this->options['chg'] ?? []);
        $chg['clearAuthInfo'] = true;
        $this->options['chg'] = $chg;

        return $this;
    }

    /** Ask to bring a deleted domain back from the redemption period (RFC 3915). */
    public function restore(): self
    {
        $this->options['restore'] = true;

        return $this;
    }

    /** A trademark or licence number, for registries that require one. */
    public function license(string $number): self
    {
        $this->options['license'] = $number;

        return $this;
    }

    /** The most you agree to pay, when the change is a billable one (RFC 8748). */
    public function maxFee(string $amount, ?string $currency = null): self
    {
        $this->options['fee'] = self::feeAgreement($amount, $currency);

        return $this;
    }

    /** Add a DS record to an existing domain. */
    public function addDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self
    {
        return $this->secDnsDelta('add', 'dsData', self::makeDsRecord($keyTag, $alg, $digestType, $digest));
    }

    /** Remove one specific DS record. Everything must match what the registry holds. */
    public function remDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self
    {
        return $this->secDnsDelta('rem', 'dsData', self::makeDsRecord($keyTag, $alg, $digestType, $digest));
    }

    /** Add a public key to an existing domain. */
    public function addKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self
    {
        return $this->secDnsDelta('add', 'keyData', self::makeKeyRecord($flags, $protocol, $alg, $pubKey));
    }

    /** Remove one specific public key. */
    public function remKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self
    {
        return $this->secDnsDelta('rem', 'keyData', self::makeKeyRecord($flags, $protocol, $alg, $pubKey));
    }

    /**
     * Unsign the domain entirely.
     *
     * Mutually exclusive with removing specific records — the protocol has no way to express both,
     * and a frame carrying both is refused. Refused here instead, where the message can say so.
     */
    public function removeAllDnssec(): self
    {
        $sec = (array) ($this->options['secDNS'] ?? []);
        if (isset($sec['rem'])) {
            throw new ValidationException(
                'removeAllDnssec() cannot be combined with remDsRecord()/remKeyRecord() — '
                . 'remove everything, or name what to remove, not both',
            );
        }
        $sec['remAll'] = true;
        $this->options['secDNS'] = $sec;

        return $this;
    }

    /** Maximum signature lifetime in seconds. */
    public function maxSigLife(int $seconds): self
    {
        $sec = (array) ($this->options['secDNS'] ?? []);
        $sec['maxSigLife'] = $seconds;
        $this->options['secDNS'] = $sec;

        return $this;
    }

    public function send(): Response
    {
        $this->markSent();

        return $this->handler->update($this->name, $this->options);
    }

    /** @param list<string> $values */
    private function push(string $block, string $key, array $values): self
    {
        $spec = (array) ($this->options[$block] ?? []);
        $existing = (array) ($spec[$key] ?? []);
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $existing[] = $value;
            }
        }
        $spec[$key] = $existing;
        $this->options[$block] = $spec;

        return $this;
    }

    /** @param list<string> $handles */
    private function contactDelta(string $block, string $role, array $handles): self
    {
        $spec = (array) ($this->options[$block] ?? []);
        $contacts = (array) ($spec['contacts'] ?? []);
        self::addContacts($contacts, $role, $handles);
        $spec['contacts'] = $contacts;
        $this->options[$block] = $spec;

        return $this;
    }

    /** @param array<string, mixed> $record */
    private function secDnsDelta(string $block, string $kind, array $record): self
    {
        $sec = (array) ($this->options['secDNS'] ?? []);
        if ($block === 'rem' && !empty($sec['remAll'])) {
            throw new ValidationException(
                'remDsRecord()/remKeyRecord() cannot be combined with removeAllDnssec() — '
                . 'remove everything, or name what to remove, not both',
            );
        }
        $spec = (array) ($sec[$block] ?? []);
        $spec[$kind] = array_merge((array) ($spec[$kind] ?? []), [$record]);
        $sec[$block] = $spec;
        $this->options['secDNS'] = $sec;

        return $this;
    }
}
