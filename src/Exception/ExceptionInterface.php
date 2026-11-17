<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Exception;

use Throwable;

/**
 * Marker interface implemented by all exceptions thrown by this package.
 *
 * Catch this interface to handle any error of the SpreadsheetWriter.
 */
interface ExceptionInterface extends Throwable
{
}
