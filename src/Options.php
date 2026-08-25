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
