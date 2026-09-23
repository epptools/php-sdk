<?php

declare(strict_types=1);

namespace EppTools;

use EppTools\Exception\ValidationException;

/**
 * Rejects an option key this library does not understand.
 *
 * An options array is convenient and, without this, silent: a key that is misspelled, in the wrong
 * case, or left over from an older version is simply never read. The command still goes out, the
 * registry still answers 1000, and the part you asked for is missing — `'secdns'` for `'secDNS'`
 * registers the domain UNSIGNED, `'nameServers'` for `'nameservers'` registers it with no
 * delegation. Nothing in the response says so, because as far as the registry is concerned you
 * never asked.
 *
 * So an unrecognised key is refused before the frame is built, with the closest known key named.
 * The cost is that a key this library does not understand raises instead of being quietly ignored —
 * which is the point.
 *
 * Two guards here look at VALUES rather than keys — nonEmptyStrings() and oneOf(). They live in this
 * class because a command method and the equivalent builder must apply them identically, and a check
 * copied into three command classes is a check that will be fixed in two of them.
 *
 * @internal
 */
final class Options
{
    /**
     * Rewrite the plain-word spelling of an option key to the one this library builds frames from.
     *
     * WHY TWO SPELLINGS EXIST. `rem`, `chg` and `remAll` are EPP's own abbreviations, and EPP
     * abbreviates because it is XML on a wire budget. An options array has no wire budget, and a
     * reader who has not memorised RFC 5731 cannot tell `chg` from a typo or guess that `rem` is not
     * short for `remark`. So `remove`, `change` and `removeAll` are accepted everywhere the short
     * forms are, and are what the documentation shows.
     *
     * THE SHORT FORMS ARE NOT DEPRECATED AND WILL NOT BE REMOVED. check() refuses an unrecognised
     * key instead of ignoring it - which is the right behaviour and the reason this library catches
     * `secdns` for `secDNS` - but it also means dropping a spelling turns working code into an
     * exception. Both stay.
     *
     * THE PLAIN WORD WINS when a caller sends both, which is the only ordering that lets a codebase
     * migrate one call at a time rather than in a flag day. Sending both is not an error: they are
     * two names for one key, not two keys.
     *
     * @param array<string, mixed> $options
     * @param array<string, string> $map  plain word => the spelling frames are built from
     * @return array<string, mixed>
     */
    public static function canonicalise(array $options, array $map): array
    {
        foreach ($map as $plain => $short) {
            if (!array_key_exists($plain, $options)) {
                continue;
            }

            $options[$short] = $options[$plain];
            unset($options[$plain]);
        }

        return $options;
    }

    /**
     * Read an option that has more than one reasonable spelling, in the order given.
     *
     * Used where BOTH spellings are defensible rather than where one is a mistake: `nameservers`
     * is one word in DNS usage, and `nameServers` is what the rest of this option set's camelCase
     * leads you to type. Guessing either should work; guessing something this library does not
     * understand should not, which is what check() is for.
     *
     * @param array<string, mixed> $options
     */
    public static function pick(array $options, string ...$names): mixed
    {
        foreach ($names as $name) {
            if (isset($options[$name])) {
                return $options[$name];
            }
        }

        return null;
    }

    /**
     * A list of wire values with the blanks taken out: every entry trimmed, every empty one dropped,
     * the keys renumbered.
     *
     * WHY IT LIVES HERE. The builders each filtered their own lists and the command methods emitted
     * whatever they were handed, so the guard belonged to one of the two ways of calling the library
     * and not to the other. A blank is not a harmless no-op on the wire: `<domain:status s=""/>`,
     * `<contact:status s=""/>` and `<host:status s=""/>` are refused because statusValueType is an
     * enumeration the empty string is not a member of, an empty `<domain:hostObj/>` underruns
     * minLength 1 and an empty `<host:addr/>` underruns minLength 3. Each comes back as a bare 2001
     * that names no element, and the whole command is lost — the four statuses that were spelled
     * correctly with it.
     *
     * Filtering rather than refusing, because a blank in a list is what a form field nobody filled in
     * produces, and dropping it is what the caller meant. A misspelled KEY is the opposite case and is
     * refused: see check().
     *
     * @return list<string>
     */
    public static function nonEmptyStrings(mixed $values): array
    {
        $out = [];
        foreach ((array) $values as $value) {
            // A nested array or an object has no string form worth putting on the wire; casting one
            // would write "Array" into an element. Skipped rather than stringified.
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * The list of names or handles a `check` asks about, with a blank one refused rather than dropped.
     *
     * The opposite treatment to nonEmptyStrings(), for the opposite reason. A blank status in a list of
     * statuses to add is noise, and dropping it performs the change the caller meant. A blank here is
     * the QUESTION, and dropping it answers a different question than the one that was asked: the
     * caller passes fifty names, gets forty-nine answers, and their own loop over the fifty finds
     * nothing under the blank — null in PHP, undefined in JavaScript, a KeyError in Python. Only the
     * Python case is loud. In the other two, "no answer" reads as "not available", so a silent filter
     * turns a frame the schema would have refused into a WRONG ANSWER further away from the cause.
     *
     * Refused with the position named, because a list long enough for one entry to be blank by accident
     * is a list too long to scan by eye.
     *
     * @param string $what the element the value becomes, named as the schema names it
     * @return list<string>
     */
    public static function identifiers(mixed $values, string $context, string $what): array
    {
        $out = [];
        foreach (array_values((array) $values) as $index => $value) {
            if (is_array($value) || is_object($value)) {
                throw new ValidationException(sprintf(
                    '%s: entry %d is not a name — %s takes a list of strings',
                    $context,
                    $index + 1,
                    $context,
                ));
            }
            $value = trim((string) $value);
            if ($value === '') {
                $asked = count((array) $values);
                throw new ValidationException(sprintf(
                    '%s: entry %d of %d is blank. An empty <%s> is a frame the registry refuses, and '
                    . 'dropping it would return %d answers to a question about %d — the missing entry '
                    . 'then reads as unavailable rather than as never asked.',
                    $context,
                    $index + 1,
                    $asked,
                    $what,
                    $asked - 1,
                    $asked,
                ));
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * An argument the schema types as a closed set, checked against that set.
     *
     * These are the values a caller cannot guess and the registry cannot help with: a transfer `op`,
     * the `hosts` filter on a domain info, a contact role, the int/loc form of a postal block. Every
     * one is an XSD enumeration, so a value outside the set is not a policy refusal — it is a frame
     * the server never parses, answered with a bare 2001 naming no attribute. The set is small,
     * documented and known here, so the caller is told which value was wrong by their own code.
     *
     * @param string[] $allowed
     * @param string   $what the argument, named as the caller wrote it
     * @param string   $rule the RFC or XSD rule that closes the set
     */
    public static function oneOf(mixed $value, array $allowed, string $what, string $rule): string
    {
        $given = trim((string) $value);
        if (in_array($given, $allowed, true)) {
            return $given;
        }

        throw new ValidationException(sprintf(
            "'%s' is not a valid %s. Use: %s. (%s)",
            $given,
            $what,
            implode(', ', $allowed),
            $rule,
        ));
    }

    /**
     * @param array<string, mixed> $given
     * @param string[]             $known
     */
    public static function check(array $given, array $known, string $context): void
    {
        $unknown = [];
        foreach (array_keys($given) as $key) {
            $key = (string) $key;
            if (!in_array($key, $known, true)) {
                $unknown[] = $key;
            }
        }
        if ($unknown === []) {
            return;
        }

        $details = [];
        foreach ($unknown as $key) {
            $suggestion = self::closest($key, $known);
            $details[] = $suggestion === null ? "'{$key}'" : "'{$key}' (did you mean '{$suggestion}'?)";
        }
        sort($known);

        throw new ValidationException(sprintf(
            '%s does not accept %s. Accepted: %s.',
            $context,
            implode(', ', $details),
            implode(', ', $known),
        ));
    }

    /**
     * The known key a misspelling most likely meant, or null when nothing is close enough.
     *
     * Case and separators are ignored first, so 'secdns' finds 'secDNS' and 'auth_info' finds
     * 'authInfo' — the two mistakes that actually happen when a developer moves between languages.
     *
     * @param string[] $known
     */
    private static function closest(string $key, array $known): ?string
    {
        $normalise = static fn (string $s): string => strtolower(str_replace(['_', '-'], '', $s));
        $target = $normalise($key);
        foreach ($known as $candidate) {
            if ($normalise($candidate) === $target) {
                return $candidate;
            }
        }

        // Two letters swapped is the commonest typo of all and Levenshtein scores it 2, which the
        // distance threshold below rejects for a short key — 'yeras' would otherwise be reported
        // with no suggestion at all. Same letters, different order, is a transposition and nothing
        // else.
        $sorted = static function (string $s): string {
            $chars = str_split($s);
            sort($chars);

            return implode('', $chars);
        };
        $targetSorted = $sorted($target);
        foreach ($known as $candidate) {
            if ($sorted($normalise($candidate)) === $targetSorted) {
                return $candidate;
            }
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($known as $candidate) {
            $distance = levenshtein($target, $normalise($candidate));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Beyond a third of the key's length the "suggestion" is noise that sends the reader looking
        // in the wrong place, which is worse than offering none.
        return $bestDistance <= max(1, (int) floor(strlen($target) / 3)) ? $best : null;
    }
}
