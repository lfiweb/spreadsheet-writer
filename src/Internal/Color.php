<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

use SpreadsheetWriter\Exception\InvalidArgumentException;

/**
 * Validates and normalizes RGB colors.
 *
 * @internal
 */
final class Color
{
    /**
     * Validate a color in the format 'RRGGBB' and return it in upper case.
     *
     * @param string $color hexadecimal RGB color, e.g. '006268'
     *
     * @return string the color in upper case, e.g. 'AAAAAA'
     * @throws InvalidArgumentException if the color is not in the format 'RRGGBB'
     */
    public static function normalize(string $color): string
    {
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException("Invalid color '$color', expected the format 'RRGGBB'.");
        }

        return strtoupper($color);
    }
}
