<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Writer;

use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\Border;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;
use SpreadsheetWriter\Workbook;

/**
 * Builds the workbook that the writer tests write and read back. It uses every feature of the API.
 *
 * Rows 1 to 5:
 * - A1:D1 merged caption, rich text with a green run, bold, top aligned, row height 24
 * - A2:D4 values: explicit text, numeric strings with decimals, text, integers, floats, line breaks,
 *   formula-like text and an integer too large for Excel
 * - A2:D5 thin gray borders (row 5 is empty), B2:D4 right aligned, A4:D4 wrapped and top aligned
 * - column widths of A and C, gridlines hidden
 *
 * Rows 7 to 12 (row 6 is empty): the caption in a plain cell (B7), spaces (A8), tabs (A9), rich text with spaces (A10),
 * the merges B8:C9 and D8:D9 and a cell after gaps (E12).
 */
final class SampleWorkbook
{
    /**
     * Return a new sample workbook.
     *
     * @return Workbook
     */
    public static function create(): Workbook
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $caption = (new RichText())->addText('Einheit', new Font(color: '006268'))->addText(': 1000 m³ <&> "q"');
        $sheet->setString(1, 1, $caption);
        $sheet->mergeCells(1, 1, 4, 1);
        $sheet->setRowHeight(1, 24);
        $sheet->setStyle(1, 1, 1, 1, new Style(font: new Font(bold: true), vertical: VerticalAlignment::Top));
        $sheet->setString(1, 2, '1990');
        $sheet->setValue(2, 2, '12.30');
        $sheet->setValue(3, 2, '-5.0');
        $sheet->setValue(4, 2, '.');
        $sheet->setValue(1, 3, '007');
        $sheet->setValue(2, 3, '1e3');
        $sheet->setValue(3, 3, 42);
        $sheet->setValue(4, 3, 3.25);
        $sheet->setValue(1, 4, "Zeile1\nZeile2 _x0041_ \x01ctl");
        $sheet->setValue(2, 4, '=SUM(A1:A2)');
        $sheet->setValue(3, 4, 1234567890123456789);
        $sheet->setStyle(1, 2, 4, 5, new Style(border: new Border(color: 'aaaaaa')));
        $sheet->setStyle(2, 2, 4, 4, new Style(horizontal: HorizontalAlignment::Right));
        $sheet->setStyle(1, 4, 4, 4, new Style(textWrapped: true, vertical: VerticalAlignment::Top));
        $sheet->setColumnWidth(1, 20);
        $sheet->setColumnWidth(3, 12.5);
        $sheet->setShowGridlines(false);

        $sheet->setValue(1, 7, 'second');
        $sheet->setString(2, 7, $caption);
        $sheet->setString(1, 8, '  two  spaces  ');
        $sheet->setString(1, 9, "tab\there \t x");
        $sheet->setString(1, 10, (new RichText())->addText('a ', new Font(bold: true))->addText(' b ')->addText(' ', new Font(color: 'FF0000')));
        $sheet->mergeCells(2, 8, 3, 9);
        $sheet->mergeCells(4, 8, 4, 9);
        $sheet->setValue(5, 12, 1);

        return $workbook;
    }
}
