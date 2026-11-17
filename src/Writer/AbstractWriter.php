<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer;

use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\Workbook;

/**
 * Base class of the writers: holds the timestamp and saves the written file.
 *
 * @internal use WriterInterface as the type of a writer
 */
abstract class AbstractWriter implements WriterInterface
{
    /**
     * Create the writer.
     *
     * @param int|null $timestamp modification time stored in the ZIP archive as a Unix timestamp,
     *     null for the current time. A fixed value makes the output reproducible, e.g. for tests.
     */
    public function __construct(protected readonly ?int $timestamp = null)
    {
    }

    /**
     * Write the file to the given path.
     *
     * @param Workbook $workbook the workbook to write
     * @param string $path path of the file, an existing file is overwritten
     *
     * @return void
     * @throws WriterException if the file can't be created or saved
     */
    public function save(Workbook $workbook, string $path): void
    {
        if (@file_put_contents($path, $this->write($workbook)) === false) {
            throw new WriterException("Failed to save the file '$path'.");
        }
    }
}
