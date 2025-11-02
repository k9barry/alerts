<?php
namespace App\Service;

/**
 * ZoneAlertHelper
 *
 * Helpers to parse and normalize the users.ZoneAlert values into a canonical
 * array of string identifiers used for matching (lowercased STATE_ZONE codes
 * and FIPS as numeric strings).
 */
final class ZoneAlertHelper
{
    /**
     * Parse a raw ZoneAlert value (JSON string or PHP array) and return a
     * deduplicated array of canonical identifiers (strings).
     *
     * Examples: ['IN040','18095','IN047','18097'] -> ['in040','18095','in047','18097']
     *
     * @param string|array|null $raw
     * @return string[]
     */
    public static function parse(mixed $raw): array
    {
        $vals = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $i => $item) {
            if (is_array($item)) {
                // object shape: try FIPS and UGC/STATE_ZONE
                $fips = $item['FIPS'] ?? $item['fips'] ?? $item['Fips'] ?? null;
                $ugc = $item['STATE_ZONE'] ?? $item['STATEZONE'] ?? $item['STATE'] ?? $item['ZONE'] ?? $item['UGC'] ?? $item['zone'] ?? null;
                if ($ugc !== null && $ugc !== '') {
                    $vals[] = self::canonizeStateZone($ugc);
                }
                if ($fips !== null && $fips !== '') {
                    $vals[] = (string)$fips;
                }
                continue;
            }

            // primitives
            if (is_int($item) || (is_string($item) && preg_match('/^[0-9]+$/', $item))) {
                $vals[] = (string)$item;
                continue;
            }

            if (is_string($item)) {
                $s = trim($item);
                if ($s === '') continue;
                // alternating shape: STATE_ZONE then FIPS
                if (preg_match('/^[A-Za-z]/', $s)) {
                    $vals[] = self::canonizeStateZone($s);
                    // lookahead for numeric fips
                    $next = $raw[$i+1] ?? null;
                    if ($next !== null && (is_int($next) || (is_string($next) && preg_match('/^[0-9]+$/', $next)))) {
                        $vals[] = (string)$next;
                    }
                } else {
                    // numeric string
                    $vals[] = $s;
                }
            }
        }

        // dedupe and normalize
        $out = [];
        foreach ($vals as $v) {
            $vv = is_string($v) ? trim($v) : (string)$v;
            if ($vv === '') continue;
            // normalize letters to lowercase
            if (preg_match('/[A-Za-z]/', $vv)) $vv = strtolower($vv);
            if (!in_array($vv, $out, true)) $out[] = $vv;
        }

        return $out;
    }

    /**
     * Normalize an input ZoneAlert (array-like) to a JSON string safe for storage.
     * Ensures values are strings and canonicalized.
     *
     * @param mixed $input
     * @return string JSON array
     */
    public static function normalizeForSave(mixed $input): string
    {
        // If the caller provided a raw JSON string, parse then re-normalize
        if (is_string($input) && trim($input) !== '') {
            $decoded = @json_decode($input, true);
            if (is_array($decoded)) $input = $decoded;
        }

        if (!is_array($input)) return json_encode([]);

        $parsed = self::parse($input);
        // store as array of strings (keep order from parse)
        return json_encode(array_values($parsed));
    }

    private static function canonizeStateZone(string $v): string
    {
        return strtolower(trim((string)$v));
    }
}
