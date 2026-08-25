<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Command\Contact;
use EppTools\Response;

/**
 * Changes a contact, one named step at a time.
 *
 *     $response = $client->contact()->updateBuilder('acme-01')
 *         ->changeEmail('new@acme.example')
 *         ->addStatus('clientUpdateProhibited')
 *         ->send();
 *
 * What you do not mention is left alone. That holds inside an address too: a field you leave out
 * keeps its value, and a field passed as an empty string is cleared. The one thing to know is that
 * the address block is a sequence with a required city and country, so touching any part of it
 * sends the whole block — give city and countryCode whenever you change street, sp or pc.
 */
final class ContactUpdateBuilder extends Builder
{
    public function __construct(private Contact $handler, private string $id)
    {
    }

    /**
     * Change the ASCII address. Only the fields you pass are sent.
     *
     * PRESENCE is what decides, and the three states are distinct:
     *
     *   omit the argument (or pass null)  the field is not sent, and the registry keeps its value
     *   pass a value                      the field is set to it
     *   pass an empty string              the field is CLEARED — this is how org, stateProvince
     *                                     and postalCode are removed, and the only way to do it
     *
     * The other form (local or international, whichever you are not changing) is untouched. The
     * address block is a sequence with a required city and country, so passing any part of it
     * sends the whole block: give city and countryCode whenever you touch street, sp or pc.
     *
     * @param string[]|null $street
     */
    public function changeInternationalAddress(
        ?string $name = null,
        ?string $city = null,
        ?string $countryCode = null,
        ?array $street = null,
        ?string $org = null,
        ?string $stateProvince = null,
        ?string $postalCode = null,
    ): self {
        return $this->address('int', $name, $city, $countryCode, $street, $org, $stateProvince, $postalCode);
    }

    /** Change the local-script address. Same rules as changeInternationalAddress(). */
    public function changeLocalizedAddress(
        ?string $name = null,
        ?string $city = null,
        ?string $countryCode = null,
        ?array $street = null,
        ?string $org = null,
        ?string $stateProvince = null,
        ?string $postalCode = null,
    ): self {
        return $this->address('loc', $name, $city, $countryCode, $street, $org, $stateProvince, $postalCode);
    }

    public function changeVoice(string $number): self
    {
        return $this->chg('voice', $number);
    }

    public function changeFax(string $number): self
    {
        return $this->chg('fax', $number);
    }

    public function changeEmail(string $email): self
    {
        return $this->chg('email', $email);
    }

    /** Replace the transfer secret. */
    public function changeAuthInfo(string $password): self
    {
        return $this->chg('authInfo', $password);
    }

    // There is deliberately no clearAuthInfo() here. RFC 5731 gives a domain a nullable form,
    // <domain:authInfo><domain:null/>, and RFC 5733 defines no equivalent for a contact — so a
    // contact's transfer secret can be REPLACED but not removed. Do not reach for an empty password
    // as a substitute: an empty value is still a value the holder can present. Set a fresh secret
    // with changeAuthInfo() instead.
    /** Consent to publish these fields: name, org, addr, voice, fax, email. */
    public function publish(string ...$fields): self
    {
        return $this->chg('disclose', self::disclosure(true, $fields));
    }

    /** Withhold these fields from publication. */
    public function withhold(string ...$fields): self
    {
        return $this->chg('disclose', self::disclosure(false, $fields));
    }

    /** Set a client-side status, e.g. `clientUpdateProhibited`. */
    public function addStatus(string ...$statuses): self
    {
        $this->options['addStatuses'] = array_merge((array) ($this->options['addStatuses'] ?? []), array_values($statuses));

        return $this;
    }

    /** Clear a client-side status. */
    public function remStatus(string ...$statuses): self
    {
        $this->options['remStatuses'] = array_merge((array) ($this->options['remStatuses'] ?? []), array_values($statuses));

        return $this;
    }

    public function send(): Response
    {
        $this->markSent();

        return $this->handler->update($this->id, $this->options);
    }

    private function chg(string $key, mixed $value): self
    {
        $chg = (array) ($this->options['chg'] ?? []);
        $chg[$key] = $value;
        $this->options['chg'] = $chg;

        return $this;
    }

    /** @param string[]|null $street */
    private function address(
        string $type,
        ?string $name,
        ?string $city,
        ?string $countryCode,
        ?array $street,
        ?string $org,
        ?string $stateProvince,
        ?string $postalCode,
    ): self {
        // Only the arguments actually given become keys. The emitter reads presence, so a key that
        // is absent is not sent and a key holding '' is sent empty, which is what clears a field.
        $block = ['type' => $type];
        foreach (['name' => $name, 'org' => $org, 'city' => $city, 'sp' => $stateProvince,
            'pc' => $postalCode, 'cc' => $countryCode] as $key => $value) {
            if ($value !== null) {
                $block[$key] = $value;
            }
        }
        if ($street !== null) {
            $block['street'] = array_values(array_map('strval', $street));
        }

        $chg = (array) ($this->options['chg'] ?? []);
        $blocks = (array) ($chg['postalInfos'] ?? []);
        $blocks[] = $block;
        $chg['postalInfos'] = $blocks;
        $this->options['chg'] = $chg;

        return $this;
    }
}
