<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

use SpreadsheetWriter\Exception\InvalidArgumentException;

/**
 * Validates 1-based cell coordinates and ranges and converts them to references like 'B3'.
 *
 * @internal
 */
final class Coordinate
{
    /**
     * maximum number of columns of a sheet (Excel limit, column XFD)
     */
    public const int MAX_COLUMNS = 16384;

    /**
     * maximum number of rows of a sheet (Excel limit)
     */
    public const int MAX_ROWS = 1048576;

    /**
     * column letters already converted, indexed by column number
     * @var array<int, string>
     */
    private static array $letterCache = [];

    /**
     * Throw an exception if the cell coordinate is outside the sheet limits.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public static function validate(int $col, int $row): void
    {
        if ($col < 1 || $col > self::MAX_COLUMNS) {
            throw new InvalidArgumentException("Column $col is outside the range 1 to ".self::MAX_COLUMNS.'.');
        }
        if ($row < 1 || $row > self::MAX_ROWS) {
            throw new InvalidArgumentException("Row $row is outside the range 1 to ".self::MAX_ROWS.'.');
        }
    }

    /**
     * Validate a range and return it with its start before its end.
     *
     * The two cells can be any two opposite corners of the range. As in PhpSpreadsheet,
     * a reversed range like (3, 5, 1, 2) is corrected to (1, 2, 3, 5).
     *
     * @param int $fromCol 1-based column index of the first corner
     * @param int $fromRow 1-based row index of the first corner
     * @param int $toCol 1-based column index of the opposite corner
     * @param int $toRow 1-based row index of the opposite corner
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [first column, first row, last column, last row]
     * @throws InvalidArgumentException if a corner is outside the sheet limits
     */
    public static function normalizeRange(int $fromCol, int $fromRow, int $toCol, int $toRow): array
    {
        self::validate($fromCol, $fromRow);
        self::validate($toCol, $toRow);

        return [min($fromCol, $toCol), min($fromRow, $toRow), max($fromCol, $toCol), max($fromRow, $toRow)];
    }

    /**
     * Return the column letters of a 1-based column index, e.g. 1 → 'A', 28 → 'AB'.
     *
     * @param int $col 1-based column index
     *
     * @return string
     */
    public static function getColumnLetters(int $col): string
    {
        if (!isset(self::$letterCache[$col])) {
            $letters = '';
            $remaining = $col;
            while ($remaining > 0) {
                $mod = ($remaining - 1) % 26;
                $letters = chr(65 + $mod).$letters;
                $remaining = intdiv($remaining - 1, 26);
            }
            self::$letterCache[$col] = $letters;
        }

        return self::$letterCache[$col];
    }

    /**
     * Return the reference of a cell, e.g. (2, 3) → 'B3'.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     *
     * @return string
     */
    public static function getReference(int $col, int $row): string
    {
        return self::getColumnLetters($col).$row;
    }
}
