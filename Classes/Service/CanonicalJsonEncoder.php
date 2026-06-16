<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Deterministic JSON encoder for the audit-snapshot hash.
 *
 * Two callers (one editor save, one CLI re-snapshot) producing the
 * same content must produce the same sha256 — otherwise our dedup
 * insert leaks duplicate rows for no semantic change. Achieving that
 * over PHP arrays needs three guarantees:
 *
 *   1. **Stable key order.** PHP arrays preserve insertion order;
 *      different code paths can hand us the same logical content with
 *      different key orderings. We `ksort()` every map level
 *      recursively. Lists (numeric-indexed arrays) keep their order —
 *      list order IS semantically meaningful for service rows,
 *      tracker rows, etc.
 *   2. **Stable whitespace + escaping.** `JSON_UNESCAPED_SLASHES` so
 *      a URL doesn't pick up backslashes that change between PHP
 *      builds; `JSON_UNESCAPED_UNICODE` so an Umlaut is `ä` not
 *      `ä` (also smaller, more readable in BE diff).
 *   3. **Drop volatile fields.** `tstamp`, `crdate`, `uid` change on
 *      every DB write; including them would mean every save creates
 *      a new hash even when the editor changed nothing. The
 *      resolver shouldn't pass them to us, but we filter as a safety
 *      net so a stray field doesn't pollute the hash.
 *
 * Output is suitable for `hash('sha256', $encoder->encode($data))`.
 */
final readonly class CanonicalJsonEncoder
{
    /**
     * Field names that get stripped from every map at every depth
     * because their value changes on every write but the semantic
     * content doesn't.
     *
     * @var list<string>
     */
    private const array VOLATILE_FIELDS = ['uid', 'tstamp', 'crdate', 'library_adopted_at'];

    /**
     * @param array<mixed> $data
     */
    public function encode(array $data): string
    {
        $normalised = $this->normalise($data);
        return json_encode(
            $normalised,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Recursively normalise: sort map keys, preserve list order,
     * drop volatile fields from maps.
     *
     * Distinguishing a map from a list: a list is an array whose
     * keys are exactly `[0, 1, 2, …, count-1]`. Anything else (mixed
     * keys, string keys, gaps) is treated as a map and sorted.
     */
    private function normalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($this->isList($value)) {
            return array_map(fn ($item) => $this->normalise($item), $value);
        }
        $sorted = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            if (in_array($key, self::VOLATILE_FIELDS, true)) {
                continue;
            }
            $sorted[$key] = $this->normalise($value[$key]);
        }
        return $sorted;
    }

    /**
     * `array_is_list()` from PHP 8.1 — kept inline so the test
     * harness doesn't need a polyfill.
     *
     * @param array<mixed> $array
     */
    private function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }
        $i = 0;
        foreach ($array as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }
}
