<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

/**
 * Helpers for writing XML.
 *
 * @internal
 */
final class Xml
{
    /**
     * characters that are not allowed in XML 1.0
     */
    private const string INVALID_CHARS = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /**
     * the XML declaration used by all parts
     */
    public const string DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

    /**
     * Escape a string for use in XML text or attribute values.
     *
     * Invalid UTF-8 sequences are replaced with '?', as PhpSpreadsheet does, and characters that are
     * not allowed in XML 1.0 (e.g. control characters) are removed.
     *
     * @param string $text the text
     *
     * @return string
     */
    public static function escape(string $text): string
    {
        $escaped = htmlspecialchars(mb_scrub($text, 'UTF-8'), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace(self::INVALID_CHARS, '', $escaped) ?? $escaped;
    }

    /**
     * Format a number for an XML value, without losing precision.
     *
     * A float is written with the fewest digits (15 to 17) that give back the same float, and always
     * with a decimal point, as PhpSpreadsheet writes it (1000.0 → '1000.0').
     * The result doesn't depend on the locale or on the serialize_precision setting of PHP.
     *
     * @param int|float $number the number, must be finite
     *
     * @return string e.g. '12', '12.3', '1000.0', '1.0E+25'
     */
    public static function formatNumber(int|float $number): string
    {
        $formatted = (string)$number;
        if (is_float($number)) {
            $precision = 14;
            do {
                // H is like G, but doesn't depend on the locale
                $formatted = sprintf('%.'.(++$precision).'H', $number);
            } while ($precision < 17 && (float)$formatted !== $number);
            if (strpbrk($formatted, '.E') === false) {
                $formatted .= '.0';
            }
        }

        return $formatted;
    }
}
