<?php

declare(strict_types=1);

namespace App\Ebay\Import;

/**
 * Text helpers for storing eBay text in the database.
 */
final class Text
{
    /**
     * Make text storable in the database's utf8mb3 columns, which hold only
     * characters up to U+FFFF: characters above that (emoji such as 👍 and
     * other 4-byte UTF-8) are removed, along with a space before them and any
     * joiners or variation selectors that belonged to them. Invalid UTF-8
     * bytes become "?". The result is trimmed.
     */
    public static function forDatabase(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return trim(preg_replace('/ ?(?:[\x{10000}-\x{10FFFF}][\x{200D}\x{FE0E}\x{FE0F}]*)+/u', '', $value) ?? '');
    }
}
