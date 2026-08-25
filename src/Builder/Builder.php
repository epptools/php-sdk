<?php

declare(strict_types=1);

namespace EppTools\Builder;

use EppTools\Exception\ValidationException;
use EppTools\Response;

/**
 * Shared behaviour of the fluent command builders.
 *
 * A builder is a typed façade over the options array the command takes. It builds no XML of its
 * own: `send()` hands `toOptions()` straight to the ordinary method, so a builder and the
 * equivalent array produce the identical frame, and every check that applies to one applies to the
 * other.
 *
 * Why reach for it: an options array accepts any key, so a misspelling is refused only because the
 * library was taught the whole list. A builder has no key to misspell — `->yeras(1)` does not
 * exist, and your editor says so as you type it.
 *
 * @internal Extended by the concrete builders; not part of the public surface itself.
 */
abstract class Builder
{
    /** @var array<string, mixed> */
    protected array $options = [];

    private bool $sent = false;

    /**
     * The options as the equivalent array call would take them.
     *
     * Useful for a dry run, for logging what you are about to do, or for handing the command to a
     * queue and sending it elsewhere. Calling it does not send anything and does not spend the
     * builder.
     *
     * @return array<string, mixed>
     */
    public function toOptions(): array
    {
        // A COPY, and a deep one: the result is a value you can keep, log or queue. Handing back the
        // live array would make it change under the caller every time another step is added, so what
        // was logged and what was sent could differ.
        return unserialize(serialize($this->options));
    }

    /** Send the command and return the registry's answer. */
    abstract public function send(): Response;

    /**
     * Guards the terminal call. A builder is a command that has not happened yet, so sending it
     * twice would be two registrations, two charges — and the second one is never what the caller
     * meant. Build a new one instead; they are cheap.
     */
    protected function markSent(): void
    {
        if ($this->sent) {
            throw new ValidationException(
                static::class . ' has already been sent. A builder carries one command; '
                . 'build another rather than re-sending this one.',
            );
        }
        $this->sent = true;
    }

    /**
     * Appends handles to a role inside an add/rem/create contacts map.
     *
     * @param array<string, mixed> $target
     */
    protected static function addContacts(array &$target, string $role, array $handles): void
    {
        $role = trim($role);
        if ($role === '') {
            throw new ValidationException('a contact role must not be empty (admin, tech, billing, …)');
        }
        $existing = (array) ($target[$role] ?? []);
        foreach ($handles as $handle) {
            $handle = trim((string) $handle);
            if ($handle !== '') {
                $existing[] = $handle;
            }
        }
        $target[$role] = $existing;
    }

    /**
     * The RFC 8748 fee AGREEMENT: the most you consent to pay.
     *
     * Not a price you set — the registry charges its own. If the real price is HIGHER the command
     * is refused (2004) and nothing is charged, which is the point: a tariff change or a premium
     * name cannot bill you more than you agreed to.
     *
     * @return string|array{amount: string, currency: string}
     */
    protected static function feeAgreement(string $amount, ?string $currency): string|array
    {
        $amount = trim($amount);
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            // Checked here rather than on the wire: a malformed agreement draws a bare 2001 that
            // names no field, and it arrives after the command has been attempted.
            throw new ValidationException("a fee amount must be a plain decimal like '100.00' (got '{$amount}')");
        }

        return $currency === null ? $amount : ['amount' => $amount, 'currency' => $currency];
    }

    /**
     * One DNSSEC delegation-signer record.
     *
     * @return array{keyTag: int, alg: int, digestType: int, digest: string}
     */
    protected static function makeDsRecord(int $keyTag, int $alg, int $digestType, string $digest): array
    {
        $digest = trim($digest);
        if ($digest === '') {
            throw new ValidationException('a DS record needs a digest');
        }

        return ['keyTag' => $keyTag, 'alg' => $alg, 'digestType' => $digestType, 'digest' => $digest];
    }

    /**
     * One DNSSEC public key.
     *
     * @return array{flags: int, protocol: int, alg: int, pubKey: string}
     */
    protected static function makeKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): array
    {
        $pubKey = trim($pubKey);
        if ($pubKey === '') {
            throw new ValidationException('a key record needs a public key');
        }

        return ['flags' => $flags, 'protocol' => $protocol, 'alg' => $alg, 'pubKey' => $pubKey];
    }

    /**
     * One postal form, in the shape contact:create / contact:update take.
     *
     * @param string[] $street
     * @return array<string, mixed>
     */
    protected static function postalInfo(
        string $type,
        string $name,
        string $city,
        string $countryCode,
        array $street,
        ?string $org,
        ?string $stateProvince,
        ?string $postalCode,
    ): array {
        $block = [
            'type' => $type,
            'name' => $name,
            'city' => $city,
            'cc'   => $countryCode,
        ];
        if ($street !== []) {
            $block['street'] = array_values(array_map('strval', $street));
        }
        if ($org !== null) {
            $block['org'] = $org;
        }
        if ($stateProvince !== null) {
            $block['sp'] = $stateProvince;
        }
        if ($postalCode !== null) {
            $block['pc'] = $postalCode;
        }

        return $block;
    }

    /**
     * Turns publish()/withhold() arguments into the disclose option.
     *
     * @param string[] $fields
     * @return array<string, mixed>
     */
    protected static function disclosure(bool $publish, array $fields): array
    {
        $spec = ['flag' => $publish];
        foreach ($fields as $field) {
            $field = trim((string) $field);
            switch ($field) {
                // name/org/addr exist once per postal form, so the choice is per form. Both are
                // named: withholding only the ASCII form while the local one stays public is a
                // privacy setting that reads as applied and is not.
                case 'name':
                case 'org':
                case 'addr':
                    $spec[$field] = ['int', 'loc'];
                    break;
                case 'voice':
                case 'fax':
                case 'email':
                    $spec[$field] = true;
                    break;
                default:
                    throw new ValidationException(
                        "'{$field}' is not a disclosable field. Use: name, org, addr, voice, fax, email.",
                    );
            }
        }

        return $spec;
    }
}
