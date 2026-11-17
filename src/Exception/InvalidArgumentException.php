<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Exception;

/**
 * Thrown when a method receives an invalid argument,
 * e.g. a coordinate outside the spreadsheet limits or a malformed color.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
