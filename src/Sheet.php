<?php

declare(strict_types=1);

namespace SpreadsheetWriter;

use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Internal\Cell;
use SpreadsheetWriter\Internal\CellType;
use SpreadsheetWriter\Internal\Coordinate;
use SpreadsheetWriter\Internal\NumberParser;
use SpreadsheetWriter\Style\Style;

/**
 * The sheet of a workbook: cell values, merged cells, styles, row heights, column widths and gridlines.
 *
 * All coordinates are 1-based: column 1 is 'A', row 1 is the first row.
 * A workbook has exactly one sheet, get it with Workbook::getActiveSheet().
 */
final class Sheet
{
    /**
     * the title shown on the sheet tab, the same for every file and the same as PhpSpreadsheet's default title
     */
    public const string TITLE = 'Worksheet';

    /**
     * maximum number of characters in a cell (Excel limit), longer texts are cut off
     */
    public const int MAX_TEXT_LENGTH = 32767;

    /**
     * maximum row height in points (Excel limit)
     */
    public const float MAX_ROW_HEIGHT = 409.0;

    /**
     * maximum column width in characters (Excel limit)
     */
    public const float MAX_COLUMN_WIDTH = 255.0;

    /**
     * cell values indexed by row and column
     * @var array<int, array<int, Cell>>
     */
    private array $cells = [];

    /**
     * merged ranges in the order they were added
     * @var list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private array $mergedRanges = [];

    /**
     * cells covered by a merged range, indexed by row and column, to detect overlapping merges
     * @var array<int, array<int, true>>
     */
    private array $mergedCells = [];

    /**
     * styled ranges in the order they were added
     * @var list<array{0: array{0: int, 1: int, 2: int, 3: int}, 1: Style}>
     */
    private array $styledRanges = [];

    /**
     * row heights in points indexed by row
     * @var array<int, float>
     */
    private array $rowHeights = [];

    /**
     * column widths in characters indexed by column
     * @var array<int, float>
     */
    private array $columnWidths = [];

    /**
     * true if the gridlines are shown
     * @var bool
     */
    private bool $gridlinesShown = true;

    /**
     * Set a cell value.
     *
     * Numeric strings ('12.30', '-5', '1e3') are stored as numbers, other strings as text.
     * A numeric string with decimals also sets a number format that keeps them ('12.30' → 0.00).
     * The detection rules are the same as in PhpSpreadsheet's DefaultValueBinder, but strings
     * starting with '=' are always text (no formulas). Integers too large for Excel's precision
     * are stored as text. A text longer than 32,767 characters is cut off. Null clears the cell.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     * @param string|int|float|null $value the value
     *
     * @return void
     * @throws InvalidArgumentException if the coordinate is invalid or the float is not finite
     */
    public function setValue(int $col, int $row, string|int|float|null $value): void
    {
        Coordinate::validate($col, $row);
        if ($value === null) {
            unset($this->cells[$row][$col]);
            if (isset($this->cells[$row]) && $this->cells[$row] === []) {
                // no empty rows are kept, the writers expect every stored row to have a cell
                unset($this->cells[$row]);
            }
        } elseif (is_string($value)) {
            $this->storeString($col, $row, $value);
        } elseif (is_int($value) && NumberParser::isIntegerTooLarge($value)) {
            $this->cells[$row][$col] = new Cell(CellType::Text, (string)$value);
        } else {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException("The value of cell ($col, $row) is not a finite number.");
            }
            $this->cells[$row][$col] = new Cell(CellType::Number, $value);
        }
    }

    /**
     * Set a cell value that is always stored as text, also when the string is numeric.
     *
     * A string longer than 32,767 characters is cut off, as PhpSpreadsheet does. A rich text that long throws.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     * @param string|RichText $text the text, a RichText is copied
     *
     * @return void
     * @throws InvalidArgumentException if the coordinate is invalid or a rich text is too long
     */
    public function setString(int $col, int $row, string|RichText $text): void
    {
        Coordinate::validate($col, $row);
        if ($text instanceof RichText) {
            $this->validateTextLength($col, $row, $text->getPlainText());
            $this->cells[$row][$col] = new Cell(CellType::RichText, clone $text);
        } else {
            $this->cells[$row][$col] = new Cell(CellType::Text, $this->truncateText($text));
        }
    }

    /**
     * Merge a range of cells.
     *
     * The value of the top left cell is shown across the whole range.
     * Merging a single cell or a range that is already merged has no effect.
     * A range given from its end to its start is corrected, as in PhpSpreadsheet.
     *
     * @param int $fromCol 1-based index of the first column
     * @param int $fromRow 1-based index of the first row
     * @param int $toCol 1-based index of the last column
     * @param int $toRow 1-based index of the last row
     *
     * @return void
     * @throws InvalidArgumentException if the range is outside the sheet or overlaps a different merged range
     */
    public function mergeCells(int $fromCol, int $fromRow, int $toCol, int $toRow): void
    {
        [$fromCol, $fromRow, $toCol, $toRow] = Coordinate::normalizeRange($fromCol, $fromRow, $toCol, $toRow);
        $merged = in_array([$fromCol, $fromRow, $toCol, $toRow], $this->mergedRanges, true);
        if (!$merged && ($fromCol !== $toCol || $fromRow !== $toRow)) {
            for ($row = $fromRow; $row <= $toRow; $row++) {
                for ($col = $fromCol; $col <= $toCol; $col++) {
                    if (isset($this->mergedCells[$row][$col])) {
                        throw new InvalidArgumentException(
                            "The range ($fromCol, $fromRow, $toCol, $toRow) overlaps an already merged range at cell ($col, $row)."
                        );
                    }
                }
            }
            for ($row = $fromRow; $row <= $toRow; $row++) {
                for ($col = $fromCol; $col <= $toCol; $col++) {
                    $this->mergedCells[$row][$col] = true;
                }
            }
            $this->mergedRanges[] = [$fromCol, $fromRow, $toCol, $toRow];
        }
    }

    /**
     * Apply a style to a range of cells.
     *
     * Styles are layered: only the properties of the style that are not null override
     * the properties set by earlier calls, like getStyle($range)->getAlignment()->setHorizontal()
     * in PhpSpreadsheet. Styling empty cells is allowed, e.g. to draw borders.
     * A range given from its end to its start is corrected, as in PhpSpreadsheet.
     *
     * @param int $fromCol 1-based index of the first column
     * @param int $fromRow 1-based index of the first row
     * @param int $toCol 1-based index of the last column
     * @param int $toRow 1-based index of the last row
     * @param Style $style the style to apply
     *
     * @return void
     * @throws InvalidArgumentException if the range is outside the sheet
     */
    public function setStyle(int $fromCol, int $fromRow, int $toCol, int $toRow, Style $style): void
    {
        $this->styledRanges[] = [Coordinate::normalizeRange($fromCol, $fromRow, $toCol, $toRow), $style];
    }

    /**
     * Set the height of a row.
     *
     * @param int $row 1-based row index
     * @param float $points height in points, from 0 to 409
     *
     * @return void
     * @throws InvalidArgumentException if the row or the height is invalid
     */
    public function setRowHeight(int $row, float $points): void
    {
        Coordinate::validate(1, $row);
        if ($points < 0 || $points > self::MAX_ROW_HEIGHT) {
            throw new InvalidArgumentException("Row height $points is outside the range 0 to ".self::MAX_ROW_HEIGHT.'.');
        }
        $this->rowHeights[$row] = $points;
    }

    /**
     * Set the width of a column in characters (Excel's unit).
     *
     * @param int $col 1-based column index
     * @param float $width width in characters, from 0 to 255
     *
     * @return void
     * @throws InvalidArgumentException if the column or the width is invalid
     */
    public function setColumnWidth(int $col, float $width): void
    {
        Coordinate::validate($col, 1);
        if ($width < 0 || $width > self::MAX_COLUMN_WIDTH) {
            throw new InvalidArgumentException("Column width $width is outside the range 0 to ".self::MAX_COLUMN_WIDTH.'.');
        }
        $this->columnWidths[$col] = $width;
    }

    /**
     * Return the width set with setColumnWidth(), or null if the column uses the default width.
     *
     * @param int $col 1-based column index
     *
     * @return float|null width in characters
     */
    public function getColumnWidth(int $col): ?float
    {
        return $this->columnWidths[$col] ?? null;
    }

    /**
     * Show or hide the gridlines.
     *
     * @param bool $show true to show the gridlines (default), false to hide them
     *
     * @return void
     */
    public function setShowGridlines(bool $show): void
    {
        $this->gridlinesShown = $show;
    }

    /**
     * Return true if the gridlines are shown.
     *
     * @return bool
     */
    public function areGridlinesShown(): bool
    {
        return $this->gridlinesShown;
    }

    /**
     * Return the cell values indexed by row and column, in the order they were set.
     *
     * @internal used by the writers
     * @return array<int, array<int, Cell>>
     */
    public function getCells(): array
    {
        return $this->cells;
    }

    /**
     * Return the merged ranges as [fromCol, fromRow, toCol, toRow].
     *
     * @internal used by the writers
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function getMergedRanges(): array
    {
        return $this->mergedRanges;
    }

    /**
     * Return the styled ranges as [[fromCol, fromRow, toCol, toRow], Style] in the order they were added.
     *
     * @internal used by the writers
     * @return list<array{0: array{0: int, 1: int, 2: int, 3: int}, 1: Style}>
     */
    public function getStyledRanges(): array
    {
        return $this->styledRanges;
    }

    /**
     * Return the row heights in points indexed by row.
     *
     * @internal used by the writers
     * @return array<int, float>
     */
    public function getRowHeights(): array
    {
        return $this->rowHeights;
    }

    /**
     * Return the column widths in characters indexed by column.
     *
     * @internal used by the writers
     * @return array<int, float>
     */
    public function getColumnWidths(): array
    {
        return $this->columnWidths;
    }

    /**
     * Store a string as a number if it is numeric, as text otherwise.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     * @param string $value the string
     *
     * @return void
     * @throws InvalidArgumentException if the text is too long
     */
    private function storeString(int $col, int $row, string $value): void
    {
        $parsed = NumberParser::parse($value);
        if ($parsed === null) {
            $this->cells[$row][$col] = new Cell(CellType::Text, $this->truncateText($value));
        } else {
            $this->cells[$row][$col] = new Cell(CellType::Number, $parsed[0], $parsed[1]);
        }
    }

    /**
     * Return the text cut off after the maximum number of characters of a cell, as PhpSpreadsheet does.
     *
     * @param string $text the text
     *
     * @return string
     */
    private function truncateText(string $text): string
    {
        // strlen() is cheap and the byte length is never smaller than the character length
        return strlen($text) > self::MAX_TEXT_LENGTH ? mb_substr($text, 0, self::MAX_TEXT_LENGTH, 'UTF-8') : $text;
    }

    /**
     * Throw an exception if the rich text is longer than a cell can hold.
     *
     * Rich text is not cut off, because that would need cutting its runs. PhpSpreadsheet writes it as it is,
     * which gives a file that Excel can't open.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     * @param string $text the text
     *
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateTextLength(int $col, int $row, string $text): void
    {
        // strlen() is cheap and the byte length is never smaller than the character length
        if (strlen($text) > self::MAX_TEXT_LENGTH && mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(
                "The rich text of cell ($col, $row) is longer than ".self::MAX_TEXT_LENGTH.' characters.'
            );
        }
    }
}
