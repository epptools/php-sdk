<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Command\Domain;
use EppTools\Exception\ValidationException;
use EppTools\Response;

/**
 * Registers a domain, one named step at a time.
 *
 *     $response = $client->domain()->createBuilder('your-brand.com.ua')
 *         ->years(1)
 *         ->registrant('acme-01')
 *         ->adminContact('acme-01')
 *         ->techContact('acme-ns')
 *         ->nameservers('ns1.acme.example', 'ns2.acme.example')
 *         ->authInfo('D0main-Pw')
 *         ->send();
 *
 * Nothing reaches the registry until send(). Until then the builder is an ordinary value: keep it,
 * inspect it with toOptions(), pass it around.
 */
final class DomainCreateBuilder extends Builder
{
    public function __construct(private Domain $handler, private string $name)
    {
    }

    /** Registration period in years. Omit it and the registry applies its own default. */
    public function years(int $years): self
    {
        $this->options['years'] = $years;

        return $this;
    }

    /** The registrant — the holder of the domain. Required by the registry. */
    public function registrant(string $handle): self
    {
        $this->options['registrant'] = $handle;

        return $this;
    }

    /**
     * Attach contacts in a role.
     *
     * Every list method on this builder ACCUMULATES: pass several at once, or call it again, or
     * both — the effect is the same. RFC 5731 puts no limit on handles per role, so a domain can
     * carry as many technical contacts as the registry's own policy allows.
     *
     *     ->contact('tech', 'acme-ns1', 'acme-ns2')
     *     ->contact('tech', 'acme-ns1')->contact('tech', 'acme-ns2')   // identical
     */
    public function contact(string $role, string ...$handles): self
    {
        $contacts = (array) ($this->options['contacts'] ?? []);
        self::addContacts($contacts, $role, $handles);
        $this->options['contacts'] = $contacts;

        return $this;
    }

    /** The people authorised to make decisions about the domain. Accumulates. */
    public function adminContact(string ...$handles): self
    {
        return $this->contact('admin', ...$handles);
    }

    /** The people to reach about DNS and delegation. Accumulates. */
    public function techContact(string ...$handles): self
    {
        return $this->contact('tech', ...$handles);
    }

    /** The people to reach about invoices for this domain. Accumulates. */
    public function billingContact(string ...$handles): self
    {
        return $this->contact('billing', ...$handles);
    }

    /**
     * A nameserver to delegate to. Accumulates, so this reads the same as nameservers() and suits
     * a loop or a conditional better.
     */
    public function nameserver(string $host): self
    {
        return $this->nameservers($host);
    }

    /** The nameservers to delegate to. Accumulates; see nameserver() for the one-at-a-time form. */
    public function nameservers(string ...$hosts): self
    {
        $existing = (array) ($this->options['nameservers'] ?? []);
        foreach ($hosts as $host) {
            $host = trim($host);
            if ($host !== '') {
                $existing[] = $host;
            }
        }
        $this->options['nameservers'] = $existing;

        return $this;
    }

    /**
     * A nameserver given with its glue addresses, inlined in the command (RFC 5731 hostAttr).
     *
     * Use this where the registry expects the addresses with the name rather than a reference to a
     * host object created beforehand; ask yours which model it takes. A command cannot mix the two,
     * so use either this or nameserver(), not both.
     */
    public function nameserverWithGlue(string $host, string ...$addresses): self
    {
        $name = trim($host);
        if ($name === '') {
            throw new ValidationException('a nameserver name must not be empty');
        }
        $existing = (array) ($this->options['nameservers'] ?? []);
        $existing[] = ['name' => $name, 'addresses' => array_values($addresses)];
        $this->options['nameservers'] = $existing;

        return $this;
    }

    /**
     * The transfer secret. Anyone holding it can move the domain to another registrar, so treat it
     * as a credential: never log it, and roll it after handing it to a customer.
     */
    public function authInfo(string $password): self
    {
        $this->options['authInfo'] = $password;

        return $this;
    }

    /** A trademark or licence number, for registries that require one to register a name. */
    public function license(string $number): self
    {
        $this->options['license'] = $number;

        return $this;
    }

    /**
     * The most you agree to pay for this registration (RFC 8748).
     *
     * Optional. Without it the registration proceeds and the registry's own price is charged; with
     * it, a higher real price refuses the command (2004) instead of billing you the difference.
     */
    public function maxFee(string $amount, ?string $currency = null): self
    {
        $this->options['fee'] = self::feeAgreement($amount, $currency);

        return $this;
    }

    /** Sign the domain with a DS record (RFC 5910). Call it again for a second key. */
    public function dsRecord(int $keyTag, int $alg, int $digestType, string $digest): self
    {
        $sec = (array) ($this->options['secDNS'] ?? []);
        $sec['dsData'] = array_merge((array) ($sec['dsData'] ?? []), [self::makeDsRecord($keyTag, $alg, $digestType, $digest)]);
        $this->options['secDNS'] = $sec;

        return $this;
    }

    /**
     * A DS record that carries the DNSKEY it was computed from (RFC 5910).
     *
     * A registry that accepts this can verify the digest against the key for you, catching a
     * mistyped digest before it reaches the zone. One that does not accept DNSKEY data refuses the
     * command rather than ignoring the extra element, so trying costs you nothing but a 2306.
     */
    public function dsRecordWithKey(
        int $keyTag,
        int $alg,
        int $digestType,
        string $digest,
        int $flags,
        int $protocol,
        int $keyAlg,
        string $pubKey,
    ): self {
        $sec = (array) ($this->options['secDNS'] ?? []);
        $record = self::makeDsRecord($keyTag, $alg, $digestType, $digest);
        $record['keyData'] = self::makeKeyRecord($flags, $protocol, $keyAlg, $pubKey);
        $sec['dsData'] = array_merge((array) ($sec['dsData'] ?? []), [$record]);
        $this->options['secDNS'] = $sec;

        return $this;
    }

    /** Sign the domain with a public key instead of a DS record, where the registry accepts one. */
    public function keyRecord(int $flags, int $protocol, int $alg, string $pubKey): self
    {
        $sec = (array) ($this->options['secDNS'] ?? []);
        $sec['keyData'] = array_merge((array) ($sec['keyData'] ?? []), [self::makeKeyRecord($flags, $protocol, $alg, $pubKey)]);
        $this->options['secDNS'] = $sec;

        return $this;
    }

    /** Maximum signature lifetime in seconds. Only meaningful alongside a DS or key record. */
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

        return $this->handler->create($this->name, $this->options);
    }
}
