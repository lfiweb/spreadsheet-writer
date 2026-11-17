<?php

declare(strict_types=1);

namespace SpreadsheetWriter;

/**
 * A workbook with one sheet.
 *
 * Fill the sheet returned by getActiveSheet() and write the workbook with Writer\Xlsx or Writer\Ods.
 */
final class Workbook
{
    /**
     * the sheet of the workbook
     * @var Sheet
     */
    private Sheet $sheet;

    /**
     * Create a workbook with an empty sheet.
     */
    public function __construct()
    {
        $this->sheet = new Sheet();
    }

    /**
     * Return the sheet of the workbook.
     *
     * @return Sheet
     */
    public function getActiveSheet(): Sheet
    {
        return $this->sheet;
    }
}
