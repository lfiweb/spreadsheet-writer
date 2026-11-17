<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

/**
 * Data type of a stored cell value.
 *
 * @internal
 */
enum CellType
{
    case Number;
    case Text;
    case RichText;
}
