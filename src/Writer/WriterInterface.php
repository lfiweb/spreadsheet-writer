<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer;

use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\Workbook;

/**
 * Writes a workbook in a spreadsheet file format.
 *
 * Writers don't hold the workbook, so the same writer instance can write any number of workbooks.
 */
interface WriterInterface
{
    /**
     * Return the file as a binary string.
     *
     * @param Workbook $workbook the workbook to write
     *
     * @return string
     * @throws WriterException if the file can't be created
     */
    public function write(Workbook $workbook): string;

    /**
     * Write the file to the given path.
     *
     * @param Workbook $workbook the workbook to write
     * @param string $path path of the file, an existing file is overwritten
     *
     * @return void
     * @throws WriterException if the file can't be created or saved
     */
    public function save(Workbook $workbook, string $path): void;

    /**
     * Return the MIME type of the file format.
     *
     * @return string e.g. 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
     */
    public function getMimeType(): string;

    /**
     * Return the file extension of the file format, without the dot.
     *
     * @return string e.g. 'xlsx'
     */
    public function getFileExtension(): string;
}
