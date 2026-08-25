<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Command\Contact;
use EppTools\Response;

/**
 * Creates a contact, one named step at a time.
 *
 *     $response = $client->contact()->createBuilder('acme-01', 'billing@acme.example')
 *         ->internationalAddress(name: 'ACME LLC', city: 'Kyiv', countryCode: 'UA',
 *                                street: ['1 Main St'], org: 'ACME LLC', postalCode: '01001')
 *         ->voice('+380.441234567')
 *         ->authInfo('C0ntact-Pw')
 *         ->withhold('voice', 'email')
 *         ->send();
 *
 * The id and the e-mail are constructor arguments because the registry requires both — a builder
 * that lets you forget a mandatory field has moved the error from your editor to the wire.
 */
final class ContactCreateBuilder extends Builder
{
    public function __construct(private Contact $handler, private string $id, string $email)
    {
        $this->options['email'] = $email;
    }

    /**
     * The address in ASCII, which every registry accepts.
     *
     * At least one form is required. Give this one unless you have a reason not to: it is the form
     * that survives being printed, e-mailed and read by a system that knows no Cyrillic.
     *
     * @param string[] $street up to three lines
     */
    public function internationalAddress(
        string $name,
        string $city,
        string $countryCode,
        array $street = [],
        ?string $org = null,
        ?string $stateProvince = null,
        ?string $postalCode = null,
    ): self {
        return $this->address('int', $name, $city, $countryCode, $street, $org, $stateProvince, $postalCode);
    }

    /**
     * The address in the local script — Cyrillic for a Ukrainian registrant.
     *
     * Optional, and additional: a contact may carry this form as well as the international one, and
     * registries differ on which they show publicly.
     *
     * @param string[] $street
     */
    public function localizedAddress(
        string $name,
        string $city,
        string $countryCode,
        array $street = [],
        ?string $org = null,
        ?string $stateProvince = null,
        ?string $postalCode = null,
    ): self {
        return $this->address('loc', $name, $city, $countryCode, $street, $org, $stateProvince, $postalCode);
    }

    /** Voice number in the EPP form: `+CC.NNNNNNNNN`, optionally `x` and an extension. */
    public function voice(string $number): self
    {
        $this->options['voice'] = $number;

        return $this;
    }

    /** Fax number, same form as voice(). */
    public function fax(string $number): self
    {
        $this->options['fax'] = $number;

        return $this;
    }

    /** The transfer secret for this contact. */
    public function authInfo(string $password): self
    {
        $this->options['authInfo'] = $password;

        return $this;
    }

    /**
     * Consent to publish these fields: name, org, addr, voice, fax, email.
     *
     * Anything you do not list takes the opposite treatment, so publish() and withhold() are two
     * ways of saying the same thing — pick the one that matches how you think about it, and do not
     * call both.
     */
    public function publish(string ...$fields): self
    {
        $this->options['disclose'] = self::disclosure(true, $fields);

        return $this;
    }

    /** Withhold these fields from publication. See publish() for the field names. */
    public function withhold(string ...$fields): self
    {
        $this->options['disclose'] = self::disclosure(false, $fields);

        return $this;
    }

    public function send(): Response
    {
        $this->markSent();

        return $this->handler->create($this->id, $this->options);
    }

    /** @param string[] $street */
    private function address(
        string $type,
        string $name,
        string $city,
        string $countryCode,
        array $street,
        ?string $org,
        ?string $stateProvince,
        ?string $postalCode,
    ): self {
        $blocks = (array) ($this->options['postalInfos'] ?? []);
        $blocks[] = self::postalInfo($type, $name, $city, $countryCode, $street, $org, $stateProvince, $postalCode);
        $this->options['postalInfos'] = $blocks;

        return $this;
    }
}
