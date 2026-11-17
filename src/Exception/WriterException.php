<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Exception;

use RuntimeException;

/**
 * Thrown when a spreadsheet file cannot be created or saved.
 */
class WriterException extends RuntimeException implements ExceptionInterface
{
}
