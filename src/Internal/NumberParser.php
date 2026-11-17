<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

/**
 * Detects numeric strings that are stored as numbers.
 *
 * The rules are the same as in PhpSpreadsheet's DefaultValueBinder, so files look the same
 * after switching. In addition, the number of decimals of the string is returned,
 * so the cell can show them.
 *
 * @internal
 */
final class NumberParser
{
    /**
     * pattern of a numeric string, e.g. '12', '-12.30', '.5', '1e3'
     *
     * As in PhpSpreadsheet, '$' also matches before a line break at the end, so "12\n" is the number 12.
     */
    private const string PATTERN = '/^[+-]?(\d+\.?\d*|\d*\.?\d+)([Ee][-+]?[0-2]?\d{1,3})?$/';

    /**
     * integers above this value lose precision in Excel and are stored as text
     */
    private const int MAX_INTEGER = 999999999999999;

    /**
     * maximum number of decimals in an Excel number format
     */
    private const int MAX_DECIMALS = 30;

    /**
     * Return the number and its decimals if the string is numeric, null otherwise.
     *
     * Strings with leading zeros (e.g. '007') are not numeric, so identifiers keep their zeros.
     *
     * @param string $value the string to parse
     *
     * @return array{0: int|float, 1: int}|null [number, number of decimals] or null if the string is not numeric
     */
    public static function parse(string $value): ?array
    {
        $result = null;
        if (
            preg_match(self::PATTERN, $value) === 1
            && !self::hasLeadingZero($value)
            && !self::isTooLargeInteger($value)
            && is_numeric($value)
        ) {
            $number = $value + 0;
            if (is_finite((float)$number)) {
                $result = [$number, self::countDecimals($value)];
            }
        }

        return $result;
    }

    /**
     * Return true if an integer is too large to be stored as a number without losing precision.
     *
     * @param int $value the integer to check
     *
     * @return bool
     */
    public static function isIntegerTooLarge(int $value): bool
    {
        return $value > self::MAX_INTEGER || $value < -self::MAX_INTEGER;
    }

    /**
     * Return true if the string has a leading zero that is not followed by a decimal point, e.g. '007'.
     *
     * @param string $value numeric string
     *
     * @return bool
     */
    private static function hasLeadingZero(string $value): bool
    {
        $unsigned = ltrim($value, '+-');

        return strlen($unsigned) > 1 && $unsigned[0] === '0' && $unsigned[1] !== '.';
    }

    /**
     * Return true if the string is an integer (no decimal point, no exponent) that is too large.
     *
     * @param string $value numeric string
     *
     * @return bool
     */
    private static function isTooLargeInteger(string $value): bool
    {
        return preg_match('/[eE.]/', $value) !== 1 && abs((float)$value) > self::MAX_INTEGER;
    }

    /**
     * Return the number of digits after the decimal point, 0 if the string has an exponent.
     *
     * A line break at the end of the string is not counted.
     *
     * @param string $value numeric string
     *
     * @return int
     */
    private static function countDecimals(string $value): int
    {
        $decimals = 0;
        $pos = strpos($value, '.');
        if ($pos !== false && preg_match('/[eE]/', $value) !== 1) {
            $decimals = min(strlen(rtrim($value, "\n")) - $pos - 1, self::MAX_DECIMALS);
        }

        return $decimals;
    }
}
