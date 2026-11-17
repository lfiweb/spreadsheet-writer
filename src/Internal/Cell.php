<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

use SpreadsheetWriter\RichText;

/**
 * A stored cell value.
 *
 * @internal
 */
final readonly class Cell
{
    /**
     * Create a cell value.
     *
     * @param CellType $type data type of the value
     * @param int|float|string|RichText $value the value, a number for CellType::Number,
     *     a string for CellType::Text and a RichText for CellType::RichText
     * @param int $decimals number of decimals to display, 0 for the General number format
     */
    public function __construct(
        public CellType $type,
        public int|float|string|RichText $value,
        public int $decimals = 0,
    ) {
    }
}
